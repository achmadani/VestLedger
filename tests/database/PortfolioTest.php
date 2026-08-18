<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Exceptions\BusinessRuleException;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Harga pasar, unrealized gain/loss, dan agregasi portofolio (§13, §14, §20, §22).
 *
 * @internal
 */
final class PortfolioTest extends EngineTestCase
{
    private function fund(int $accountId, int $amount = 500_000_000): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $accountId, 'amount' => $amount,
        ]);
    }

    /**
     * Contoh terhitung §13, angka demi angka.
     */
    public function testUnrealizedGainMatchesTheSpecWorkedExample(): void
    {
        $this->fund($this->ajaib);

        // 10.000 lembar @ Rp8.000 tanpa biaya -> average cost tepat Rp8.000
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000,
        ]);

        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_000,
        ]);

        $snapshot = service('portfolio')->snapshot('2026-06-30');
        $position = $snapshot['positions'][0];

        $this->assertSame(10_000, $position['quantity']);
        $this->assertSame('8000.0000', $position['average_cost']->toDecimalString());
        $this->assertMoneyEquals('80000000.00', $position['book_value']);
        $this->assertSame('9000.0000', $position['market_price']->toDecimalString());
        $this->assertMoneyEquals('90000000.00', $position['market_value']);
        $this->assertMoneyEquals('10000000.00', $position['unrealized']);
        $this->assertEqualsWithDelta(12.5, $position['return_pct'], 0.0001);
    }

    /**
     * §13 & §40.2: unrealized TIDAK masuk laba rugi periode berjalan.
     */
    public function testUnrealizedGainNeverEntersProfitAndLoss(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_000,
        ]);

        $totals = service('portfolio')->snapshot('2026-06-30')['totals'];

        $this->assertMoneyEquals('10000000.00', $totals['unrealized']);
        $this->assertMoneyEquals('0.00', $totals['net_profit'], 'Unrealized tidak boleh menaikkan laba periode berjalan');
        $this->assertMoneyEquals('0.00', $totals['realized_net']);
    }

    /**
     * §14: harga pasar tidak pernah menyentuh buku besar.
     */
    public function testRecordingMarketPriceCreatesNoJournalAndKeepsBookValueIntact(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);

        $journalsBefore = $this->db->table('journal_entries')->countAllResults();
        $bookBefore     = $this->position($this->ajaib, $this->bbca)->bookValue();

        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 12_000,
        ]);

        $this->assertSame($journalsBefore, $this->db->table('journal_entries')->countAllResults());
        $this->assertTrue($bookBefore->equals($this->position($this->ajaib, $this->bbca)->bookValue()));
        $this->assertEveryJournalBalanced();
    }

    public function testUnrealizedLossIsReportedAsNegative(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 7_000,
        ]);

        $position = service('portfolio')->snapshot('2026-06-30')['positions'][0];

        $this->assertMoneyEquals('-1000000.00', $position['unrealized']);
        $this->assertEqualsWithDelta(-12.5, $position['return_pct'], 0.0001);
    }

    /**
     * Posisi tanpa harga pasar TIDAK boleh dianggap unrealized-nya nol —
     * yang benar adalah "belum diketahui", dan itu harus terlihat.
     */
    public function testPositionWithoutPriceIsReportedSeparatelyRatherThanAssumedFlat(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-06', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbri, 'quantity' => 2_000, 'price' => 4_000,
        ]);

        // Hanya BBCA yang punya harga.
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_000,
        ]);

        $totals = service('portfolio')->snapshot('2026-06-30')['totals'];

        $this->assertSame(1, $totals['unpriced_count']);
        $this->assertMoneyEquals('8000000.00', $totals['unpriced_book_value'], 'Book value BBRI');
        $this->assertMoneyEquals('9000000.00', $totals['market_value'], 'Hanya BBCA yang bernilai pasar');
        $this->assertMoneyEquals('1000000.00', $totals['unrealized'], 'Unrealized hanya dari posisi berharga');

        $positions = collect_by_ticker(service('portfolio')->snapshot('2026-06-30')['positions']);
        $this->assertFalse($positions['BBRI']['has_price']);
        $this->assertNull($positions['BBRI']['unrealized']);
        $this->assertNull($positions['BBRI']['return_pct']);
    }

    /**
     * §14: yang dipakai adalah harga penutupan TERBARU pada atau sebelum tanggal laporan.
     */
    public function testLatestPriceOnOrBeforeTheReportingDateIsUsed(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);

        foreach ([['2026-03-01', 8_500], ['2026-04-01', 9_000], ['2026-07-01', 10_000]] as [$date, $price]) {
            service('marketPrices')->record(['stock_id' => $this->bbca, 'price_date' => $date, 'closing_price' => $price]);
        }

        // Per 30 Juni, harga terbaru adalah harga 1 April — bukan harga 1 Juli.
        $position = service('portfolio')->snapshot('2026-06-30')['positions'][0];
        $this->assertSame('9000.0000', $position['market_price']->toDecimalString());
        $this->assertSame('2026-04-01', $position['price_date']);

        $later = service('portfolio')->snapshot('2026-07-15')['positions'][0];
        $this->assertSame('10000.0000', $later['market_price']->toDecimalString());
    }

    public function testRecordingPriceTwiceForTheSameDayOverwritesInsteadOfDuplicating(): void
    {
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_000,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_150,
        ]);

        $this->assertSame(1, $this->db->table('market_prices')->countAllResults());
        $this->assertSame(
            '9150.0000',
            (new \App\Models\MarketPriceModel())->findForDate($this->bbca, '2026-06-30')->closingPrice()->toDecimalString()
        );
    }

    public function testFuturePriceIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/masa depan/');

        service('marketPrices')->record([
            'stock_id' => $this->bbca,
            'price_date' => date('Y-m-d', strtotime('+1 day')),
            'closing_price' => 9_000,
        ]);
    }

    public function testBulkEntrySkipsBlankRowsInsteadOfTreatingThemAsZero(): void
    {
        $result = service('marketPrices')->recordMany('2026-06-30', [
            $this->bbca => '9000',
            $this->bbri => '',
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $this->db->table('market_prices')->countAllResults());
    }

    // ------------------------------------------------------------ Agregasi

    /**
     * §5: total per ticker menjumlahkan lintas sekuritas, dengan rincian per sekuritas.
     */
    public function testTickerViewAggregatesAcrossSecuritiesWithBreakdown(): void
    {
        $this->fund($this->ajaib);
        $this->fund($this->ipot);

        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-06', 'securities_account_id' => $this->ipot,
            'stock_id' => $this->bbca, 'quantity' => 2_000, 'price' => 9_000,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 10_000,
        ]);

        $ticker = service('portfolio')->snapshot('2026-06-30')['by_ticker'][0];

        $this->assertSame('BBCA', $ticker['ticker']);
        $this->assertSame(3_000, $ticker['quantity']);
        $this->assertMoneyEquals('26000000.00', $ticker['book_value']);
        $this->assertMoneyEquals('30000000.00', $ticker['market_value']);
        $this->assertMoneyEquals('4000000.00', $ticker['unrealized']);

        // Average cost global = 26.000.000 / 3.000
        $this->assertSame('8666.6667', $ticker['average_cost']->toDecimalString());

        // Rincian per sekuritas tetap memakai average cost masing-masing.
        $this->assertCount(2, $ticker['breakdown']);
        $byCode = [];

        foreach ($ticker['breakdown'] as $row) {
            $byCode[$row['securities_code']] = $row;
        }

        $this->assertSame(1_000, $byCode['AJAIB']['quantity']);
        $this->assertSame('8000.0000', $byCode['AJAIB']['average_cost']->toDecimalString());
        $this->assertSame(2_000, $byCode['IPOT']['quantity']);
        $this->assertSame('9000.0000', $byCode['IPOT']['average_cost']->toDecimalString());
    }

    /**
     * §20: ringkasan per sekuritas, termasuk rekening yang hanya berisi kas.
     */
    public function testSecuritiesViewIncludesAccountsThatOnlyHoldCash(): void
    {
        $this->fund($this->ajaib, 100_000_000);
        $this->fund($this->ipot, 50_000_000);

        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_000,
        ]);

        $byCode = [];

        foreach (service('portfolio')->snapshot('2026-06-30')['by_securities'] as $row) {
            $byCode[$row['securities_code']] = $row;
        }

        $this->assertMoneyEquals('92000000.00', $byCode['AJAIB']['cash']);
        $this->assertMoneyEquals('9000000.00', $byCode['AJAIB']['market_value']);
        $this->assertMoneyEquals('101000000.00', $byCode['AJAIB']['net_worth']);

        // IPOT belum punya posisi tetapi kasnya harus tetap terlihat.
        $this->assertSame(0, $byCode['IPOT']['holdings']);
        $this->assertMoneyEquals('50000000.00', $byCode['IPOT']['cash']);
        $this->assertMoneyEquals('50000000.00', $byCode['IPOT']['net_worth']);
    }

    /**
     * Net worth global = kas + market value (+ book value posisi yang belum berharga).
     */
    public function testGlobalNetWorthCombinesCashAndMarketValue(): void
    {
        $this->fund($this->ajaib, 100_000_000);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_000,
        ]);

        $totals = service('portfolio')->snapshot('2026-06-30')['totals'];

        $this->assertMoneyEquals('92000000.00', $totals['cash']);
        $this->assertMoneyEquals('9000000.00', $totals['market_value']);
        $this->assertMoneyEquals('101000000.00', $totals['net_worth']);
    }

    // ------------------------------------------------------- Kas negatif

    /**
     * Pembelian melebihi saldo kas TIDAK diblokir.
     *
     * Aplikasi ini dipakai untuk pencatatan dan transaksi kerap dimasukkan
     * mundur, sehingga saldo bisa tampak negatif hanya karena top up-nya belum
     * sempat dicatat. Memblokirnya akan membuat pencatatan mundur mustahil.
     */
    public function testBuyingBeyondAvailableCashIsRecordedRatherThanBlocked(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);

        $transaction = service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);

        $this->assertNotNull($transaction->id, 'Transaksi harus tetap tercatat.');
        $this->assertSame(1_000, $this->position($this->ajaib, $this->bbca)->quantity);

        // Buku besar tetap benar meskipun kasnya negatif.
        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Saldo negatif tidak diblokir, tetapi HARUS terdeteksi dan dilaporkan.
     */
    public function testNegativeCashIsDetectedAndReported(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);

        $negative = service('portfolio')->negativeCashAccounts('2026-06-30');

        $this->assertCount(1, $negative);
        $this->assertSame('AJAIB', $negative[0]['securities_code']);
        $this->assertMoneyEquals('-7000000.00', $negative[0]['balance']);

        // Ikut terbawa ke potret portofolio, sehingga tiap halaman dapat menampilkannya.
        $this->assertCount(1, service('portfolio')->snapshot('2026-06-30')['totals']['negative_cash']);
    }

    /**
     * Mencatat top up yang tertinggal secara MUNDUR harus memulihkan saldo —
     * inilah alasan pembelian di atas tidak boleh diblokir.
     */
    public function testBackdatedTopUpClearsTheNegativeCashWarning(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);

        $this->assertCount(1, service('portfolio')->negativeCashAccounts('2026-06-30'));

        // Top up yang sebenarnya terjadi SEBELUM pembelian, baru dicatat sekarang.
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-03', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);

        $this->assertSame([], service('portfolio')->negativeCashAccounts('2026-06-30'));
        $this->assertEveryJournalBalanced();
    }

    public function testAccountsWithHealthyCashAreNotFlagged(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 50_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 1_000, 'price' => 8_000,
        ]);

        $this->assertSame([], service('portfolio')->negativeCashAccounts('2026-06-30'));
    }

    /**
     * Realized gain dan dividen MASUK laba periode berjalan; unrealized tidak (§40.2).
     */
    public function testNetProfitCountsRealizedAndDividendButNotUnrealized(): void
    {
        $this->fund($this->ajaib);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 9_000, 'broker_fee' => 15_000,
        ]);
        service('dividendTransactions')->record([
            'transaction_date' => '2026-03-15', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity_eligible' => 5_000, 'dividend_per_share' => 100,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 20_000,
        ]);

        $totals = service('portfolio')->snapshot('2026-06-30')['totals'];

        // Realized 5.000.000, dividen 500.000, fee 15.000 -> 5.485.000
        $this->assertMoneyEquals('5000000.00', $totals['realized_net']);
        $this->assertMoneyEquals('500000.00', $totals['dividend_income']);
        $this->assertMoneyEquals('15000.00', $totals['broker_fee']);
        $this->assertMoneyEquals('5485000.00', $totals['net_profit']);

        // Unrealized besar sekali, tetapi tidak menyentuh laba periode berjalan.
        $this->assertTrue($totals['unrealized']->greaterThan(Money::of('50000000')));
    }
}

/**
 * @param list<array<string, mixed>> $positions
 *
 * @return array<string, array<string, mixed>>
 */
function collect_by_ticker(array $positions): array
{
    $byTicker = [];

    foreach ($positions as $row) {
        $byTicker[$row['ticker']] = $row;
    }

    return $byTicker;
}
