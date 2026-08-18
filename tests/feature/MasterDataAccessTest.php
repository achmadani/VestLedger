<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Otorisasi halaman master data ditegakkan di lapisan routing, bukan sekadar
 * disembunyikan dari menu (§36).
 *
 * @internal
 */
final class MasterDataAccessTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use TruncatesDomainTables;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Setiap test berangkat dari keadaan yang sama; lihat TruncatesDomainTables
        // untuk alasan FK check dimatikan sementara.
        $this->truncateDomainTables();

        // Service di-share antar pemanggilan, jadi instance lamanya (beserta
        // cache di dalamnya) harus dibuang agar tidak membawa state test sebelumnya.
        \Config\Services::reset(true);
        service('chartOfAccounts')->ensureSystemAccounts();
    }

    /**
     * POST dengan token CSRF yang sah.
     *
     * Tanpa token, filter CSRF akan menolak request lebih dulu — sehingga test
     * penolakan akses bisa "lulus" karena alasan yang keliru dan tidak lagi
     * membuktikan apa pun tentang otorisasi.
     *
     * @param array<string, mixed> $data
     */
    private function postAs(User $user, string $path, array $data = []): \CodeIgniter\Test\TestResponse
    {
        return $this->actingAs($user)->withBodyFormat('html')->post(
            $path,
            $data + [csrf_token() => csrf_hash()]
        );
    }

    private function makeUser(string $username, string $group): User
    {
        $users = new UserModel();
        $user  = new User([
            'username' => $username . '_' . bin2hex(random_bytes(3)),
            'email'    => bin2hex(random_bytes(5)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup($group);

        return $user;
    }

    public function testViewerCanBrowseMasterData(): void
    {
        $viewer = $this->makeUser('viewer', 'viewer');

        foreach (['master/securities', 'master/stocks', 'master/accounts'] as $path) {
            $this->actingAs($viewer)->get($path)->assertOK();
        }
    }

    /**
     * Viewer bersifat read-only: halaman form dan aksi tulis harus ditolak.
     */
    public function testViewerCannotReachCreateFormsOrWriteActions(): void
    {
        $viewer = $this->makeUser('viewer_ro', 'viewer');

        foreach (['master/securities/new', 'master/stocks/new', 'master/accounts/new'] as $path) {
            $this->actingAs($viewer)->get($path)->assertRedirect();
        }

        $result = $this->postAs($viewer, 'master/stocks', [
            'ticker' => 'HACK', 'company_name' => 'Seharusnya ditolak',
        ]);
        $result->assertRedirect();

        $this->assertNull((new \App\Models\StockModel())->findByTicker('HACK'));
    }

    public function testOwnerCanCreateStockThroughTheForm(): void
    {
        $owner = $this->makeUser('owner', 'owner');

        $result = $this->postAs($owner, 'master/stocks', [
            'ticker'       => 'bbca',
            'company_name' => 'Bank Central Asia Tbk',
            'sector'       => 'Keuangan',
            'is_active'    => '1',
        ]);

        $result->assertRedirect();

        $stock = (new \App\Models\StockModel())->findByTicker('BBCA');
        $this->assertNotNull($stock);
        $this->assertSame('BBCA', $stock->ticker);
    }

    public function testChartOfAccountsPageListsSystemAccounts(): void
    {
        $result = $this->actingAs($this->makeUser('owner_coa', 'owner'))->get('master/accounts');

        $result->assertOK();
        $result->assertSee('1000');
        $result->assertSee('3200');
        $result->assertSee('Chart of Accounts');
    }

    /**
     * Menu Phase 2 sudah aktif dan mengarah ke halaman yang benar-benar ada.
     */
    public function testSidebarLinksToPhaseTwoPages(): void
    {
        $result = $this->actingAs($this->makeUser('owner_nav', 'owner'))->get('dashboard');

        $result->assertOK();
        $result->assertSee('master/securities');
        $result->assertSee('master/stocks');
        $result->assertSee('master/accounts');
        $result->assertSee('accounting/periods');
    }

    /**
     * Komponen tidak boleh mewarisi variabel halaman induknya.
     *
     * Sebelum diperbaiki, merender form beli lalu halaman daftar saham dalam
     * satu proses membuat komponen form/input mengambil variabel $type milik
     * form beli — yang berisi enum, bukan string — dan halaman gagal dengan
     * "Object of class StockTransactionType could not be converted to string".
     */
    public function testRenderingSeveralPagesDoesNotLeakViewVariables(): void
    {
        $owner = $this->makeUser('owner', 'owner');

        service('accountingPeriod')->generateYear((int) date('Y'));
        service('stockService')->create(['ticker' => 'BBCA', 'company_name' => 'Bank Central Asia Tbk']);

        // Form beli menetapkan variabel bernama $type berisi enum...
        $this->actingAs($owner)->get('transactions/buy')->assertOK();

        // ...dan halaman ini memanggil form/input tanpa menyebut type.
        $this->actingAs($owner)->get('master/stocks')->assertOK();

        // Urutan sebaliknya juga harus aman.
        $this->actingAs($owner)->get('transactions/top-up')->assertOK();
        $this->actingAs($owner)->get('master/securities')->assertOK();
    }

    /**
     * Menutup periode adalah aksi berdampak besar; accountant tidak memilikinya.
     */
    public function testAccountantCannotClosePeriods(): void
    {
        service('accountingPeriod')->generateYear(2026);
        $period = (new \App\Models\AccountingPeriodModel())->findByCode('2026-01');

        $result = $this->postAs($this->makeUser('acct', 'accountant'), 'accounting/periods/' . $period->id . '/close');

        $result->assertRedirect();

        $this->assertTrue(
            (new \App\Models\AccountingPeriodModel())->find($period->id)->isOpen(),
            'Periode seharusnya tetap terbuka.'
        );
    }
}
