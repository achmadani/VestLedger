<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountCode;
use App\Models\AccountModel;
use App\Models\CashTransactionModel;
use App\Models\SecuritiesAccountModel;
use App\Models\StockTransactionModel;
use App\ValueObjects\Money;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Alur transaksi lewat HTTP, dari form sampai jurnal.
 *
 * @internal
 */
final class TransactionUiTest extends CIUnitTestCase
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
    private int $otherAccountId;
    private int $stockId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateDomainTables();
        \Config\Services::reset(true);

        service('chartOfAccounts')->ensureSystemAccounts();
        service('accountingPeriod')->generateYear((int) date('Y'));

        $ajaib = service('securityService')->create(['code' => 'AJAIB', 'name' => 'Ajaib'], ['label' => 'RDN']);
        $ipot  = service('securityService')->create(['code' => 'IPOT', 'name' => 'Indo Premier'], ['label' => 'RDN']);

        $accounts             = new SecuritiesAccountModel();
        $this->accountId      = $accounts->forSecurities($ajaib->id)[0]->id;
        $this->otherAccountId = $accounts->forSecurities($ipot->id)[0]->id;
        $this->stockId        = service('stockService')->create(['ticker' => 'BBCA', 'company_name' => 'BCA'])->id;
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

    /**
     * @param array<string, mixed> $data
     */
    private function postAs(User $user, string $path, array $data = []): \CodeIgniter\Test\TestResponse
    {
        return $this->actingAs($user)->withBodyFormat('html')->post($path, $data + [csrf_token() => csrf_hash()]);
    }

    // ------------------------------------------------------------- Halaman

    public function testEveryTransactionFormRenders(): void
    {
        $owner = $this->makeUser('owner');

        foreach ([
            'transactions', 'transactions/top-up', 'transactions/withdrawal',
            'transactions/transfer', 'transactions/fee', 'transactions/buy',
            'transactions/sell', 'transactions/dividend',
            'accounting/journal', 'accounting/ledger', 'system/audit',
        ] as $path) {
            $this->actingAs($owner)->get($path)->assertOK();
        }
    }

    public function testBuyFormShowsThePreviewPanel(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('transactions/buy');

        $result->assertOK();
        $result->assertSee('Preview');
        $result->assertSee('Average Cost Baru');
        $result->assertSee('Total Cost');
    }

    public function testSellFormShowsRealizedGainPreviewFields(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('transactions/sell');

        $result->assertOK();
        $result->assertSee('Book Value Dilepas');
        $result->assertSee('Estimasi Realized G/L');
        $result->assertSee('Estimasi Kas Diterima');
    }

    // ------------------------------------------------------------ Alur data

    public function testTopUpThroughTheFormCreatesTransactionAndBalancedJournal(): void
    {
        $result = $this->postAs($this->makeUser('owner'), 'transactions/top-up', [
            'transaction_date'      => date('Y') . '-01-05',
            'securities_account_id' => $this->accountId,
            'amount'                => '10000000',
        ]);

        $result->assertRedirect();

        $transaction = (new CashTransactionModel())->first();
        $this->assertNotNull($transaction);
        $this->assertNotNull($transaction->journal_entry_id, 'Transaksi wajib tertaut ke jurnal');

        $row = $this->db->table('journal_lines')
            ->select('SUM(debit) AS d, SUM(credit) AS c')
            ->get()->getRowArray();

        $this->assertTrue(Money::of((string) $row['d'])->equals(Money::of((string) $row['c'])));
    }

    public function testFullBuyThenSellFlowThroughHttpKeepsTheLedgerBalanced(): void
    {
        $owner = $this->makeUser('owner');
        $year  = date('Y');

        $this->postAs($owner, 'transactions/top-up', [
            'transaction_date' => $year . '-01-02', 'securities_account_id' => $this->accountId, 'amount' => '100000000',
        ]);

        $this->postAs($owner, 'transactions/buy', [
            'transaction_date' => $year . '-01-05', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity' => '10000', 'price' => '8000', 'broker_fee' => '20000',
        ]);

        $this->postAs($owner, 'transactions/sell', [
            'transaction_date' => $year . '-02-10', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity' => '5000', 'price' => '9000', 'broker_fee' => '15000',
        ]);

        $stocks = new StockTransactionModel();
        $this->assertSame(2, $stocks->countAllResults());

        $sale = $stocks->where('type', 'sell')->first();
        $this->assertSame('40010000.00', $sale->bookValueSold()->toDecimalString());
        $this->assertSame('4990000.00', $sale->realizedGainGross()->toDecimalString());

        $this->assertTrue(service('journalPoster')->ledgerIsBalanced(), 'Buku besar harus tetap balance');
        $this->assertSame(5000, service('positions')->current($this->accountId, $this->stockId)->quantity);
    }

    public function testTransferThroughFormMovesCashBetweenAccounts(): void
    {
        $owner = $this->makeUser('owner');
        $year  = date('Y');

        $this->postAs($owner, 'transactions/top-up', [
            'transaction_date' => $year . '-01-02', 'securities_account_id' => $this->accountId, 'amount' => '10000000',
        ]);
        $this->postAs($owner, 'transactions/transfer', [
            'transaction_date'       => $year . '-01-10',
            'securities_account_id'  => $this->accountId,
            'counterpart_account_id' => $this->otherAccountId,
            'amount'                 => '4000000',
        ]);

        $cashAccountId = (new AccountModel())->idFor(AccountCode::Cash);
        $balances      = (new \App\Models\JournalLineModel())->cashBalanceByAccount($cashAccountId);

        $this->assertSame('6000000.00', Money::of($balances[$this->accountId])->toDecimalString());
        $this->assertSame('4000000.00', Money::of($balances[$this->otherAccountId])->toDecimalString());
    }

    /**
     * Kegagalan aturan bisnis harus kembali sebagai pesan, bukan halaman error —
     * dan tidak boleh menyisakan data apa pun.
     */
    public function testInvalidSellIsRejectedGracefullyWithoutWritingAnything(): void
    {
        $owner = $this->makeUser('owner');

        $result = $this->postAs($owner, 'transactions/sell', [
            'transaction_date' => date('Y') . '-02-10', 'securities_account_id' => $this->accountId,
            'stock_id' => $this->stockId, 'quantity' => '100', 'price' => '9000',
        ]);

        $result->assertRedirect();

        $this->assertSame(0, (new StockTransactionModel())->countAllResults());
        $this->assertSame(0, $this->db->table('journal_entries')->countAllResults());
    }

    // ------------------------------------------------------------ Otorisasi

    public function testViewerCannotOpenTransactionFormsOrPost(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer)->get('transactions')->assertOK();
        $this->actingAs($viewer)->get('transactions/buy')->assertRedirect();

        $this->postAs($viewer, 'transactions/top-up', [
            'transaction_date' => date('Y') . '-01-05',
            'securities_account_id' => $this->accountId, 'amount' => '1000000',
        ])->assertRedirect();

        $this->assertSame(0, (new CashTransactionModel())->countAllResults());
    }

    /**
     * Membatalkan transaksi memerlukan transaction.void; viewer tidak memilikinya.
     */
    public function testViewerCannotReverseATransaction(): void
    {
        $owner = $this->makeUser('owner');
        $this->postAs($owner, 'transactions/top-up', [
            'transaction_date' => date('Y') . '-01-05',
            'securities_account_id' => $this->accountId, 'amount' => '1000000',
        ]);

        $transaction = (new CashTransactionModel())->first();

        $this->postAs($this->makeUser('viewer'), 'transactions/cash/' . $transaction->id . '/reverse')
            ->assertRedirect();

        $this->assertTrue(
            (new CashTransactionModel())->find($transaction->id)->status()->isEffective(),
            'Transaksi seharusnya tetap posted.'
        );
    }

    public function testOwnerCanReverseAndTheTransactionSurvivesAsReversed(): void
    {
        $owner = $this->makeUser('owner');
        $this->postAs($owner, 'transactions/top-up', [
            'transaction_date' => date('Y') . '-01-05',
            'securities_account_id' => $this->accountId, 'amount' => '1000000',
        ]);

        $transaction = (new CashTransactionModel())->first();

        $this->postAs($owner, 'transactions/cash/' . $transaction->id . '/reverse', ['reason' => 'Salah input'])
            ->assertRedirect();

        $after = (new CashTransactionModel())->find($transaction->id);
        $this->assertFalse($after->status()->isEffective());
        $this->assertSame(1, (new CashTransactionModel())->countAllResults(), 'Tidak boleh ada penghapusan');
        $this->assertSame(2, $this->db->table('journal_entries')->countAllResults(), 'Jurnal asli + pembalik');
        $this->assertTrue(service('journalPoster')->ledgerIsBalanced());
    }

    public function testJournalPageReportsLedgerHealth(): void
    {
        $owner = $this->makeUser('owner');
        $this->postAs($owner, 'transactions/top-up', [
            'transaction_date' => date('Y') . '-01-05',
            'securities_account_id' => $this->accountId, 'amount' => '1000000',
        ]);

        $result = $this->actingAs($owner)->get('accounting/journal');

        $result->assertOK();
        $result->assertSee('Buku besar balance');
        $result->assertSee('JV-');
    }
}
