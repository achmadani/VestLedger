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
 * Halaman laporan (§21–§24).
 *
 * @internal
 */
final class ReportUiTest extends CIUnitTestCase
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
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateDomainTables();
        \Config\Services::reset(true);

        $this->year = (int) date('Y');

        service('chartOfAccounts')->ensureSystemAccounts();
        service('accountingPeriod')->generateYear($this->year);

        $ajaib           = service('securityService')->create(['code' => 'AJAIB', 'name' => 'Ajaib'], ['label' => 'RDN']);
        $this->accountId = (new SecuritiesAccountModel())->forSecurities($ajaib->id)[0]->id;
        $this->stockId   = service('stockService')->create(['ticker' => 'BBCA', 'company_name' => 'BCA'])->id;

        service('cashTransactions')->topUp([
            'transaction_date' => $this->year . '-01-02', 'securities_account_id' => $this->accountId, 'amount' => 100_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => $this->year . '-01-10', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity' => 10_000, 'price' => 8_000, 'broker_fee' => 20_000,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => $this->year . '-02-10', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity' => 5_000, 'price' => 9_000, 'broker_fee' => 15_000,
        ]);
        service('dividendTransactions')->record([
            'transaction_date' => $this->year . '-03-10', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity_eligible' => 5_000, 'dividend_per_share' => 100,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->stockId, 'price_date' => date('Y-m-d'), 'closing_price' => 9_500,
        ]);
    }

    private function owner(): User
    {
        $users = new UserModel();
        $user  = new User([
            'username' => 'owner_' . bin2hex(random_bytes(3)),
            'email'    => bin2hex(random_bytes(5)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('owner');

        return $user;
    }

    /**
     * Laba Rugi dapat dibatasi pada satu sekuritas, dan halamannya harus
     * menyatakan pembatasan itu — angka yang terlihat seperti laba rugi
     * seluruh entitas padahal bukan adalah salah baca yang mahal.
     */
    public function testIncomeStatementCanBeScopedToOneSecuritiesAccount(): void
    {
        $result = $this->actingAs($this->owner())
            ->get('reports/income-statement?securities_account_id=' . $this->accountId);

        $result->assertOK();
        $result->assertSee('dibatasi pada');
        $result->assertSee('bukan');
    }

    /**
     * Rincian per sekuritas menampilkan rekeningnya dan angka yang bersesuaian.
     */
    public function testProfitBySecuritiesPageShowsEachAccount(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/profit-by-securities');

        $result->assertOK();
        $result->assertSee('Laba Rugi per Sekuritas');
        $result->assertSee('AJAIB');
        $result->assertSee('Unrealized');
    }

    /**
     * Filter sekuritas pada Realized G/L sudah lama didukung controller, tetapi
     * dahulu tidak pernah muncul di halaman — praktis hanya dapat dipakai
     * dengan mengetik URL sendiri.
     */
    public function testRealizedReportOffersSecuritiesFilter(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/realized');

        $result->assertOK();
        $this->assertStringContainsString('securities_account_id', (string) $result->getBody());
    }

    public function testAllReportPagesRender(): void
    {
        $owner = $this->owner();

        foreach ([
            'reports/balance-sheet', 'reports/income-statement', 'reports/cash-flow',
            'accounting/trial-balance', 'reports/monthly', 'reports/yearly',
            'reports/realized', 'reports/unrealized', 'reports/dividend', 'reports/broker-fee',
            'reports/profit-by-securities',
        ] as $path) {
            $this->actingAs($owner)->get($path)->assertOK();
        }
    }

    public function testBalanceSheetReportsItselfAsBalanced(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/balance-sheet');

        $result->assertOK();
        $result->assertSee('Neraca balance');
        $result->assertDontSee('TIDAK balance');
    }

    public function testTrialBalanceReportsDebitEqualsCredit(): void
    {
        $result = $this->actingAs($this->owner())->get('accounting/trial-balance');

        $result->assertOK();
        $result->assertSee('Total debit sama dengan total kredit');
    }

    /**
     * Laba Rugi harus menyatakan bahwa unrealized tidak termasuk di dalamnya.
     */
    public function testIncomeStatementStatesThatUnrealizedIsExcluded(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/income-statement');

        $result->assertOK();
        $result->assertSee('Unrealized gain/loss tidak muncul di sini');
    }

    public function testCashFlowExplainsWhyTransfersAreAbsent(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/cash-flow');

        $result->assertOK();
        $result->assertSee('Transfer antar sekuritas tidak muncul');
        $result->assertSee('Aktivitas Pendanaan');
        $result->assertSee('Aktivitas Investasi');
    }

    public function testMonthlyReportShowsComparisonWithPreviousMonth(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/monthly?year=' . $this->year . '&month=2');

        $result->assertOK();
        $result->assertSee('Perbandingan dengan Januari');
        $result->assertSee('Februari');
    }

    public function testYearlyReportListsTwelveMonths(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/yearly?year=' . $this->year);

        $result->assertOK();
        $result->assertSee('Rincian per Bulan');
        $result->assertSee('Januari');
        $result->assertSee('Desember');
    }

    /**
     * Laporan broker fee harus menampilkan biaya beli yang dikapitalisasi,
     * bukan menyembunyikannya karena bukan beban.
     */
    public function testBrokerFeeReportSeparatesCapitalisedFromExpensed(): void
    {
        $result = $this->actingAs($this->owner())->get('reports/broker-fee');

        $result->assertOK();
        $result->assertSee('dikapitalisasi');
        $result->assertSee('dibebankan');
        $result->assertSee('20.000');  // fee pembelian
        $result->assertSee('15.000');  // fee penjualan
    }

    public function testReversedDateRangeIsCorrectedInsteadOfReturningNothing(): void
    {
        $result = $this->actingAs($this->owner())
            ->get('reports/income-statement?from=' . $this->year . '-12-31&to=' . $this->year . '-01-01');

        $result->assertOK();
        // Rentang ditukar, sehingga laporan tetap berisi data setahun penuh.
        $result->assertSee('Total Pendapatan');
    }

    public function testViewerCanReadReports(): void
    {
        $users = new UserModel();
        $user  = new User([
            'username' => 'viewer_' . bin2hex(random_bytes(3)),
            'email'    => bin2hex(random_bytes(5)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('viewer');

        $this->actingAs($user)->get('reports/balance-sheet')->assertOK();
        $this->actingAs($user)->get('reports/yearly')->assertOK();
    }
}
