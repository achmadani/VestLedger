<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
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
     * Registrasi mandiri harus mati: aplikasi ini hanya untuk pemilik portofolio.
     */
    public function testPublicRegistrationRouteIsNotRegistered(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->get('register');
    }
}
