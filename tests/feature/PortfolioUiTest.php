<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketPriceModel;
use App\Models\SecuritiesAccountModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Halaman portofolio dan input harga pasar (§14, §20, §22).
 *
 * @internal
 */
final class PortfolioUiTest extends CIUnitTestCase
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

        service('cashTransactions')->topUp([
            'transaction_date' => date('Y') . '-01-02', 'securities_account_id' => $this->accountId, 'amount' => 100_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => date('Y') . '-01-05', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity' => 10_000, 'price' => 8_000,
        ]);
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

    public function testPortfolioPagesRender(): void
    {
        $owner = $this->makeUser('owner');

        foreach (['portfolio', 'portfolio/securities', 'portfolio/tickers', 'market-prices', 'dashboard'] as $path) {
            $this->actingAs($owner)->get($path)->assertOK();
        }
    }

    /**
     * Selama harga belum diinput, halaman harus MENGATAKAN bahwa nilainya belum
     * diketahui — bukan menampilkan unrealized nol yang menyesatkan.
     */
    public function testPortfolioWarnsWhenPositionsHaveNoMarketPrice(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('portfolio');

        $result->assertOK();
        $result->assertSee('belum memiliki harga pasar');
        $result->assertSee('bukan dianggap nol');
    }

    public function testEnteringPriceMakesMarketValueAndUnrealizedAppear(): void
    {
        $owner = $this->makeUser('owner');

        $this->postAs($owner, 'market-prices', [
            'price_date' => date('Y-m-d'),
            'prices'     => [$this->stockId => '9000'],
        ])->assertRedirect();

        $this->assertSame(1, (new MarketPriceModel())->countAllResults());

        $snapshot = service('portfolio')->snapshot();

        $this->assertSame(0, $snapshot['totals']['unpriced_count']);
        $this->assertSame('90000000.00', $snapshot['totals']['market_value']->toDecimalString());
        $this->assertSame('10000000.00', $snapshot['totals']['unrealized']->toDecimalString());

        $result = $this->actingAs($owner)->get('portfolio');
        $result->assertOK();
        $result->assertDontSee('belum memiliki harga pasar');
    }

    /**
     * §14: harga pasar tidak menghasilkan jurnal apa pun.
     */
    public function testEnteringPriceThroughTheFormCreatesNoJournal(): void
    {
        $before = $this->db->table('journal_entries')->countAllResults();

        $this->postAs($this->makeUser('owner'), 'market-prices', [
            'price_date' => date('Y-m-d'),
            'prices'     => [$this->stockId => '9000'],
        ]);

        $this->assertSame($before, $this->db->table('journal_entries')->countAllResults());
    }

    public function testViewerCanSeePortfolioButCannotEnterPrices(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer)->get('portfolio')->assertOK();
        $this->actingAs($viewer)->get('market-prices')->assertOK();

        $this->postAs($viewer, 'market-prices', [
            'price_date' => date('Y-m-d'),
            'prices'     => [$this->stockId => '9000'],
        ])->assertRedirect();

        $this->assertSame(0, (new MarketPriceModel())->countAllResults());
    }

    public function testDashboardShowsRealNumbersInsteadOfPlaceholders(): void
    {
        $owner = $this->makeUser('owner');

        $this->postAs($owner, 'market-prices', [
            'price_date' => date('Y-m-d'),
            'prices'     => [$this->stockId => '9000'],
        ]);

        $result = $this->actingAs($owner)->get('dashboard');

        $result->assertOK();
        $result->assertSee('Top Holdings');
        $result->assertSee('BBCA');
        // Kas 20.000.000 dan market value 90.000.000 -> net worth 110.000.000
        $result->assertSee('110.000.000');
    }
}
