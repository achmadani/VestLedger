<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\AccountCode;
use App\Models\AccountModel;
use App\Services\Portfolio\PortfolioService;
use App\ValueObjects\Money;
use DateTimeImmutable;

/**
 * Laporan bulanan (§23) dan tahunan (§24).
 *
 * Angka arus (top up, pembelian, dividen, ...) diambil dari tabel transaksi
 * karena itulah yang dipahami pengguna; angka saldo (kas, book value) diambil
 * dari buku besar karena ledger adalah source of truth. Keduanya selalu cocok
 * — dijaga oleh test yang membandingkan keduanya.
 */
class PeriodicReportService
{
    public function __construct(
        private PortfolioService $portfolio,
        private AccountModel $accounts,
    ) {
    }

    /**
     * Laporan satu bulan, beserta perbandingan dengan bulan sebelumnya (§23).
     *
     * @return array<string, mixed>
     */
    public function monthly(int $year, int $month): array
    {
        $current  = $this->periodFigures(...$this->monthRange($year, $month));
        $previous = $this->previousMonth($year, $month);
        $prior    = $this->periodFigures(...$this->monthRange($previous['year'], $previous['month']));

        return [
            'year'       => $year,
            'month'      => $month,
            'label'      => $this->monthLabel($year, $month),
            'current'    => $current,
            'previous'   => $prior,
            'prev_label' => $this->monthLabel($previous['year'], $previous['month']),
            'comparison' => $this->compare($current, $prior),
        ];
    }

    /**
     * Laporan satu tahun beserta rincian per bulan (§24).
     *
     * @return array<string, mixed>
     */
    public function yearly(int $year): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            [$from, $to] = $this->monthRange($year, $month);

            $months[] = [
                'month'   => $month,
                'label'   => $this->monthLabel($year, $month),
                'figures' => $this->periodFigures($from, $to),
            ];
        }

        return [
            'year'   => $year,
            'total'  => $this->periodFigures($year . '-01-01', $year . '-12-31'),
            'months' => $months,
        ];
    }

    /**
     * Seluruh angka untuk satu rentang tanggal.
     *
     * @return array<string, mixed>
     */
    public function periodFigures(string $from, string $to): array
    {
        $db = db_connect();

        $cashId      = $this->accounts->idFor(AccountCode::Cash);
        $portfolioId = $this->accounts->idFor(AccountCode::StockPortfolio);

        $beginningCash = $this->accountBalanceBefore($cashId, $from);
        $endingCash    = $this->accountBalanceUpTo($cashId, $to);
        $endingBook    = $this->accountBalanceUpTo($portfolioId, $to);

        // Arus kas menurut jenis transaksi.
        $cashByType = [];

        foreach ($db->query(
            "SELECT type, SUM(amount) AS total, SUM(fee) AS fee
             FROM cash_transactions
             WHERE status = 'posted' AND transaction_date >= ? AND transaction_date <= ?
             GROUP BY type",
            [$from, $to]
        )->getResultArray() as $row) {
            $cashByType[$row['type']] = [
                'amount' => Money::of((string) $row['total']),
                'fee'    => Money::of((string) $row['fee']),
            ];
        }

        // Pembelian & penjualan.
        $stockByType = [];

        foreach ($db->query(
            "SELECT type, SUM(gross_amount) AS gross, SUM(net_amount) AS net,
                    SUM(broker_fee) AS broker_fee, SUM(tax) AS tax, SUM(levy) AS levy,
                    SUM(COALESCE(realized_gain_gross, 0)) AS realized_gross,
                    SUM(COALESCE(realized_gain_net, 0)) AS realized_net,
                    COUNT(*) AS trades
             FROM stock_transactions
             WHERE status = 'posted' AND transaction_date >= ? AND transaction_date <= ?
             GROUP BY type",
            [$from, $to]
        )->getResultArray() as $row) {
            $stockByType[$row['type']] = $row;
        }

        $dividend = $db->query(
            "SELECT SUM(gross_dividend) AS gross, SUM(tax) AS tax, SUM(net_dividend) AS net
             FROM dividend_transactions
             WHERE status = 'posted' AND transaction_date >= ? AND transaction_date <= ?",
            [$from, $to]
        )->getRowArray();

        $ledger   = $this->nominalBalances($from, $to);
        $snapshot = $this->portfolio->snapshot($to);

        $get = static fn (array $source, string $key, string $field = 'amount'): Money => isset($source[$key])
            ? (is_array($source[$key]) && $source[$key][$field] instanceof Money
                ? $source[$key][$field]
                : Money::of((string) ($source[$key][$field] ?? '0')))
            : Money::zero();

        $buyNet  = $get($stockByType, 'buy', 'net');
        $sellNet = $get($stockByType, 'sell', 'net');

        $totalFees = $ledger['broker_fee']->add($ledger['admin_expense'])->add($ledger['tax_levy']);

        return [
            'from'                => $from,
            'to'                  => $to,
            'beginning_cash'      => $beginningCash,
            'top_up'              => $get($cashByType, 'top_up'),
            'withdrawal'          => $get($cashByType, 'withdrawal'),
            'transfer'            => $get($cashByType, 'transfer'),
            'admin_fee'           => $get($cashByType, 'admin_fee'),
            'buy'                 => $buyNet,
            'buy_gross'           => $get($stockByType, 'buy', 'gross'),
            'buy_count'           => (int) ($stockByType['buy']['trades'] ?? 0),
            'sell'                => $sellNet,
            'sell_gross'          => $get($stockByType, 'sell', 'gross'),
            'sell_count'          => (int) ($stockByType['sell']['trades'] ?? 0),
            'dividend_gross'      => Money::of((string) ($dividend['gross'] ?? '0')),
            'dividend_tax'        => Money::of((string) ($dividend['tax'] ?? '0')),
            'dividend_net'        => Money::of((string) ($dividend['net'] ?? '0')),
            'broker_fee'          => $ledger['broker_fee'],
            'admin_expense'       => $ledger['admin_expense'],
            'tax_levy'            => $ledger['tax_levy'],
            'total_fees'          => $totalFees,
            'realized_gain'       => $ledger['realized_gain'],
            'realized_loss'       => $ledger['realized_loss'],
            'realized_net'        => $ledger['realized_gain']->subtract($ledger['realized_loss']),
            'net_profit'          => $ledger['realized_gain']
                ->add($ledger['dividend_income'])
                ->subtract($ledger['realized_loss'])
                ->subtract($totalFees),
            'ending_cash'         => $endingCash,
            'ending_book_value'   => $endingBook,
            'ending_market_value' => $snapshot['totals']['market_value'],
            'unpriced_book_value' => $snapshot['totals']['unpriced_book_value'],
            'unrealized'          => $snapshot['totals']['unrealized'],
            'net_worth'           => $snapshot['totals']['net_worth'],
        ];
    }

    /**
     * Perbandingan dua periode (§23).
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $previous
     *
     * @return array<string, array{current:Money, previous:Money, change:Money, change_pct:?float}>
     */
    private function compare(array $current, array $previous): array
    {
        $fields = [
            'top_up', 'withdrawal', 'buy', 'sell', 'dividend_net', 'total_fees',
            'realized_net', 'net_profit', 'ending_cash', 'ending_book_value',
            'ending_market_value', 'unrealized', 'net_worth',
        ];

        $comparison = [];

        foreach ($fields as $field) {
            $now  = $current[$field];
            $then = $previous[$field];

            $change = $now->subtract($then);

            // Persentase tidak bermakna bila pembandingnya nol — kembalikan null
            // alih-alih memaksakan angka yang menyesatkan.
            $pct = $then->isZero() ? null : $change->toFloat() / abs($then->toFloat()) * 100;

            $comparison[$field] = [
                'current'    => $now,
                'previous'   => $then,
                'change'     => $change,
                'change_pct' => $pct,
            ];
        }

        return $comparison;
    }

    /**
     * @return array<string, Money>
     */
    private function nominalBalances(string $from, string $to): array
    {
        $codes = [
            'realized_gain'   => AccountCode::RealizedGain,
            'realized_loss'   => AccountCode::RealizedLoss,
            'dividend_income' => AccountCode::DividendIncome,
            'broker_fee'      => AccountCode::BrokerFee,
            'admin_expense'   => AccountCode::AdministrativeExpense,
            'tax_levy'        => AccountCode::TaxAndLevy,
        ];

        $balances = [];

        foreach ($codes as $key => $code) {
            $balances[$key] = $this->accountMovement($this->accounts->idFor($code), $from, $to, $code->normalBalance()->value);
        }

        return $balances;
    }

    private function accountMovement(int $accountId, string $from, string $to, string $normalBalance): Money
    {
        $row = db_connect()->query(
            'SELECT SUM(jl.debit) AS d, SUM(jl.credit) AS c
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.entry_date >= ? AND je.entry_date <= ?',
            [$accountId, $from, $to]
        )->getRowArray();

        $debit  = Money::of((string) ($row['d'] ?? '0'));
        $credit = Money::of((string) ($row['c'] ?? '0'));

        return $normalBalance === 'debit' ? $debit->subtract($credit) : $credit->subtract($debit);
    }

    private function accountBalanceBefore(int $accountId, string $date): Money
    {
        $row = db_connect()->query(
            'SELECT SUM(jl.debit) - SUM(jl.credit) AS balance
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.entry_date < ?',
            [$accountId, $date]
        )->getRowArray();

        return Money::of((string) ($row['balance'] ?? '0'));
    }

    private function accountBalanceUpTo(int $accountId, string $date): Money
    {
        $row = db_connect()->query(
            'SELECT SUM(jl.debit) - SUM(jl.credit) AS balance
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.entry_date <= ?',
            [$accountId, $date]
        )->getRowArray();

        return Money::of((string) ($row['balance'] ?? '0'));
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function monthRange(int $year, int $month): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    /**
     * @return array{year:int, month:int}
     */
    private function previousMonth(int $year, int $month): array
    {
        return $month === 1
            ? ['year' => $year - 1, 'month' => 12]
            : ['year' => $year, 'month' => $month - 1];
    }

    public function monthLabel(int $year, int $month): string
    {
        $names = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return ($names[$month] ?? (string) $month) . ' ' . $year;
    }
}
