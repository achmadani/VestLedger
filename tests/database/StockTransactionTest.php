<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Exceptions\BusinessRuleException;
use App\Models\StockPositionModel;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Pembelian & penjualan saham (§10, §11, §12, §37).
 *
 * @internal
 */
final class StockTransactionTest extends EngineTestCase
{
    private function fund(int $accountId, int $amount = 500_000_000): void
    {
        service('cashTransactions')->topUp([
            'transaction_date'      => '2026-01-02',
            'securities_account_id' => $accountId,
            'amount'                => $amount,
        ]);
    }

    // ---------------------------------------------------------------- BELI

    /**
     * Contoh terhitung §12, angka demi angka.
     */
    public function testWeightedAverageCostMatchesTheSpecWorkedExample(): void
    {
        $this->fund($this->ajaib);

        // Buy 1: 1.000 lembar @ Rp1.000, fee Rp2.000 -> book cost Rp1.002.000
        service('stockTransactions')->buy([
            'transaction_date'      => '2026-01-05',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity'              => 1000,
            'price'                 => 1000,
            'broker_fee'            => 2000,
        ]);

        $position = $this->position($this->ajaib, $this->bbca);
        $this->assertSame(1000, $position->quantity);
        $this->assertMoneyEquals('1002000.00', $position->bookValue());

        // Buy 2: 2.000 lembar @ Rp1.100, fee Rp4.000 -> book cost Rp2.204.000
        service('stockTransactions')->buy([
            'transaction_date'      => '2026-01-06',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity'              => 2000,
            'price'                 => 1100,
            'broker_fee'            => 4000,
        ]);

        $position = $this->position($this->ajaib, $this->bbca);

        $this->assertSame(3000, $position->quantity, 'Total quantity');
        $this->assertMoneyEquals('3206000.00', $position->bookValue(), 'Total book cost');
        $this->assertSame('1068.6667', $position->averageCost()->toDecimalString(), 'Average cost');
        $this->assertSame('1.068,67', fmt_avg_cost($position->averageCost()->toFloat()));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * §10 & keputusan kapitalisasi: pembelian tidak pernah menyentuh akun beban.
     */
    public function testBuyCapitalisesEveryChargeAndTouchesNoExpenseAccount(): void
    {
        $this->fund($this->ajaib);

        service('stockTransactions')->buy([
            'transaction_date'      => '2026-01-05',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity'              => 1000,
            'price'                 => 10_000,
            'broker_fee'            => 20_000,
            'tax'                   => 5_000,
            'levy'                  => 3_000,
        ]);

        // Rp10.000.000 + 20.000 + 5.000 + 3.000
        $this->assertMoneyEquals('10028000.00', $this->position($this->ajaib, $this->bbca)->bookValue());
        $this->assertMoneyEquals('10028000.00', $this->accountBalance(AccountCode::StockPortfolio));

        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::BrokerFee), 'Fee beli tidak boleh menjadi beban');
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::TaxAndLevy), 'Pajak beli tidak boleh menjadi beban');

        $this->assertEveryJournalBalanced();
    }

    /**
     * Contoh jurnal §10: Dr Stock Portfolio / Cr Cash sebesar book cost.
     */
    public function testBuyJournalDebitsPortfolioAndCreditsCash(): void
    {
        $this->fund($this->ajaib);

        service('stockTransactions')->buy([
            'transaction_date'      => '2026-01-05',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity'              => 1000,
            'price'                 => 10_000,
            'broker_fee'            => 20_000,
        ]);

        $this->assertMoneyEquals('10020000.00', $this->accountBalance(AccountCode::StockPortfolio));
        $this->assertMoneyEquals('489980000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
    }

    // ---------------------------------------------------------------- JUAL

    public function testSellAtProfitProducesTheExpectedJournalAndRealizedFigures(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000, 'broker_fee' => 20_000,
        ]);

        $sale = service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 9_000,
            'broker_fee' => 15_000, 'levy' => 5_000,
        ]);

        // Book value yang dilepas = 80.020.000 x 5.000/10.000
        $this->assertMoneyEquals('40010000.00', $sale->bookValueSold());
        // Realized gain yang masuk akun 4000 = gross - book value sold
        $this->assertMoneyEquals('4990000.00', $sale->realizedGainGross());
        // Realized G/L versi laporan §11 Step 3 = gross - book - fee - pajak/levy
        $this->assertMoneyEquals('4970000.00', $sale->realizedGainNet());

        $this->assertMoneyEquals('4990000.00', $this->accountBalance(AccountCode::RealizedGain));
        $this->assertMoneyEquals('15000.00', $this->accountBalance(AccountCode::BrokerFee), 'Fee jual menjadi beban');
        // Levy jual 5.000 + bea materai 2 x 10.000 (hari beli Rp80jt dan hari
        // jual Rp45jt, keduanya melewati ambang Rp10 juta).
        $this->assertMoneyEquals('25000.00', $this->accountBalance(AccountCode::TaxAndLevy));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::RealizedLoss));

        // Posisi tersisa separuh, book value tersisa separuh.
        $position = $this->position($this->ajaib, $this->bbca);
        $this->assertSame(5_000, $position->quantity);
        $this->assertMoneyEquals('40010000.00', $position->bookValue());

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    public function testSellAtLossDebitsRealizedLossInsteadOfCreditingGain(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000, 'broker_fee' => 20_000,
        ]);

        $sale = service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 7_000, 'broker_fee' => 10_000,
        ]);

        $this->assertMoneyEquals('-5010000.00', $sale->realizedGainGross());
        $this->assertMoneyEquals('5010000.00', $this->accountBalance(AccountCode::RealizedLoss));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::RealizedGain), 'Rugi tidak boleh dicatat sebagai laba negatif');

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Menjual habis harus mengosongkan posisi DAN akun 1100, tanpa sisa sen.
     */
    public function testSellingEverythingLeavesNoResidualBookValue(): void
    {
        $this->fund($this->ajaib);

        // Angka yang sengaja tidak habis dibagi.
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 3_000, 'price' => 1_000, 'broker_fee' => 2_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-06', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 7_000, 'price' => 1_100, 'broker_fee' => 4_000,
        ]);

        foreach ([3_333, 3_333, 3_334] as $index => $quantity) {
            service('stockTransactions')->sell([
                'transaction_date' => '2026-02-1' . $index, 'securities_account_id' => $this->ajaib,
                'stock_id' => $this->bbca, 'quantity' => $quantity, 'price' => 1_200, 'broker_fee' => 1_000,
            ]);
        }

        $position = $this->position($this->ajaib, $this->bbca);

        $this->assertSame(0, $position->quantity);
        $this->assertMoneyEquals('0.00', $position->bookValue(), 'Book value harus benar-benar nol');
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::StockPortfolio), 'Akun 1100 harus benar-benar nol');

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * §27: sell quantity <= current quantity.
     */
    public function testSellingMoreThanHeldIsRejectedAndNothingIsWritten(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 1_000, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);

        $journalsBefore = $this->db->table('journal_entries')->countAllResults();

        try {
            service('stockTransactions')->sell([
                'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
                'stock_id' => $this->bbca, 'quantity' => 1_001, 'price' => 1_200, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);
            $this->fail('Penjualan melebihi kepemilikan seharusnya ditolak.');
        } catch (BusinessRuleException $e) {
            $this->assertMatchesRegularExpression('/melebihi kepemilikan/', $e->getMessage());
        }

        $this->assertSame(1000, $this->position($this->ajaib, $this->bbca)->quantity);
        $this->assertSame($journalsBefore, $this->db->table('journal_entries')->countAllResults());
        $this->assertSame(1, $this->db->table('stock_transactions')->countAllResults());
    }

    public function testSellingFromAnAccountThatHoldsNothingIsRejected(): void
    {
        $this->fund($this->ipot);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/melebihi kepemilikan/');

        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ipot,
            'stock_id' => $this->bbca, 'quantity' => 100, 'price' => 1_200, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);
    }

    // ------------------------------------------------------- Multi sekuritas

    /**
     * §5: saham yang sama pada sekuritas berbeda memiliki average cost sendiri-sendiri,
     * dan book cost antar sekuritas tidak boleh tercampur.
     */
    public function testAverageCostIsKeptSeparatePerSecuritiesAccount(): void
    {
        $this->fund($this->ajaib);
        $this->fund($this->ipot);

        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-06', 'securities_account_id' => $this->ipot,
            'stock_id' => $this->bbca, 'quantity' => 2_000, 'price' => 9_000, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);

        $this->assertSame('8000.0000', $this->position($this->ajaib, $this->bbca)->averageCost()->toDecimalString());
        $this->assertSame('9000.0000', $this->position($this->ipot, $this->bbca)->averageCost()->toDecimalString());

        // Total per ticker menjumlahkan lintas sekuritas (§5).
        $totals = (new StockPositionModel())->totalsByTicker();
        $this->assertCount(1, $totals);
        $this->assertSame('3000', (string) $totals[0]['quantity']);
        $this->assertMoneyEquals('26000000.00', Money::of((string) $totals[0]['book_value']));
    }

    /**
     * Menjual di satu sekuritas tidak boleh menyentuh posisi di sekuritas lain.
     */
    public function testSellingInOneAccountLeavesTheOtherAccountUntouched(): void
    {
        $this->fund($this->ajaib);
        $this->fund($this->ipot);

        foreach ([$this->ajaib, $this->ipot] as $account) {
            service('stockTransactions')->buy([
                'transaction_date' => '2026-01-05', 'securities_account_id' => $account,
                'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);
        }

        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 9_000, 'broker_fee' => 0, 'tax' => 0, 'levy' => 0]);

        $this->assertSame(0, $this->position($this->ajaib, $this->bbca)->quantity);
        $this->assertSame(1_000, $this->position($this->ipot, $this->bbca)->quantity);
        $this->assertMoneyEquals('8000000.00', $this->position($this->ipot, $this->bbca)->bookValue());
    }

    // ---------------------------------------------------------- Invariant

    /**
     * Invariant terpenting mesin portofolio: saldo akun 1100 di buku besar harus
     * selalu sama dengan jumlah book_value seluruh posisi. Bila keduanya
     * berbeda, salah satu dari neraca atau portofolio pasti berbohong.
     */
    public function testPortfolioAccountAlwaysEqualsSumOfPositionBookValues(): void
    {
        $this->fund($this->ajaib);
        $this->fund($this->ipot);

        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_234, 'price' => 8_137, 'broker_fee' => 1_234,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-07', 'securities_account_id' => $this->ipot,
            'stock_id' => $this->bbri, 'quantity' => 999, 'price' => 4_321, 'broker_fee' => 777, 'levy' => 13,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-11', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 411, 'price' => 8_900, 'broker_fee' => 555,
        ]);

        $ledger    = $this->accountBalance(AccountCode::StockPortfolio);
        $positions = Money::of((new StockPositionModel())->totalBookValue());

        $this->assertTrue(
            $ledger->equals($positions),
            sprintf('Akun 1100 = %s, total posisi = %s', $ledger->toDecimalString(), $positions->toDecimalString())
        );

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * §28: posisi adalah calculated state dan harus dapat dibangun ulang persis.
     */
    public function testPositionsCanBeRebuiltFromTransactionsAlone(): void
    {
        $this->fund($this->ajaib);

        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 3_000, 'price' => 1_000, 'broker_fee' => 2_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-06', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 7_000, 'price' => 1_100, 'broker_fee' => 4_000,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 2_500, 'price' => 1_200, 'broker_fee' => 1_000,
        ]);

        $before = $this->position($this->ajaib, $this->bbca);

        $result = service('positions')->rebuildAll();

        $after = $this->position($this->ajaib, $this->bbca);

        $this->assertSame($before->quantity, $after->quantity);
        $this->assertTrue(
            $before->bookValue()->equals($after->bookValue()),
            sprintf('Sebelum %s, sesudah %s', $before->bookValue()->toDecimalString(), $after->bookValue()->toDecimalString())
        );
        $this->assertSame(1, $result['positions']);
        $this->assertSame(3, $result['transactions']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        helper('format');
    }
}
