<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Kontrol akses adalah bagian dari keamanan data keuangan (§36).
 * Halaman aplikasi tidak boleh bisa dibuka tanpa login.
 *
 * @internal
 */
final class AuthAccessTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public function testGuestIsRedirectedFromDashboardToLogin(): void
    {
        $result = $this->get('dashboard');

        $result->assertRedirect();
        $this->assertStringContainsString('login', (string) $result->getRedirectUrl());
    }

    public function testRootRedirectsGuestToLogin(): void
    {
        $result = $this->get('/');

        $result->assertRedirect();
        $this->assertStringContainsString('login', (string) $result->getRedirectUrl());
    }

    public function testLoginPageRendersVestLedgerLayout(): void
    {
        $result = $this->get('login');

        $result->assertOK();
        $result->assertSee('Masuk ke akun Anda');
        $result->assertSee('name="email"');
        $result->assertSee('name="password"');
        // Layout VestLedger, bukan layout bawaan Shield yang memakai Bootstrap.
        $result->assertSee('assets/css/app.css');
        $result->assertDontSee('bootstrap');
    }

    public function testLoginFormIsProtectedByCsrfToken(): void
    {
        $result = $this->get('login');

        $result->assertOK();
        $result->assertSee(csrf_token());
    }

    /**
     * Logout dipicu lewat form POST ber-CSRF, bukan tautan biasa.
     *
     * Shield hanya mendaftarkan logout sebagai GET, sehingga tombol keluar di
     * navbar sempat menghasilkan 404. Rute POST ditambahkan sendiri karena
     * logout lewat GET dapat dipicu pihak lain hanya dengan menyisipkan
     * <img src=".../logout"> di halaman mana pun.
     */
    public function testLogoutAcceptsThePostRequestSentByTheNavbar(): void
    {
        $users = new UserModel();
        $user  = new User([
            'username' => 'keluar' . bin2hex(random_bytes(4)),
            'email'    => bin2hex(random_bytes(5)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('owner');

        $result = $this->actingAs($user)->withBodyFormat('html')->post('logout', [
            csrf_token() => csrf_hash(),
        ]);

        // Yang penting: TIDAK 404. Sebelum perbaikan, tombol keluar di navbar
        // menghasilkan "Can't find a route for 'POST: logout'".
        $this->assertNotSame(404, $result->response()->getStatusCode());
        $result->assertRedirect();
        $this->assertFalse(auth()->loggedIn(), 'Sesi harus berakhir setelah keluar.');
    }

    /**
     * Registrasi mandiri harus mati: aplikasi ini hanya untuk pemilik portofolio.
     */
    public function testPublicRegistrationRouteIsNotRegistered(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->get('register');
    }
}
