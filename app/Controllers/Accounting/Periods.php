<?php

declare(strict_types=1);

namespace App\Controllers\Accounting;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountingPeriodModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Periode akuntansi (§25).
 */
class Periods extends BaseController
{
    use HandlesBusinessRules;

    private AccountingPeriodModel $periods;

    public function __construct()
    {
        $this->periods = new AccountingPeriodModel();
    }

    public function index(): string
    {
        $years = $this->periods->years();
        $year  = (int) ($this->request->getGet('year') ?: ($years[0] ?? date('Y')));

        return view('accounting/periods/index', [
            'pageTitle' => 'Periode Akuntansi',
            'years'     => $years,
            'year'      => $year,
            'periods'   => $this->periods->forYear($year),
        ]);
    }

    public function generate(): RedirectResponse
    {
        $year = (int) $this->request->getPost('year');

        try {
            $created = service('accountingPeriod')->generateYear($year);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/accounting/periods');
        }

        $message = $created === 0
            ? sprintf('Seluruh periode tahun %d memang sudah ada.', $year)
            : sprintf('%d periode tahun %d berhasil dibuat.', $created, $year);

        return redirect()->to('/accounting/periods?year=' . $year)->with('success', $message);
    }

    public function close(int $id): RedirectResponse
    {
        try {
            $period = service('accountingPeriod')->close($id, auth()->id(), $this->request->getPost('notes') ?: null);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/accounting/periods');
        }

        return redirect()->to('/accounting/periods?year=' . $period->year)
            ->with('success', sprintf('Periode %s ditutup. Transaksi bertanggal dalam periode ini tidak lagi diterima.', $period->displayName()));
    }

    public function reopen(int $id): RedirectResponse
    {
        try {
            $period = service('accountingPeriod')->reopen($id);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/accounting/periods');
        }

        return redirect()->to('/accounting/periods?year=' . $period->year)
            ->with('success', sprintf('Periode %s dibuka kembali.', $period->displayName()));
    }
}
