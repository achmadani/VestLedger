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
 * Halaman saldo awal (§19) dan chart dashboard (§31).
 *
 * @internal
 */
final class OpeningBalanceUiTest extends CIUnitTestCase
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
    private int $stockId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateDomainTables();
        \Config\Services::reset(true);

        service('chartOfAccounts')->ensureSystemAccounts();
        service('accountingPeriod')->generateYear((int) date('Y'));

        $ajaib           = service('securityService')->create(['code' => 'AJAIB', 'name' => 'Ajaib'], ['label' => 'RDN']);
        $this->accountId = (new SecuritiesAccountModel())->forSecurities($ajaib->id)[0]->id;
        $this->stockId   = service('stockService')->create(['ticker' => 'BBCA', 'company_name' => 'BCA'])->id;
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

    public function testOpeningBalanceFormRenders(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('accounting/opening-balance');

        $result->assertOK();
        $result->assertSee('Laba ditahan dihitung otomatis');
        $result->assertSee('Modal Disetor');
    }

    public function testSavingOpeningBalanceThroughTheFormBalancesTheBooks(): void
    {
        $owner = $this->makeUser('owner');
        $year  = date('Y');

        $result = $this->postAs($owner, 'accounting/opening-balance', [
            'as_of_date'      => $year . '-01-01',
            'cash'            => [$this->accountId => '5000000'],
            // Nilai dikirim sebagai string, persis seperti yang datang dari
            // browser — POST HTTP tidak pernah membawa tipe selain string.
            'positions'       => [[
                'securities_account_id' => (string) $this->accountId,
                'stock_id'              => (string) $this->stockId,
                'quantity'              => '2000',
                'book_value'            => '16400000',
            ]],
            'paid_in_capital' => '20000000',
        ]);

        $result->assertRedirect();

        $this->assertTrue(service('journalPoster')->ledgerIsBalanced());
        $this->assertTrue(service('financialStatements')->balanceSheet($year . '-01-01')['balanced']);
        $this->assertSame(2_000, service('positions')->current($this->accountId, $this->stockId)->quantity);
    }

    /**
     * Setelah tersimpan, halaman menampilkan ringkasan alih-alih form kosong.
     */
    public function testExistingOpeningBalanceIsShownAsSummary(): void
    {
        $owner = $this->makeUser('owner');
        $year  = date('Y');

        $this->postAs($owner, 'accounting/opening-balance', [
            'as_of_date'      => $year . '-01-01',
            'cash'            => [$this->accountId => '5000000'],
            'paid_in_capital' => '5000000',
        ]);

        $result = $this->actingAs($owner)->get('accounting/opening-balance');

        $result->assertOK();
        $result->assertSee('Saldo awal sudah tercatat');
        $result->assertSee('Hapus Saldo Awal');
    }

    /**
     * §36: mengisi saldo awal butuh permission opening.manage.
     */
    public function testAccountantCannotCreateOpeningBalance(): void
    {
        $this->actingAs($this->makeUser('accountant'))->get('accounting/opening-balance')->assertRedirect();

        $this->postAs($this->makeUser('accountant'), 'accounting/opening-balance', [
            'as_of_date'      => date('Y') . '-01-01',
            'cash'            => [$this->accountId => '5000000'],
            'paid_in_capital' => '5000000',
        ])->assertRedirect();

        $this->assertSame(0, $this->db->table('opening_balances')->countAllResults());
    }

    // ------------------------------------------------------------ Chart

    public function testDashboardRendersTheAssetChartAsInlineSvg(): void
    {
        $owner = $this->makeUser('owner');

        service('cashTransactions')->topUp([
            'transaction_date' => date('Y') . '-01-05',
            'securities_account_id' => $this->accountId, 'amount' => 10_000_000,
        ]);

        $result = $this->actingAs($owner)->get('dashboard');

        $result->assertOK();
        $result->assertSee('Perkembangan Aset');
        $result->assertSee('svg');
        $result->assertSee('Komposisi Portofolio');
        // Grafik digambar server-side, tanpa library chart apa pun.
        $result->assertDontSee('chart.js');
        $result->assertDontSee('cdn.jsdelivr');
    }

    /**
     * Grafik memakai nilai buku, dan halaman harus menyatakannya.
     */
    public function testChartStatesThatItUsesBookValue(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('dashboard');

        $result->assertOK();
        $result->assertSee('NILAI BUKU');
    }
}
