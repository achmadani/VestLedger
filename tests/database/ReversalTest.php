<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Enums\PostingStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashTransactionModel;
use App\Models\JournalEntryModel;
use App\Models\StockTransactionModel;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Pembatalan transaksi lewat jurnal pembalik (§26, §40.8).
 *
 * @internal
 */
final class ReversalTest extends EngineTestCase
{
    private function fund(int $accountId, int $amount = 500_000_000): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $accountId, 'amount' => $amount,
        ]);
    }

    /**
     * Pembatalan meniadakan dampak, tetapi tidak menghapus apa pun.
     */
    public function testReversingCashTransactionCancelsItsEffectWithoutDeletingAnything(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);

        $transaction = (new CashTransactionModel())->first();

        service('reversals')->reverseCash($transaction->id, null, 'Salah nominal');

        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::PaidInCapital));

        // Tidak ada yang dihapus: transaksi tetap ada, statusnya berubah.
        $this->assertSame(1, $this->db->table('cash_transactions')->countAllResults());
        $this->assertSame(
            PostingStatus::Reversed,
            (new CashTransactionModel())->find($transaction->id)->status()
        );

        // Jurnal asli tetap utuh, plus satu jurnal pembalik.
        $this->assertSame(2, $this->db->table('journal_entries')->countAllResults());

        $entries  = new JournalEntryModel();
        $original = $entries->find($transaction->journal_entry_id);
        $this->assertTrue($original->isReversed(), 'Jurnal asli ditandai reversed');

        $reversal = $entries->where('reverses_entry_id', $original->id)->first();
        $this->assertNotNull($reversal, 'Jurnal pembalik harus merujuk jurnal aslinya');
        $this->assertTrue($reversal->isReversal());

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Jurnal pembalik menukar sisi, bukan mencatat nilai negatif.
     */
    public function testReversalFlipsSidesRatherThanUsingNegativeAmounts(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);
        service('reversals')->reverseCash((new CashTransactionModel())->first()->id);

        $rows = $this->db->table('journal_lines')->get()->getResultArray();

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, (float) $row['debit'], 'Tidak boleh ada debit negatif');
            $this->assertGreaterThanOrEqual(0, (float) $row['credit'], 'Tidak boleh ada kredit negatif');
        }
    }

    public function testReversingStockPurchaseRestoresThePositionExactly(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000, 'broker_fee' => 20_000,
        ]);

        $cashBefore = $this->accountBalance(AccountCode::Cash, $this->ajaib);
        $this->assertSame(1_000, $this->position($this->ajaib, $this->bbca)->quantity);

        service('reversals')->reverseStock((new StockTransactionModel())->first()->id, null, 'Salah input');

        $position = $this->position($this->ajaib, $this->bbca);
        $this->assertSame(0, $position->quantity);
        $this->assertMoneyEquals('0.00', $position->bookValue());
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::StockPortfolio));

        // Kas kembali seperti sebelum pembelian.
        $this->assertMoneyEquals('500000000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertTrue($cashBefore->lessThan($this->accountBalance(AccountCode::Cash, $this->ajaib)));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    public function testReversingSaleRestoresPositionAndUndoesRealizedGain(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000, 'broker_fee' => 20_000,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 9_000, 'broker_fee' => 15_000,
        ]);

        $this->assertMoneyEquals('4990000.00', $this->accountBalance(AccountCode::RealizedGain));

        $sale = (new StockTransactionModel())->where('type', 'sell')->first();
        service('reversals')->reverseStock($sale->id);

        // Posisi kembali utuh...
        $position = $this->position($this->ajaib, $this->bbca);
        $this->assertSame(10_000, $position->quantity);
        $this->assertMoneyEquals('80020000.00', $position->bookValue());

        // ...dan laba realisasi ikut ditiadakan.
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::RealizedGain));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::BrokerFee));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Average cost bersifat berurutan, sehingga membatalkan transaksi lama akan
     * membuat realized gain transaksi sesudahnya menjadi salah.
     */
    public function testOnlyTheLatestTransactionOfAPositionCanBeReversed(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 9_000,
        ]);

        $purchase = (new StockTransactionModel())->where('type', 'buy')->first();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/bukan transaksi terakhir/');

        service('reversals')->reverseStock($purchase->id);
    }

    /**
     * Setelah penjualan dibatalkan, pembeliannya menjadi transaksi terakhir
     * dan boleh dibatalkan juga — sehingga koreksi berantai tetap mungkin.
     */
    public function testReversingInReverseChronologicalOrderIsAllowed(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 9_000,
        ]);

        $stocks = new StockTransactionModel();

        service('reversals')->reverseStock($stocks->where('type', 'sell')->first()->id);
        service('reversals')->reverseStock($stocks->where('type', 'buy')->first()->id);

        $position = $this->position($this->ajaib, $this->bbca);
        $this->assertSame(0, $position->quantity);
        $this->assertMoneyEquals('0.00', $position->bookValue());
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::StockPortfolio));
        $this->assertMoneyEquals('500000000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    public function testTransactionCannotBeReversedTwice(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);
        $id = (new CashTransactionModel())->first()->id;

        service('reversals')->reverseCash($id);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/sudah dibatalkan/');

        service('reversals')->reverseCash($id);
    }

    public function testReversalIsRecordedInTheAuditTrail(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);
        service('reversals')->reverseCash((new CashTransactionModel())->first()->id, null, 'Duplikat');

        $log = $this->db->table('audit_logs')->where('action', 'reversed')->get()->getRowArray();

        $this->assertNotNull($log);
        $this->assertSame('cash_transaction', $log['entity_type']);
        $this->assertStringContainsString('Duplikat', (string) $log['summary']);
    }

    /**
     * Rebuild posisi harus mengabaikan transaksi yang sudah dibatalkan.
     */
    public function testRebuildIgnoresReversedTransactions(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-06', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 2_000, 'price' => 9_000,
        ]);

        $latest = (new StockTransactionModel())->orderBy('id', 'desc')->first();
        service('reversals')->reverseStock($latest->id);

        $before = $this->position($this->ajaib, $this->bbca);
        service('positions')->rebuildAll();
        $after = $this->position($this->ajaib, $this->bbca);

        $this->assertSame(1_000, $after->quantity);
        $this->assertSame($before->quantity, $after->quantity);
        $this->assertTrue($before->bookValue()->equals($after->bookValue()));
        $this->assertMoneyEquals('8000000.00', $after->bookValue());
    }

    /**
     * Setelah semua transaksi dibatalkan, seluruh saldo kembali nol.
     */
    public function testFullyReversedBookReturnsEveryBalanceToZero(): void
    {
        $this->fund($this->ajaib, 100_000_000);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000, 'broker_fee' => 10_000,
        ]);
        service('dividendTransactions')->record([
            'transaction_date' => '2026-03-15', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity_eligible' => 1_000, 'dividend_per_share' => 50,
        ]);

        service('reversals')->reverseDividend((new \App\Models\DividendTransactionModel())->first()->id);
        service('reversals')->reverseStock((new StockTransactionModel())->first()->id);
        service('reversals')->reverseCash((new CashTransactionModel())->first()->id);

        foreach ([
            AccountCode::Cash, AccountCode::StockPortfolio, AccountCode::PaidInCapital,
            AccountCode::DividendIncome, AccountCode::BrokerFee, AccountCode::TaxAndLevy,
            AccountCode::RealizedGain, AccountCode::RealizedLoss, AccountCode::OwnerWithdrawal,
        ] as $code) {
            $this->assertMoneyEquals(
                '0.00',
                $this->accountBalance($code),
                'Akun ' . $code->value . ' seharusnya nol setelah semua transaksi dibatalkan'
            );
        }

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }
}
