<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FiltersRequestInput;
use App\Models\AccountingPeriodModel;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;

/**
 * Laporan keuangan (§21) dan laporan investasi (§22, §23, §24).
 *
 * Controller tetap tipis: seluruh perhitungan ada di service layer, dan di sini
 * hanya penerjemahan parameter rentang tanggal.
 */
class Reports extends BaseController
{
    use FiltersRequestInput;

    // ------------------------------------------------------ Laporan keuangan

    public function balanceSheet(): string
    {
        $asOf = (string) $this->dateInput('as_of', date('Y-m-d'));

        return view('reports/balance_sheet', [
            'pageTitle' => 'Neraca',
            'asOf'      => $asOf,
            'report'    => service('financialStatements')->balanceSheet($asOf),
        ]);
    }

    public function incomeStatement(): string
    {
        [$from, $to] = $this->range();

        return view('reports/income_statement', [
            'pageTitle' => 'Laba Rugi',
            'from'      => $from,
            'to'        => $to,
            'report'    => service('financialStatements')->incomeStatement($from, $to),
        ]);
    }

    public function cashFlow(): string
    {
        [$from, $to] = $this->range();

        return view('reports/cash_flow', [
            'pageTitle' => 'Arus Kas',
            'from'      => $from,
            'to'        => $to,
            'report'    => service('financialStatements')->cashFlow($from, $to),
        ]);
    }

    public function trialBalance(): string
    {
        [$from, $to] = $this->range();

        return view('reports/trial_balance', [
            'pageTitle' => 'Neraca Saldo',
            'from'      => $from,
            'to'        => $to,
            'report'    => service('financialStatements')->trialBalance($from, $to),
        ]);
    }

    // ------------------------------------------------------ Laporan periodik

    public function monthly(): string
    {
        $year  = (int) ($this->request->getGet('year') ?: date('Y'));
        $month = (int) ($this->request->getGet('month') ?: date('n'));
        $month = max(1, min(12, $month));

        return view('reports/monthly', [
            'pageTitle' => 'Laporan Bulanan',
            'year'      => $year,
            'month'     => $month,
            'years'     => $this->availableYears(),
            'report'    => service('periodicReports')->monthly($year, $month),
        ]);
    }

    public function yearly(): string
    {
        $year = (int) ($this->request->getGet('year') ?: date('Y'));

        return view('reports/yearly', [
            'pageTitle' => 'Laporan Tahunan',
            'year'      => $year,
            'years'     => $this->availableYears(),
            'report'    => service('periodicReports')->yearly($year),
        ]);
    }

    // ----------------------------------------------------- Laporan investasi

    public function realized(): string
    {
        [$from, $to] = $this->range();

        $stockId   = $this->idInput('stock_id');
        $accountId = $this->idInput('securities_account_id');

        return view('reports/realized', [
            'pageTitle' => 'Realized Gain/Loss',
            'from'      => $from,
            'to'        => $to,
            'filters'   => ['stock_id' => $stockId, 'securities_account_id' => $accountId],
            'stocks'    => (new StockModel())->options(),
            'accounts'  => (new SecuritiesAccountModel())->options(),
            'report'    => service('investmentReports')->realized($from, $to, $stockId, $accountId),
        ]);
    }

    public function unrealized(): string
    {
        $asOf = (string) $this->dateInput('as_of', date('Y-m-d'));

        return view('reports/unrealized', [
            'pageTitle' => 'Unrealized Gain/Loss',
            'asOf'      => $asOf,
            'snapshot'  => service('portfolio')->snapshot($asOf),
        ]);
    }

    public function dividend(): string
    {
        [$from, $to] = $this->range();

        return view('reports/dividend', [
            'pageTitle' => 'Laporan Dividen',
            'from'      => $from,
            'to'        => $to,
            'report'    => service('investmentReports')->dividends($from, $to),
        ]);
    }

    public function brokerFee(): string
    {
        [$from, $to] = $this->range();

        return view('reports/broker_fee', [
            'pageTitle' => 'Laporan Broker Fee',
            'from'      => $from,
            'to'        => $to,
            'report'    => service('investmentReports')->brokerFees($from, $to),
        ]);
    }

    // ------------------------------------------------------------- Pembantu

    /**
     * Rentang tanggal; default awal tahun berjalan sampai hari ini.
     *
     * @return array{0:string, 1:string}
     */
    private function range(): array
    {
        $from = (string) $this->dateInput('from', date('Y') . '-01-01');
        $to   = (string) $this->dateInput('to', date('Y-m-d'));

        // Rentang terbalik hampir selalu salah ketik; ditukar diam-diam akan
        // membingungkan, jadi dibetulkan dan hasilnya tetap masuk akal.
        return $from <= $to ? [$from, $to] : [$to, $from];
    }


    /**
     * @return list<int>
     */
    private function availableYears(): array
    {
        $years = (new AccountingPeriodModel())->years();

        return $years === [] ? [(int) date('Y')] : $years;
    }
}
