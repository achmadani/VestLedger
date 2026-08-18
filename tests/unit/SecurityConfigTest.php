<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Security;
use Config\Session;

/**
 * Penjaga konfigurasi keamanan (§36).
 *
 * Test ini ada karena salah satu dari kesalahan berikut pernah benar-benar
 * terjadi di proyek ini, dan gejalanya tidak terlihat sampai seseorang
 * memeriksa direktori mana yang dilayani web server.
 *
 * @internal
 */
final class SecurityConfigTest extends CIUnitTestCase
{
    /**
     * File session TIDAK BOLEH berada di bawah web root.
     *
     * Menulis `session.savePath = null` di .env membuat CI4 memakai direktori
     * relatif bernama "null" di bawah direktori kerja (public/), karena nilai
     * .env selalu berupa string. Akibatnya file session dapat diunduh langsung
     * lewat browser — termasuk sesi yang sudah terautentikasi.
     */
    public function testSessionFilesAreStoredOutsideTheWebRoot(): void
    {
        $savePath = (new Session())->savePath;

        $this->assertNotSame('', $savePath, 'savePath tidak boleh kosong.');
        $this->assertNotSame('null', $savePath, 'savePath "null" adalah string, bukan nilai kosong.');

        $resolved = realpath($savePath) ?: $savePath;
        $webRoot  = realpath(FCPATH) ?: FCPATH;

        $this->assertStringStartsNotWith(
            rtrim($webRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            'File session berada di dalam web root dan dapat diunduh lewat browser.'
        );

        $this->assertStringStartsWith(
            rtrim(realpath(WRITEPATH) ?: WRITEPATH, DIRECTORY_SEPARATOR),
            rtrim($resolved, DIRECTORY_SEPARATOR),
            'File session seharusnya berada di bawah writable/.'
        );
    }

    /**
     * Shield menolak mode cookie karena rentan terhadap same-site attacker.
     */
    public function testCsrfProtectionUsesSessionNotCookie(): void
    {
        $this->assertSame('session', (new Security())->csrfProtection);
    }

    public function testCsrfTokenIsRandomisedPerRequest(): void
    {
        $this->assertTrue((new Security())->tokenRandomize);
    }

    /**
     * Registrasi mandiri harus tetap mati: aplikasi ini hanya untuk pemilik portofolio.
     */
    public function testPublicRegistrationStaysDisabled(): void
    {
        $this->assertFalse(config('Auth')->allowRegistration);
    }

    /**
     * Filter global yang wajib aktif (§36).
     */
    public function testGlobalSecurityFiltersAreEnabled(): void
    {
        $filters = config('Filters');

        $this->assertContains('csrf', $filters->globals['before']);
        $this->assertContains('invalidchars', $filters->globals['before']);
        $this->assertContains('secureheaders', $filters->globals['after']);
    }
}
