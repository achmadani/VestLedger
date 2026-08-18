<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Enums\SourceType;
use App\Exceptions\BusinessRuleException;
use App\Models\JournalLineModel;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\Money;
use LogicException;
use Tests\Support\Engine\EngineTestCase;

/**
 * Dividen (§15) dan pengaman mesin jurnal (§8).
 *
 * @internal
 */
final class DividendAndJournalTest extends EngineTestCase
{
    // ------------------------------------------------------------- Dividen

    public function testDividendWithoutTaxIncreasesCashAndIncome(): void
    {
        service('dividendTransactions')->record([
            'transaction_date'      => '2026-04-15',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity_eligible'     => 10_000,
            'dividend_per_share'    => 100,
        ]);

        $this->assertMoneyEquals('1000000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertMoneyEquals('1000000.00', $this->accountBalance(AccountCode::DividendIncome));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * §15: dengan pajak, pendapatan tetap dicatat BRUTO dan pajaknya menjadi
     * beban tersendiri — bukan dikurangkan diam-diam dari pendapatan.
     */
    public function testDividendTaxIsExpensedWhileIncomeStaysGross(): void
    {
        $dividend = service('dividendTransactions')->record([
            'transaction_date'      => '2026-04-15',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity_eligible'     => 10_000,
            'dividend_per_share'    => 100,
            'tax'                   => 100_000,
        ]);

        $this->assertMoneyEquals('1000000.00', $dividend->grossDividend());
        $this->assertMoneyEquals('900000.00', $dividend->netDividend());

        $this->assertMoneyEquals('900000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib), 'Kas bertambah netto');
        $this->assertMoneyEquals('1000000.00', $this->accountBalance(AccountCode::DividendIncome), 'Pendapatan tercatat bruto');
        $this->assertMoneyEquals('100000.00', $this->accountBalance(AccountCode::TaxAndLevy));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    public function testDividendTaxCannotExceedGross(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/melebihi dividen bruto/');

        service('dividendTransactions')->record([
            'transaction_date' => '2026-04-15', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity_eligible' => 100, 'dividend_per_share' => 10, 'tax' => 5_000,
        ]);
    }

    /**
     * Dividen adalah pendapatan; top up bukan. Keduanya tidak boleh tertukar (§40.3).
     */
    public function testDividendIsRevenueWhileTopUpIsNot(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 5_000_000,
        ]);
        service('dividendTransactions')->record([
            'transaction_date' => '2026-04-15', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity_eligible' => 1_000, 'dividend_per_share' => 100,
        ]);

        $this->assertMoneyEquals('100000.00', $this->accountBalance(AccountCode::DividendIncome));
        $this->assertMoneyEquals('5000000.00', $this->accountBalance(AccountCode::PaidInCapital));
    }

    // ------------------------------------------------- Pengaman JournalPoster

    /**
     * §8: jurnal tidak balance harus DITOLAK, bukan disimpan lalu diperbaiki.
     */
    public function testUnbalancedJournalIsRejected(): void
    {
        $draft = new JournalDraft('2026-05-01', 'Sengaja tidak balance', SourceType::Manual);
        $draft->debit(AccountCode::Cash, Money::of(1000), $this->ajaib);
        $draft->credit(AccountCode::PaidInCapital, Money::of(900));

        $db = db_connect();
        $db->transBegin();

        try {
            service('journalPoster')->post($draft);
            $this->fail('Jurnal tidak balance seharusnya ditolak.');
        } catch (BusinessRuleException $e) {
            $this->assertMatchesRegularExpression('/tidak balance/', $e->getMessage());
            $this->assertStringContainsString('100.00', implode(' ', $e->reasons()), 'Selisih harus dilaporkan');
        } finally {
            $db->transRollback();
        }

        $this->assertSame(0, $this->db->table('journal_entries')->countAllResults());
    }

    /**
     * Pengaman terhadap kegagalan yang paling ditakuti §8: transaksi tersimpan
     * tetapi jurnalnya gagal. Poster menolak berjalan di luar database transaction.
     */
    public function testPosterRefusesToRunOutsideADatabaseTransaction(): void
    {
        $draft = new JournalDraft('2026-05-01', 'Di luar transaksi', SourceType::Manual);
        $draft->debit(AccountCode::Cash, Money::of(1000), $this->ajaib);
        $draft->credit(AccountCode::PaidInCapital, Money::of(1000));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/database transaction/');

        service('journalPoster')->post($draft);
    }

    public function testSingleLineJournalIsRejected(): void
    {
        $draft = new JournalDraft('2026-05-01', 'Satu baris', SourceType::Manual);
        $draft->debit(AccountCode::Cash, Money::of(1000), $this->ajaib);

        $db = db_connect();
        $db->transBegin();

        try {
            service('journalPoster')->post($draft);
            $this->fail('Jurnal satu baris seharusnya ditolak.');
        } catch (BusinessRuleException $e) {
            $this->assertMatchesRegularExpression('/minimal dua baris|tidak balance/', $e->getMessage());
        } finally {
            $db->transRollback();
        }
    }

    /**
     * §21.5: buku besar harus dapat difilter per sekuritas dan per ticker,
     * jadi dimensi pada akun kas dan portofolio bersifat wajib.
     */
    public function testCashLineWithoutSecuritiesDimensionIsRejected(): void
    {
        $draft = new JournalDraft('2026-05-01', 'Tanpa dimensi', SourceType::Manual);
        $draft->debit(AccountCode::Cash, Money::of(1000));            // dimensi hilang
        $draft->credit(AccountCode::PaidInCapital, Money::of(1000));

        $db = db_connect();
        $db->transBegin();

        try {
            service('journalPoster')->post($draft);
            $this->fail('Baris kas tanpa dimensi sekuritas seharusnya ditolak.');
        } catch (BusinessRuleException $e) {
            $this->assertMatchesRegularExpression('/wajib menyebut rekening sekuritas/', $e->getMessage());
        } finally {
            $db->transRollback();
        }
    }

    public function testPortfolioLineWithoutStockDimensionIsRejected(): void
    {
        $draft = new JournalDraft('2026-05-01', 'Tanpa saham', SourceType::Manual);
        $draft->debit(AccountCode::StockPortfolio, Money::of(1000), $this->ajaib); // stock_id hilang
        $draft->credit(AccountCode::Cash, Money::of(1000), $this->ajaib);

        $db = db_connect();
        $db->transBegin();

        try {
            service('journalPoster')->post($draft);
            $this->fail('Baris portofolio tanpa dimensi saham seharusnya ditolak.');
        } catch (BusinessRuleException $e) {
            $this->assertMatchesRegularExpression('/wajib menyebut saham/', $e->getMessage());
        } finally {
            $db->transRollback();
        }
    }

    /**
     * Nilai negatif dibalik ke sisi lawan, bukan dicatat sebagai debit negatif.
     */
    public function testNegativeAmountFlipsSideInsteadOfBecomingANegativeDebit(): void
    {
        $draft = new JournalDraft('2026-05-01', 'Nilai negatif', SourceType::Manual);
        $draft->debit(AccountCode::Cash, Money::of(-1000), $this->ajaib);
        $draft->debit(AccountCode::PaidInCapital, Money::of(1000));

        $this->assertTrue($draft->isBalanced());
        $this->assertMoneyEquals('1000.00', $draft->totalDebit());
        $this->assertMoneyEquals('1000.00', $draft->totalCredit());

        foreach ($draft->lines() as $line) {
            $this->assertFalse($line->amount->isNegative(), 'Tidak boleh ada baris bernilai negatif.');
        }
    }

    /**
     * Nomor jurnal harus urut dan unik per bulan.
     */
    public function testJournalNumbersAreSequentialWithinAMonth(): void
    {
        for ($i = 0; $i < 3; $i++) {
            service('cashTransactions')->topUp([
                'transaction_date' => '2026-01-1' . $i, 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
            ]);
        }

        $numbers = array_column(
            $this->db->table('journal_entries')->select('entry_number')->orderBy('id')->get()->getResultArray(),
            'entry_number'
        );

        $this->assertSame(['JV-202601-0001', 'JV-202601-0002', 'JV-202601-0003'], $numbers);
    }

    /**
     * Buku besar dapat difilter per sekuritas — inti dari keputusan memakai
     * dimensi alih-alih memecah Chart of Accounts.
     */
    public function testLedgerCanBeFilteredBySecuritiesAndTicker(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 50_000_000,
        ]);
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ipot, 'amount' => 20_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);

        $lines = new JournalLineModel();

        $ajaibLines = $lines->ledgerQuery(['securities_account_id' => $this->ajaib])->get()->getResultArray();
        $ipotLines  = $lines->ledgerQuery(['securities_account_id' => $this->ipot])->get()->getResultArray();
        $bbcaLines  = $lines->ledgerQuery(['stock_id' => $this->bbca])->get()->getResultArray();

        $this->assertGreaterThan(count($ipotLines), count($ajaibLines));
        $this->assertCount(1, $bbcaLines, 'Hanya baris portofolio yang membawa dimensi saham.');
        $this->assertSame('BBCA', $bbcaLines[0]['ticker']);

        // Saldo kas per sekuritas dihitung dari dimensi, bukan dari akun terpisah.
        $accountId = (new \App\Models\AccountModel())->idFor(AccountCode::Cash);
        $balances  = $lines->cashBalanceByAccount($accountId);

        $this->assertMoneyEquals('42000000.00', Money::of($balances[$this->ajaib]));
        $this->assertMoneyEquals('20000000.00', Money::of($balances[$this->ipot]));
    }
}
