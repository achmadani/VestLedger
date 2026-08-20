<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Enums\SourceType;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Laba Rugi per sekuritas (§21.6).
 *
 * Yang paling penting di sini bukan angka masing-masing baris, melainkan satu
 * sifat: rinciannya WAJIB berjumlah persis sama dengan Laba Rugi global.
 * Rincian yang diam-diam tidak menjumlah lebih buruk daripada tidak ada
 * rincian sama sekali, karena ia terlihat meyakinkan.
 *
 * @internal
 */
final class ProfitBySecuritiesTest extends EngineTestCase
{
    /**
     * Aktivitas di DUA sekuritas, dengan bentuk yang berbeda:
     * Ajaib untung dan menerima dividen, IPOT rugi.
     */
    private function buildTwoBrokers(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 200_000_000,
        ]);
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ipot, 'amount' => 200_000_000,
        ]);

        // Ajaib: beli 10.000 @8.000, jual 5.000 @9.000 -> untung
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 8_000,
            'broker_fee' => 0, 'tax' => 0, 'levy' => 0,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-03-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 9_000,
            'broker_fee' => 15_000, 'tax' => 0, 'levy' => 0,
        ]);
        service('dividendTransactions')->record([
            'transaction_date' => '2026-04-10', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity_eligible' => 5_000, 'dividend_per_share' => 100, 'tax' => 50_000,
        ]);

        // IPOT: beli 10.000 @8.000, jual 5.000 @7.000 -> rugi
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-15', 'securities_account_id' => $this->ipot,
            'stock_id' => $this->bbri, 'quantity' => 10_000, 'price' => 8_000,
            'broker_fee' => 0, 'tax' => 0, 'levy' => 0,
        ]);
        service('stockTransactions')->sell([
            'transaction_date' => '2026-03-15', 'securities_account_id' => $this->ipot,
            'stock_id' => $this->bbri, 'quantity' => 5_000, 'price' => 7_000,
            'broker_fee' => 10_000, 'tax' => 0, 'levy' => 0,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rowFor(array $rows, ?int $accountId): ?array
    {
        foreach ($rows as $row) {
            if ($row['securities_account_id'] === $accountId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Sifat yang menjadi alasan laporan ini boleh dipercaya.
     */
    public function testBreakdownSumsExactlyToTheGlobalIncomeStatement(): void
    {
        $this->buildTwoBrokers();

        $global    = service('financialStatements')->incomeStatement('2026-01-01', '2026-12-31');
        $breakdown = service('financialStatements')->profitBySecurities('2026-01-01', '2026-12-31');

        $this->assertMoneyEquals(
            $global['net_profit']->toDecimalString(),
            $breakdown['totals']['net_profit'],
        );
        $this->assertMoneyEquals(
            $global['total_revenue']->toDecimalString(),
            $breakdown['totals']['revenue'],
        );
        $this->assertMoneyEquals(
            $global['total_expense']->toDecimalString(),
            $breakdown['totals']['expense'],
        );
    }

    /**
     * Menjumlahkan Laba Rugi yang difilter per sekuritas harus menghasilkan
     * angka global yang sama — kalau tidak, salah satu filter membuang baris.
     */
    public function testFilteredIncomeStatementsAddUpToTheGlobalFigure(): void
    {
        $this->buildTwoBrokers();

        $statements = service('financialStatements');
        $global     = $statements->incomeStatement('2026-01-01', '2026-12-31');
        $ajaib      = $statements->incomeStatement('2026-01-01', '2026-12-31', $this->ajaib);
        $ipot       = $statements->incomeStatement('2026-01-01', '2026-12-31', $this->ipot);

        $this->assertMoneyEquals(
            $global['net_profit']->toDecimalString(),
            $ajaib['net_profit']->add($ipot['net_profit']),
        );

        // Ajaib untung, IPOT rugi — keduanya harus tampak berbeda tanda.
        $this->assertTrue($ajaib['net_profit']->isPositive(), 'Ajaib seharusnya untung.');
        $this->assertTrue($ipot['net_profit']->isNegative(), 'IPOT seharusnya rugi.');
    }

    public function testEachSecuritiesRowCarriesItsOwnRealizedDividendAndFees(): void
    {
        $this->buildTwoBrokers();

        $report = service('financialStatements')->profitBySecurities('2026-01-01', '2026-12-31');
        $ajaib  = $this->rowFor($report['rows'], $this->ajaib);
        $ipot   = $this->rowFor($report['rows'], $this->ipot);

        $this->assertNotNull($ajaib);
        $this->assertNotNull($ipot);

        // Ajaib: jual 5.000 @9.000 = 45.000.000, book value dilepas 40.000.000
        $this->assertMoneyEquals('5000000.00', $ajaib['realized_net']);
        $this->assertMoneyEquals('500000.00', $ajaib['dividend']);
        $this->assertMoneyEquals('15000.00', $ajaib['broker_fee']);
        // Pajak & levy Ajaib = pajak dividen 50.000 + bea materai 2 x 10.000
        // (beli 80 juta dan jual 45 juta, dua-duanya di atas ambang 10 juta).
        $this->assertMoneyEquals('70000.00', $ajaib['tax_levy']);

        // IPOT: jual 5.000 @7.000 = 35.000.000, book value dilepas 40.000.000
        $this->assertMoneyEquals('-5000000.00', $ipot['realized_net']);
        $this->assertMoneyEquals('0.00', $ipot['dividend']);
        $this->assertMoneyEquals('10000.00', $ipot['broker_fee']);
        // IPOT tidak menerima dividen, jadi pajaknya murni bea materai 2 x 10.000.
        $this->assertMoneyEquals('20000.00', $ipot['tax_levy']);
    }

    /**
     * Unrealized berdiri terpisah: ia tidak pernah dijurnal, sehingga tidak
     * boleh ikut menggeser laba (§13, §14).
     */
    public function testUnrealizedIsReportedSeparatelyAndNeverAddedToProfit(): void
    {
        $this->buildTwoBrokers();

        service('marketPrices')->record([
            'stock_id' => $this->bbca, 'price_date' => '2026-06-30', 'closing_price' => 10_000,
        ]);

        $unrealized = [];

        foreach (service('portfolio')->snapshot('2026-06-30')['by_securities'] as $row) {
            $unrealized[$row['securities_account_id']] = $row['unrealized'];
        }

        $statements = service('financialStatements');
        $withPrice  = $statements->profitBySecurities('2026-01-01', '2026-12-31', $unrealized);
        $without    = $statements->profitBySecurities('2026-01-01', '2026-12-31');

        $ajaib = $this->rowFor($withPrice['rows'], $this->ajaib);

        // Sisa 5.000 lembar @8.000 book, harga 10.000 -> unrealized 10.000.000
        $this->assertMoneyEquals('10000000.00', $ajaib['unrealized']);

        // Laba tidak bergerak sedikit pun karena harga pasar.
        $this->assertMoneyEquals(
            $without['totals']['net_profit']->toDecimalString(),
            $withPrice['totals']['net_profit'],
        );
    }

    /**
     * Baris nominal tanpa dimensi sekuritas tidak boleh hilang diam-diam.
     *
     * Seluruh transaksi aplikasi memang selalu membawa dimensi, tetapi jurnal
     * yang dibuat di luar jalur itu tidak dijamin demikian. Bila baris seperti
     * itu dibuang, total rincian akan berbeda dari Laba Rugi global tanpa satu
     * pun tanda — persis kegagalan yang paling sulit disadari.
     */
    public function testUnattributedNominalLinesAreShownInsteadOfDropped(): void
    {
        $this->buildTwoBrokers();

        $db = db_connect();
        $db->transBegin();

        $draft = new JournalDraft('2026-06-01', 'Biaya tingkat entitas', SourceType::Manual);
        $draft->debit(AccountCode::AdministrativeExpense, Money::of('75000.00'), null, null, 'Biaya tanpa sekuritas');
        $draft->credit(AccountCode::Cash, Money::of('75000.00'), $this->ajaib, null, 'Pembayaran');

        service('journalPoster')->post($draft);
        $db->transCommit();

        $global    = service('financialStatements')->incomeStatement('2026-01-01', '2026-12-31');
        $breakdown = service('financialStatements')->profitBySecurities('2026-01-01', '2026-12-31');

        $this->assertTrue($breakdown['has_unattributed'], 'Baris tanpa dimensi harus ditandai.');

        $orphan = $this->rowFor($breakdown['rows'], null);
        $this->assertNotNull($orphan, 'Harus ada baris "Tanpa sekuritas".');
        $this->assertMoneyEquals('75000.00', $orphan['admin_expense']);

        // Dan yang terpenting: totalnya tetap sama dengan Laba Rugi global.
        $this->assertMoneyEquals(
            $global['net_profit']->toDecimalString(),
            $breakdown['totals']['net_profit'],
        );
    }

    /**
     * Rekening yang tidak bergerak sama sekali hanya menambah baris kosong.
     */
    public function testDormantSecuritiesAccountsAreOmitted(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);

        $report = service('financialStatements')->profitBySecurities('2026-01-01', '2026-12-31');

        $this->assertNull($this->rowFor($report['rows'], $this->ipot), 'IPOT tanpa aktivitas tidak perlu muncul.');
    }
}
