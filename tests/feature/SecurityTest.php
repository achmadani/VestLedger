<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SecuritiesAccountModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Pengujian keamanan aplikasi (§36).
 *
 * Menyerang aplikasi lewat HTTP, bukan sekadar memeriksa kode: input berbahaya
 * benar-benar dikirim, lalu keluarannya diperiksa.
 *
 * @internal
 */
final class SecurityTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TruncatesDomainTables;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        // Throttler menyimpan hitungannya di cache dan tidak terikat database,
        // sehingga sisa dari test sebelumnya akan membuat test berikutnya
        // menerima 429 tanpa sebab.
        cache()->clean();

        $this->truncateDomainTables();
        \Config\Services::reset(true);

        service('chartOfAccounts')->ensureSystemAccounts();
        service('accountingPeriod')->generateYear((int) date('Y'));

        $ajaib           = service('securityService')->create(['code' => 'AJAIB', 'name' => 'Ajaib'], ['label' => 'RDN']);
        $this->accountId = (new SecuritiesAccountModel())->forSecurities($ajaib->id)[0]->id;
    }

    protected function tearDown(): void
    {
        // Jangan tinggalkan hitungan throttle untuk kelas test berikutnya.
        cache()->clean();

        parent::tearDown();
    }

    private function makeUser(string $group): User
    {
        $users = new UserModel();
        $user  = new User([
            'username' => $group . '_' . bin2hex(random_bytes(3)),
            'email'    => bin2hex(random_bytes(5)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup($group);

        return $user;
    }

    private function postAs(User $user, string $path, array $data = []): \CodeIgniter\Test\TestResponse
    {
        return $this->actingAs($user)->withBodyFormat('html')->post($path, $data + [csrf_token() => csrf_hash()]);
    }

    // ------------------------------------------------------------------ XSS

    /**
     * Skrip yang disimpan lewat nama perusahaan harus keluar sebagai teks,
     * bukan sebagai tag yang dieksekusi browser.
     */
    public function testStoredScriptInMasterDataIsEscapedOnOutput(): void
    {
        $owner   = $this->makeUser('owner');
        $payload = '<script>alert("xss")</script>';

        $this->postAs($owner, 'master/stocks', [
            'ticker'       => 'XSS',
            'company_name' => $payload,
            'is_active'    => '1',
        ]);

        $body = (string) $this->actingAs($owner)->get('master/stocks')->getBody();

        $this->assertStringNotContainsString($payload, $body, 'Skrip mentah tidak boleh muncul di HTML.');
        $this->assertStringContainsString('&lt;script&gt;', $body, 'Skrip harus tampil sebagai teks yang di-escape.');
    }

    /**
     * Catatan transaksi juga tampil di beberapa halaman.
     */
    public function testStoredScriptInTransactionNotesIsEscaped(): void
    {
        $owner   = $this->makeUser('owner');
        $payload = '"><img src=x onerror=alert(1)>';

        $this->postAs($owner, 'transactions/top-up', [
            'transaction_date'      => date('Y') . '-01-05',
            'securities_account_id' => (string) $this->accountId,
            'amount'                => '1000000',
            'notes'                 => $payload,
        ]);

        foreach (['transactions', 'accounting/journal'] as $path) {
            $body = (string) $this->actingAs($owner)->get($path)->getBody();

            $this->assertStringNotContainsString('onerror=alert', $body, 'Payload tidak boleh lolos di ' . $path);
        }
    }

    // ---------------------------------------------------------- SQL injection

    /**
     * Filter pencarian dikirim langsung ke query; nilainya harus terikat
     * sebagai parameter, bukan disisipkan ke SQL.
     */
    public function testSqlInjectionThroughSearchFilterDoesNotDamageData(): void
    {
        $owner = $this->makeUser('owner');

        $this->postAs($owner, 'transactions/top-up', [
            'transaction_date'      => date('Y') . '-01-05',
            'securities_account_id' => (string) $this->accountId,
            'amount'                => '1000000',
        ]);

        $before = $this->db->table('cash_transactions')->countAllResults();

        foreach ([
            "' OR '1'='1",
            "'; DROP TABLE cash_transactions; --",
            "1' UNION SELECT NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL --",
        ] as $payload) {
            $result = $this->actingAs($owner)->get('transactions?q=' . urlencode($payload));

            $this->assertTrue($result->isOK(), 'Aplikasi tidak boleh error karena input jahat.');
        }

        $this->assertSame($before, $this->db->table('cash_transactions')->countAllResults());
        $this->assertTrue($this->db->tableExists('cash_transactions'), 'Tabel harus tetap ada.');
    }

    public function testSqlInjectionThroughLedgerFiltersIsHarmless(): void
    {
        $owner = $this->makeUser('owner');

        $result = $this->actingAs($owner)->get('accounting/ledger?account_id=1%20OR%201=1&from=%27%20OR%20%271');

        $this->assertTrue($result->isOK());
        $this->assertTrue($this->db->tableExists('journal_lines'));
    }

    // ------------------------------------------------------------------ CSRF

    /**
     * Tanpa token CSRF, permintaan yang mengubah data harus ditolak.
     */
    public function testWriteRequestWithoutCsrfTokenIsRejected(): void
    {
        $owner = $this->makeUser('owner');

        // Sengaja TANPA token.
        $this->actingAs($owner)->withBodyFormat('html')->post('transactions/top-up', [
            'transaction_date'      => date('Y') . '-01-05',
            'securities_account_id' => (string) $this->accountId,
            'amount'                => '9999999',
        ]);

        $this->assertSame(0, $this->db->table('cash_transactions')->countAllResults());
    }

    // --------------------------------------------------------------- Otorisasi

    /**
     * Setiap rute yang mengubah data harus berada di balik filter permission.
     *
     * Diperiksa dari daftar rute, bukan satu per satu secara manual, sehingga
     * rute baru yang lupa diberi filter langsung ketahuan.
     */
    public function testEveryWriteRouteIsProtectedByAPermissionFilter(): void
    {
        $collection = service('routes');
        $collection->loadRoutes();

        $unprotected = [];

        foreach ($collection->getRoutes('post') as $route => $handler) {
            // Rute autentikasi Shield memang harus terbuka untuk tamu.
            if (preg_match('#^(login|register|auth/|logout)#', $route) === 1) {
                continue;
            }

            $filters = $collection->getFiltersForRoute($route, 'post');
            $joined  = implode(' ', $filters);

            if (! str_contains($joined, 'permission:')) {
                $unprotected[] = $route . ' -> ' . ($joined ?: 'tanpa filter');
            }
        }

        $this->assertSame([], $unprotected, "Rute POST tanpa filter permission:\n" . implode("\n", $unprotected));
    }

    // ------------------------------------------------------- Data sensitif

    /**
     * §36: nomor rekening tidak ditampilkan terbuka di daftar.
     */
    public function testAccountNumberIsNotExposedInListings(): void
    {
        $owner = $this->makeUser('owner');

        service('securityService')->create(
            ['code' => 'SECRET', 'name' => 'Uji Rahasia'],
            ['label' => 'RDN', 'account_number' => '9988776655']
        );

        $body = (string) $this->actingAs($owner)->get('master/securities')->getBody();

        $this->assertStringNotContainsString('9988776655', $body);
    }

    /**
     * Jejak audit dibaca lebih longgar daripada data aslinya, sehingga nomor
     * rekening tidak boleh ikut tersalin ke sana.
     */
    public function testAuditTrailScrubsSensitiveValues(): void
    {
        service('auditLogger')->record(
            'created',
            'uji',
            1,
            'Uji penyamaran',
            null,
            ['account_number' => '9988776655', 'password' => 'rahasia', 'amount' => '1000'],
        );

        $log = $this->db->table('audit_logs')->orderBy('id', 'desc')->get()->getRowArray();

        $this->assertStringNotContainsString('9988776655', (string) $log['new_values']);
        $this->assertStringNotContainsString('rahasia', (string) $log['new_values']);
        $this->assertStringContainsString('[disamarkan]', (string) $log['new_values']);
        // Data non-sensitif tetap tercatat.
        $this->assertStringContainsString('1000', (string) $log['new_values']);
    }

    // ------------------------------------------------------------- Rate limit

    /**
     * §36: percobaan login harus dibatasi lajunya.
     *
     * Shield menyediakan filternya tetapi tidak memasangnya sendiri; tanpa
     * konfigurasi di Config\Filters, halaman login menerima percobaan kata
     * sandi sebanyak apa pun tanpa hambatan.
     *
     * Yang diuji adalah PERILAKUNYA — permintaan berulang benar-benar ditolak —
     * bukan sekadar keberadaan nama filter di konfigurasi.
     */
    public function testRepeatedLoginAttemptsAreThrottled(): void
    {
        $throttled = false;

        // AuthRates membatasi 10 permintaan per menit per alamat IP.
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $result = $this->withBodyFormat('html')->post('login', [
                'email'         => 'penyerang@contoh.test',
                'password'      => 'tebakan-' . $attempt,
                csrf_token()    => csrf_hash(),
            ]);

            if ($result->response()->getStatusCode() === 429) {
                $throttled = true;

                break;
            }
        }

        $this->assertTrue($throttled, 'Percobaan login berulang seharusnya dibatasi dan menghasilkan 429.');
    }

    /**
     * Tanggal tidak valid di query string tidak boleh membuat aplikasi error.
     *
     * Nilainya memang selalu terikat sebagai parameter sehingga tidak ada
     * risiko injection, tetapi tanpa penyaringan ia diteruskan ke database
     * dan ditolak di sana — pengguna melihat halaman error hanya karena salah
     * ketik di URL.
     */
    public function testMalformedDateFiltersDoNotBreakAnyPage(): void
    {
        $owner   = $this->makeUser('owner');
        $garbage = urlencode("' OR '1");

        foreach ([
            'accounting/ledger?from=' . $garbage . '&to=' . $garbage,
            'accounting/journal?from=' . $garbage,
            'transactions?from=' . $garbage . '&to=' . $garbage,
            'reports/income-statement?from=' . $garbage,
            'reports/balance-sheet?as_of=' . $garbage,
            'portfolio?as_of=' . $garbage,
            'market-prices?date=' . $garbage,
        ] as $path) {
            $result = $this->actingAs($owner)->get($path);

            $this->assertTrue($result->isOK(), 'Gagal pada ' . $path);
        }
    }

    public function testSecurityHeadersArePresent(): void
    {
        $result = $this->get('login');

        $result->assertOK();
        $result->assertHeader('X-Frame-Options');
        $result->assertHeader('X-Content-Type-Options');
    }
}
