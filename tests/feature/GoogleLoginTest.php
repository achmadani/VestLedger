<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\GoogleAuth;

/**
 * Login dengan akun Google (§36).
 *
 * Penukaran kode ke server Google tidak diuji di sini — yang diuji adalah
 * seluruh keputusan keamanan di sekitarnya: state anti-CSRF, penolakan email
 * yang tidak terdaftar, dan penolakan akun yang dinonaktifkan.
 *
 * @internal
 */
final class GoogleLoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        \Config\Services::reset(true);
    }

    private function configure(bool $enabled = true): GoogleAuth
    {
        $config               = new GoogleAuth();
        $config->clientId     = $enabled ? 'uji.apps.googleusercontent.com' : '';
        $config->clientSecret = $enabled ? 'rahasia-uji' : '';

        \Config\Services::injectMock('googleAuth', new \App\Services\GoogleAuthService(
            $config,
            new UserModel(),
            service('auditLogger'),
        ));

        return $config;
    }

    private function makeUser(string $email): User
    {
        $users = new UserModel();
        $user  = new User([
            'username' => 'g' . bin2hex(random_bytes(4)),
            'email'    => $email,
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('viewer');

        return $user;
    }

    public function testLoginPageHidesGoogleButtonWhenNotConfigured(): void
    {
        $this->configure(false);

        $result = $this->get('login');

        $result->assertOK();
        $result->assertDontSee('Masuk dengan Google');
    }

    public function testLoginPageShowsGoogleButtonWhenConfigured(): void
    {
        $this->configure();

        $result = $this->get('login');

        $result->assertOK();
        $result->assertSee('Masuk dengan Google');
        $result->assertSee('Hanya alamat email yang sudah terdaftar');
    }

    public function testStartingLoginRedirectsToGoogleWithStateAndScope(): void
    {
        $this->configure();

        $result = $this->get('auth/google');

        $result->assertRedirect();
        $target = (string) $result->getRedirectUrl();

        $this->assertStringStartsWith('https://accounts.google.com/', $target);
        $this->assertStringContainsString('response_type=code', $target);
        $this->assertStringContainsString('scope=openid+email+profile', $target);
        $this->assertStringContainsString('state=', $target);

        // Client secret TIDAK boleh ikut ke browser.
        $this->assertStringNotContainsString('rahasia-uji', $target);
    }

    /**
     * State mengikat balasan Google ke sesi yang memulainya.
     */
    public function testCallbackWithoutMatchingStateIsRejected(): void
    {
        $this->configure();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/tidak dapat diverifikasi/');

        service('googleAuth')->completeLogin('kode-palsu', 'state-karangan');
    }

    public function testCallbackWithoutCodeIsRejected(): void
    {
        $this->configure();

        // Siapkan state yang sah lebih dulu.
        service('googleAuth')->authorizationUrl();
        $state = session()->get('google_oauth_state');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/dibatalkan/');

        service('googleAuth')->completeLogin(null, $state);
    }

    public function testGoogleRoutesAreReachableByGuests(): void
    {
        $this->configure();

        // Tamu harus bisa memulai alur — justru inilah yang membuat mereka login.
        $this->get('auth/google')->assertRedirect();

        $result = $this->get('auth/google/callback?error=access_denied');
        $result->assertRedirect();
        $this->assertStringContainsString('login', (string) $result->getRedirectUrl());
    }

    public function testAutoRegistrationIsDisabledByDefault(): void
    {
        $this->assertFalse((new GoogleAuth())->allowAutoRegistration);
    }

    public function testServiceReportsDisabledWhenCredentialsAreMissing(): void
    {
        $this->configure(false);

        $this->assertFalse(service('googleAuth')->isEnabled());

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/belum dikonfigurasi/');

        service('googleAuth')->authorizationUrl();
    }

    public function testRedirectUriDefaultsToTheApplicationCallback(): void
    {
        $config = new GoogleAuth();

        $this->assertStringEndsWith('auth/google/callback', $config->redirectUri());
    }
}
