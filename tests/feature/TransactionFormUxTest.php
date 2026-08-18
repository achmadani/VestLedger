<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Kenyamanan form transaksi: urutan rekening, pencarian saham, biaya otomatis.
 *
 * @internal
 */
final class TransactionFormUxTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TruncatesDomainTables;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var array<string, int> */
    private array $accounts = [];
    private int $bbca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateDomainTables();
        \Config\Services::reset(true);

        service('chartOfAccounts')->ensureSystemAccounts();
        service('accountingPeriod')->generateYear((int) date('Y'));

        $model = new SecuritiesAccountModel();

        foreach (['AJAIB', 'IPOT', 'MIRAE'] as $code) {
            $security = service('securityService')->create(['code' => $code, 'name' => $code . ' Sekuritas'], ['label' => 'RDN']);
            $this->accounts[$code] = $model->forSecurities($security->id)[0]->id;
        }

        $this->bbca = service('stockService')->create(['ticker' => 'BBCA', 'company_name' => 'Bank Central Asia Tbk'])->id;
        service('stockService')->create(['ticker' => 'BBRI', 'company_name' => 'Bank Rakyat Indonesia Tbk']);
        service('stockService')->create(['ticker' => 'BMRI', 'company_name' => 'Bank Mandiri Tbk']);
        service('stockService')->create(['ticker' => 'TLKM', 'company_name' => 'Telkom Indonesia Tbk']);
    }

    private function owner(): User
    {
        $users = new UserModel();
        $user  = new User([
            'username' => 'owner' . bin2hex(random_bytes(4)),
            'email'    => bin2hex(random_bytes(5)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('owner');

        return $user;
    }

    // --------------------------------------------- Urutan rekening

    /**
     * Rekening yang paling sering dipakai muncul paling atas, karena itulah
     * yang hampir selalu sedang dipakai.
     */
    public function testMostUsedSecuritiesAccountComesFirst(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => date('Y') . '-01-02',
            'securities_account_id' => $this->accounts['MIRAE'], 'amount' => 100_000_000,
        ]);

        // MIRAE dipakai tiga kali, AJAIB sekali, IPOT belum pernah.
        for ($i = 0; $i < 3; $i++) {
            service('stockTransactions')->buy([
                'transaction_date' => date('Y') . '-01-0' . ($i + 3),
                'securities_account_id' => $this->accounts['MIRAE'],
                'stock_id' => $this->bbca, 'quantity' => 100, 'price' => 1_000,
            ]);
        }

        service('cashTransactions')->topUp([
            'transaction_date' => date('Y') . '-01-02',
            'securities_account_id' => $this->accounts['AJAIB'], 'amount' => 10_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => date('Y') . '-01-07',
            'securities_account_id' => $this->accounts['AJAIB'],
            'stock_id' => $this->bbca, 'quantity' => 100, 'price' => 1_000,
        ]);

        $order = array_values((new SecuritiesAccountModel())->optionsByUsage());

        $this->assertStringStartsWith('MIRAE', $order[0], 'Paling sering dipakai harus di atas');
        $this->assertStringStartsWith('AJAIB', $order[1]);
        $this->assertStringStartsWith('IPOT', $order[2], 'Yang belum pernah dipakai di bawah');
    }

    public function testUnusedAccountsStillAppearInTheList(): void
    {
        $this->assertCount(3, (new SecuritiesAccountModel())->optionsByUsage());
    }

    // --------------------------------------------- Pencarian saham

    public function testStockSearchMatchesByTickerPrefix(): void
    {
        $results = (new StockModel())->search('BB');

        $tickers = array_column($results, 'ticker');

        $this->assertContains('BBCA', $tickers);
        $this->assertContains('BBRI', $tickers);
        $this->assertNotContains('TLKM', $tickers);
    }

    public function testStockSearchAlsoMatchesCompanyName(): void
    {
        $tickers = array_column((new StockModel())->search('Mandiri'), 'ticker');

        $this->assertSame(['BMRI'], $tickers);
    }

    /**
     * Pengguna mengetik kode, jadi kecocokan pada ticker didahulukan.
     */
    public function testExactTickerMatchIsRankedFirst(): void
    {
        service('stockService')->create(['ticker' => 'ZZZZ', 'company_name' => 'Perusahaan BBCA Sejahtera']);

        $results = (new StockModel())->search('BBCA');

        $this->assertSame('BBCA', $results[0]['ticker']);
    }

    public function testSearchEndpointRequiresAuthentication(): void
    {
        $this->get('api/stocks/search?q=BB')->assertRedirect();
    }

    public function testSearchEndpointReturnsJsonForLoggedInUser(): void
    {
        $result = $this->actingAs($this->owner())->get('api/stocks/search?q=BBCA');

        $result->assertOK();
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->assertStringContainsString('Bank Central Asia', (string) $result->getBody());
    }

    /**
     * Satu huruf akan mengembalikan hampir seluruh daftar tanpa membantu.
     */
    public function testSearchEndpointIgnoresVeryShortQueries(): void
    {
        $result = $this->actingAs($this->owner())->get('api/stocks/search?q=B');

        $result->assertOK();

        // Catatan: FeatureTestTrait membungkus body dengan kerangka HTML, jadi
        // yang diuji adalah tidak adanya satu pun emiten dalam hasilnya.
        $body = (string) $result->getBody();
        $this->assertStringContainsString('[]', $body);
        $this->assertStringNotContainsString('BBCA', $body);
        $this->assertStringNotContainsString('BBRI', $body);
    }

    // --------------------------------------------- Form

    public function testBuyFormShipsFeeRatesAndSearchBox(): void
    {
        $result = $this->actingAs($this->owner())->get('transactions/buy');

        $result->assertOK();
        $result->assertSee('Ketik kode saham');
        $result->assertSee('feeRates');
        $result->assertSee('Hitung ulang dari tarif sekuritas');
        // Daftar emiten TIDAK ikut dikirim ke browser.
        $result->assertDontSee('Telkom Indonesia Tbk');
    }

    public function testSellFormShowsStampDutyLineInPreview(): void
    {
        $result = $this->actingAs($this->owner())->get('transactions/sell');

        $result->assertOK();
        $result->assertSee('Bea Materai');
    }
}
