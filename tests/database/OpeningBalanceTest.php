<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Exceptions\BusinessRuleException;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Saldo awal (§19, §37).
 *
 * @internal
 */
final class OpeningBalanceTest extends EngineTestCase
{
    /**
     * Contoh §19: Ajaib, BBCA, 2.000 lembar, avg cost Rp8.200, book value Rp16.400.000.
     */
    private function saveSpecExample(string $capital = '20000000'): void
    {
        service('openingBalance')->save([
            'as_of_date'      => '2026-01-01',
            'cash'            => [$this->ajaib => '5000000'],
            'positions'       => [[
                'securities_account_id' => $this->ajaib,
                'stock_id'              => $this->bbca,
                'quantity'              => 2_000,
                'book_value'            => '16400000',
            ]],
            'paid_in_capital' => $capital,
        ]);
    }

    public function testOpeningBalanceCreatesPositionWithCorrectAverageCost(): void
    {
        $this->saveSpecExample();

        $position = $this->position($this->ajaib, $this->bbca);

        $this->assertSame(2_000, $position->quantity);
        $this->assertMoneyEquals('16400000.00', $position->bookValue());
        $this->assertSame('8200.0000', $position->averageCost()->toDecimalString());
    }

    /**
     * §19: saldo awal harus menghasilkan accounting state yang balance.
     */
    public function testOpeningBalanceProducesABalancedAccountingState(): void
    {
        $this->saveSpecExample();

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
        $this->assertTrue(service('financialStatements')->balanceSheet('2026-01-01')['balanced']);
    }

    public function testLedgerAccountsMatchTheOpeningInputs(): void
    {
        $this->saveSpecExample();

        $this->assertMoneyEquals('5000000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertMoneyEquals('16400000.00', $this->accountBalance(AccountCode::StockPortfolio));
        $this->assertMoneyEquals('20000000.00', $this->accountBalance(AccountCode::PaidInCapital));
        // Laba ditahan = aset 21.400.000 - modal 20.000.000
        $this->assertMoneyEquals('1400000.00', $this->accountBalance(AccountCode::RetainedEarnings));
    }

    /**
     * Modal lebih besar daripada aset berarti akumulasi RUGI; itu sah dan
     * harus tercatat sebagai laba ditahan negatif, bukan ditolak.
     */
    public function testCapitalExceedingAssetsBecomesNegativeRetainedEarnings(): void
    {
        $this->saveSpecExample('30000000');

        // Aset 21.400.000 - modal 30.000.000 = -8.600.000
        $this->assertMoneyEquals('-8600000.00', $this->accountBalance(AccountCode::RetainedEarnings));
        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Nilai negatif harus dibalik ke sisi debit, bukan dicatat sebagai kredit negatif.
     */
    public function testNegativeRetainedEarningsIsRecordedOnTheDebitSide(): void
    {
        $this->saveSpecExample('30000000');

        $rows = $this->db->table('journal_lines')->get()->getResultArray();

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, (float) $row['debit']);
            $this->assertGreaterThanOrEqual(0, (float) $row['credit']);
        }
    }

    /**
     * Setelah saldo awal, transaksi berikutnya memakai average cost awal.
     */
    public function testSellingAfterOpeningBalanceUsesTheOpeningAverageCost(): void
    {
        $this->saveSpecExample();

        $sale = service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 9_000, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);

        // Book value dilepas = 16.400.000 x 1.000/2.000
        $this->assertMoneyEquals('8200000.00', $sale->bookValueSold());
        // Realized = 9.000.000 - 8.200.000
        $this->assertMoneyEquals('800000.00', $sale->realizedGainGross());

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    // ------------------------------------------------------------- Penjagaan

    public function testOpeningBalanceCannotBeCreatedTwice(): void
    {
        $this->saveSpecExample();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/sudah pernah dibuat/');

        $this->saveSpecExample();
    }

    /**
     * Saldo awal harus mendahului seluruh transaksi; jika tidak, ia bukan
     * saldo AWAL.
     */
    public function testOpeningBalanceDatedAfterAnExistingTransactionIsRejected(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/harus mendahului/');

        service('openingBalance')->save([
            'as_of_date'      => '2026-01-31',
            'cash'            => [$this->ajaib => '5000000'],
            'paid_in_capital' => '5000000',
        ]);
    }

    public function testEmptyOpeningBalanceIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/kosong/');

        service('openingBalance')->save(['as_of_date' => '2026-01-01']);
    }

    /**
     * Posisi dengan lembar tetapi tanpa book value akan menghasilkan average
     * cost nol, yang membuat seluruh penjualan berikutnya tampak untung penuh.
     */
    public function testPositionWithQuantityButNoBookValueIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/average cost-nya akan menjadi nol/');

        service('openingBalance')->save([
            'as_of_date'      => '2026-01-01',
            'positions'       => [[
                'securities_account_id' => $this->ajaib,
                'stock_id'              => $this->bbca,
                'quantity'              => 1_000,
                'book_value'            => 0,
            ]],
            'paid_in_capital' => '1000000',
        ]);
    }

    // ------------------------------------------------------------ Penghapusan

    public function testResetReversesTheJournalAndClearsPositions(): void
    {
        $this->saveSpecExample();

        service('openingBalance')->reset();

        $this->assertSame(0, $this->position($this->ajaib, $this->bbca)->quantity);
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::PaidInCapital));

        // Jurnal asli tetap ada, ditambah jurnal pembaliknya.
        $this->assertSame(2, $this->db->table('journal_entries')->countAllResults());
        $this->assertEveryJournalBalanced();
    }

    /**
     * Begitu ada transaksi yang dibangun di atas posisi awal, menghapusnya akan
     * membuat realized gain/loss yang sudah tercatat menjadi salah.
     */
    public function testResetIsRefusedOnceTransactionsExist(): void
    {
        $this->saveSpecExample();

        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 500, 'price' => 9_000, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/sudah ada transaksi/');

        service('openingBalance')->reset();
    }

    /**
     * Saldo awal harus tampak di laporan seperti transaksi lain.
     */
    public function testOpeningBalanceAppearsInReports(): void
    {
        $this->saveSpecExample();

        $bs = service('financialStatements')->balanceSheet('2026-01-01');
        $this->assertMoneyEquals('21400000.00', $bs['total_assets']);

        // Saldo awal bukan pendapatan maupun beban.
        $is = service('financialStatements')->incomeStatement('2026-01-01', '2026-12-31');
        $this->assertMoneyEquals('0.00', $is['total_revenue']);
        $this->assertMoneyEquals('0.00', $is['total_expense']);

        // Posisi historis ikut terbaca.
        $snapshot = service('portfolio')->snapshot('2026-01-31');
        $this->assertSame(2_000, $snapshot['positions'][0]['quantity']);
    }

    public function testPreviewComputesRetainedEarningsWithoutSaving(): void
    {
        $preview = service('openingBalance')->preview([
            'cash'            => [$this->ajaib => '5000000'],
            'positions'       => [['quantity' => 2_000, 'book_value' => '16400000']],
            'paid_in_capital' => '20000000',
        ]);

        $this->assertMoneyEquals('21400000.00', $preview['assets']);
        $this->assertMoneyEquals('1400000.00', $preview['retained']);
        $this->assertSame(0, $this->db->table('opening_balances')->countAllResults());
    }
}
