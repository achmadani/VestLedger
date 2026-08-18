<?php

declare(strict_types=1);

namespace Tests\Database;

use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Laporan keuangan dan laporan periodik (§21, §23, §24, §37).
 *
 * @internal
 */
final class ReportingTest extends EngineTestCase
{
    /**
     * Skenario bersama: satu tahun aktivitas yang angkanya mudah ditelusuri.
     */
    private function buildYear(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 100_000_000,
        ]);
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-02-02', 'securities_account_id' => $this->ipot, 'amount' => 50_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000, 'broker_fee' => 20_000,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-03-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 9_000, 'broker_fee' => 15_000,
        ]);
        service('dividendTransactions')->record([
            'transaction_date' => '2026-04-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity_eligible' => 5_000, 'dividend_per_share' => 100, 'tax' => 50_000,
        ]);
        service('cashTransactions')->withdraw([
            'transaction_date' => '2026-05-10', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);
        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 9_500,
        ]);
    }

    // -------------------------------------------------------- Neraca Saldo

    public function testTrialBalanceIsBalanced(): void
    {
        $this->buildYear();

        $tb = service('financialStatements')->trialBalance();

        $this->assertTrue($tb['balanced'], sprintf(
            'Debit %s vs Kredit %s',
            $tb['total_debit']->toDecimalString(),
            $tb['total_credit']->toDecimalString()
        ));
        $this->assertNotSame([], $tb['rows']);
    }

    // -------------------------------------------------------------- Neraca

    /**
     * §21.1 & §40.10: Aset = Kewajiban + Ekuitas, selalu.
     */
    public function testBalanceSheetAlwaysBalances(): void
    {
        $this->buildYear();

        foreach (['2026-01-31', '2026-03-31', '2026-06-30', '2026-12-31'] as $date) {
            $bs = service('financialStatements')->balanceSheet($date);

            $this->assertTrue($bs['balanced'], sprintf(
                'Neraca per %s tidak balance: aset %s vs kewajiban+ekuitas %s',
                $date,
                $bs['total_assets']->toDecimalString(),
                $bs['total_liabilities_equity']->toDecimalString()
            ));
        }
    }

    public function testBalanceSheetAssetsMatchCashPlusPortfolio(): void
    {
        $this->buildYear();

        $bs = service('financialStatements')->balanceSheet('2026-06-30');

        $byCode = [];

        foreach ($bs['assets'] as $row) {
            $byCode[$row['code']] = $row['amount'];
        }

        // Kas 100jt - 80.020.000 + 44.985.000 + 450.000 - 10jt + 50jt = 105.415.000
        $this->assertMoneyEquals('105415000.00', $byCode['1000']);
        // Portofolio: 80.020.000 - 40.010.000
        $this->assertMoneyEquals('40010000.00', $byCode['1100']);
        $this->assertMoneyEquals('145425000.00', $bs['total_assets']);
    }

    /**
     * Withdrawal muncul sebagai pengurang ekuitas, bukan beban (§17, §40.4).
     */
    public function testWithdrawalAppearsInEquityNotExpenses(): void
    {
        $this->buildYear();

        $bs = service('financialStatements')->balanceSheet('2026-12-31');
        $is = service('financialStatements')->incomeStatement('2026-01-01', '2026-12-31');

        $equityCodes = array_column($bs['equity'], 'code');
        $this->assertContains('3200', $equityCodes, 'Penarikan pemilik harus tampil di ekuitas.');

        $expenseCodes = array_column($is['expenses'], 'code');
        $this->assertNotContains('3200', $expenseCodes, 'Penarikan pemilik tidak boleh tampil sebagai beban.');
    }

    // ----------------------------------------------------------- Laba Rugi

    public function testIncomeStatementMatchesLedgerAndExcludesUnrealized(): void
    {
        $this->buildYear();

        $is = service('financialStatements')->incomeStatement('2026-01-01', '2026-12-31');

        // Pendapatan: realized gain 4.990.000 + dividen bruto 500.000.
        // Realized = gross 45.000.000 - book value dilepas 40.010.000, dan book
        // value itu separuh dari 80.020.000 yang sudah termasuk fee pembelian.
        $this->assertMoneyEquals('5490000.00', $is['total_revenue']);
        // Beban: fee jual 15.000 + pajak dividen 50.000
        $this->assertMoneyEquals('65000.00', $is['total_expense']);
        $this->assertMoneyEquals('5425000.00', $is['net_profit']);

        // Unrealized tidak boleh muncul sama sekali di Laba Rugi (§13, §40.2).
        $codes = array_merge(array_column($is['revenue'], 'code'), array_column($is['expenses'], 'code'));

        foreach ($codes as $code) {
            $this->assertNotSame('1100', $code, 'Akun portofolio tidak boleh muncul di Laba Rugi.');
        }

        $unrealized = service('portfolio')->snapshot('2026-06-30')['totals']['unrealized'];
        $this->assertTrue($unrealized->isPositive(), 'Skenario ini seharusnya punya unrealized gain...');
        $this->assertMoneyEquals('5425000.00', $is['net_profit'], '...yang tetap tidak mengubah laba.');
    }

    /**
     * Fee pembelian dikapitalisasi, jadi tidak boleh muncul sebagai beban.
     */
    public function testPurchaseFeeNeverAppearsAsExpense(): void
    {
        $this->buildYear();

        $is = service('financialStatements')->incomeStatement('2026-01-01', '2026-01-31');

        // Januari hanya berisi top up dan pembelian: tidak ada pendapatan
        // maupun beban sama sekali.
        $this->assertMoneyEquals('0.00', $is['total_revenue']);
        $this->assertMoneyEquals('0.00', $is['total_expense']);
    }

    // ------------------------------------------------------------ Arus Kas

    /**
     * §21.3: arus kas harus merekonsiliasi saldo awal ke saldo akhir.
     */
    public function testCashFlowReconcilesBeginningToEndingCash(): void
    {
        $this->buildYear();

        $cf = service('financialStatements')->cashFlow('2026-01-01', '2026-12-31');

        $this->assertMoneyEquals('0.00', $cf['beginning']);
        $this->assertMoneyEquals('105415000.00', $cf['ending']);
        $this->assertTrue(
            $cf['beginning']->add($cf['net_change'])->equals($cf['ending']),
            'Saldo awal + perubahan harus sama dengan saldo akhir.'
        );
    }

    public function testCashFlowClassifiesActivitiesCorrectly(): void
    {
        $this->buildYear();

        $cf = service('financialStatements')->cashFlow('2026-01-01', '2026-12-31');

        // Pendanaan: top up 150jt - withdrawal 10jt
        $this->assertMoneyEquals('140000000.00', $cf['sections']['financing']['total']);
        // Investasi: -80.020.000 beli + 44.985.000 jual
        $this->assertMoneyEquals('-35035000.00', $cf['sections']['investing']['total']);
        // Operasi: dividen netto 450.000
        $this->assertMoneyEquals('450000.00', $cf['sections']['operating']['total']);
    }

    /**
     * §18: transfer internal tidak boleh muncul di arus kas — tidak ada uang
     * yang benar-benar masuk atau keluar.
     */
    public function testInternalTransferDoesNotAppearInCashFlow(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);
        service('cashTransactions')->transfer([
            'transaction_date'       => '2026-01-10',
            'securities_account_id'  => $this->ajaib,
            'counterpart_account_id' => $this->ipot,
            'amount'                 => 4_000_000,
        ]);

        $cf = service('financialStatements')->cashFlow('2026-01-01', '2026-01-31');

        $this->assertMoneyEquals('10000000.00', $cf['net_change'], 'Hanya top up yang menggerakkan kas.');
        $this->assertCount(1, $cf['sections']['financing']['items']);
        $this->assertSame([], $cf['sections']['operating']['items']);
        $this->assertSame([], $cf['sections']['investing']['items']);
    }

    // ------------------------------------------------------------- Bulanan

    public function testMonthlyReportFiguresAndComparison(): void
    {
        $this->buildYear();

        $march = service('periodicReports')->monthly(2026, 3);

        // Maret: satu penjualan.
        $this->assertMoneyEquals('44985000.00', $march['current']['sell']);
        $this->assertSame(1, $march['current']['sell_count']);
        $this->assertMoneyEquals('4990000.00', $march['current']['realized_net']);
        $this->assertMoneyEquals('15000.00', $march['current']['broker_fee']);

        // Februari: hanya top up 50jt, tidak ada penjualan.
        $this->assertMoneyEquals('0.00', $march['previous']['sell']);
        $this->assertMoneyEquals('50000000.00', $march['previous']['top_up']);

        // Perbandingan Maret vs Februari.
        $this->assertMoneyEquals('44985000.00', $march['comparison']['sell']['change']);
        $this->assertSame('Maret 2026', $march['label']);
        $this->assertSame('Februari 2026', $march['prev_label']);
    }

    /**
     * Saldo akhir kas satu bulan harus sama dengan saldo awal bulan berikutnya.
     */
    public function testMonthEndCashCarriesIntoTheNextMonth(): void
    {
        $this->buildYear();

        for ($month = 1; $month <= 11; $month++) {
            $current = service('periodicReports')->monthly(2026, $month)['current'];
            $next    = service('periodicReports')->monthly(2026, $month + 1)['current'];

            $this->assertTrue(
                $current['ending_cash']->equals($next['beginning_cash']),
                sprintf(
                    'Saldo akhir bulan %d (%s) tidak sama dengan saldo awal bulan %d (%s).',
                    $month,
                    $current['ending_cash']->toDecimalString(),
                    $month + 1,
                    $next['beginning_cash']->toDecimalString()
                )
            );
        }
    }

    /**
     * Perbandingan terhadap periode bernilai nol tidak boleh memaksakan persentase.
     */
    public function testComparisonAgainstZeroPreviousPeriodReturnsNullPercent(): void
    {
        $this->buildYear();

        $march = service('periodicReports')->monthly(2026, 3);

        $this->assertNull($march['comparison']['sell']['change_pct']);
    }

    // ------------------------------------------------------------- Tahunan

    public function testYearlyTotalsEqualTheSumOfItsMonths(): void
    {
        $this->buildYear();

        $yearly = service('periodicReports')->yearly(2026);

        foreach (['top_up', 'withdrawal', 'buy', 'sell', 'dividend_net', 'realized_net', 'total_fees'] as $field) {
            $sum = Money::zero();

            foreach ($yearly['months'] as $month) {
                $sum = $sum->add($month['figures'][$field]);
            }

            $this->assertTrue(
                $sum->equals($yearly['total'][$field]),
                sprintf(
                    'Total tahunan %s (%s) tidak sama dengan jumlah bulanannya (%s).',
                    $field,
                    $yearly['total'][$field]->toDecimalString(),
                    $sum->toDecimalString()
                )
            );
        }
    }

    public function testYearlyReportHasTwelveMonths(): void
    {
        $this->buildYear();

        $yearly = service('periodicReports')->yearly(2026);

        $this->assertCount(12, $yearly['months']);
        $this->assertSame('Januari 2026', $yearly['months'][0]['label']);
        $this->assertSame('Desember 2026', $yearly['months'][11]['label']);
    }

    // ------------------------------------------------- Posisi historis

    /**
     * Laporan per tanggal lampau harus memakai posisi PADA TANGGAL ITU,
     * bukan posisi hari ini yang dinilai dengan harga lampau.
     */
    public function testSnapshotUsesHistoricalPositionsNotTodays(): void
    {
        $this->buildYear();

        // Per 31 Januari posisi masih 10.000 lembar; penjualan baru terjadi Maret.
        $january = service('portfolio')->snapshot('2026-01-31');
        $this->assertCount(1, $january['positions']);
        $this->assertSame(10_000, $january['positions'][0]['quantity']);
        $this->assertMoneyEquals('80020000.00', $january['positions'][0]['book_value']);

        // Per 30 Juni tersisa 5.000 lembar.
        $june = service('portfolio')->snapshot('2026-06-30');
        $this->assertSame(5_000, $june['positions'][0]['quantity']);
        $this->assertMoneyEquals('40010000.00', $june['positions'][0]['book_value']);
    }

    /**
     * Book value historis dari buku besar harus sama dengan saldo akun 1100
     * pada tanggal yang sama — kedua sumber tidak boleh berselisih.
     */
    public function testHistoricalPositionsAgreeWithTheLedger(): void
    {
        $this->buildYear();

        foreach (['2026-01-31', '2026-03-31', '2026-12-31'] as $date) {
            $snapshot = service('portfolio')->snapshot($date);
            $bs       = service('financialStatements')->balanceSheet($date);

            $portfolioOnSheet = Money::zero();

            foreach ($bs['assets'] as $row) {
                if ($row['code'] === '1100') {
                    $portfolioOnSheet = $row['amount'];
                }
            }

            $this->assertTrue(
                $snapshot['totals']['book_value']->equals($portfolioOnSheet),
                sprintf(
                    'Per %s: book value portofolio %s vs akun 1100 di neraca %s.',
                    $date,
                    $snapshot['totals']['book_value']->toDecimalString(),
                    $portfolioOnSheet->toDecimalString()
                )
            );
        }
    }
}
