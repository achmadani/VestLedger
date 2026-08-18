<?php

declare(strict_types=1);

namespace App\Controllers\Accounting;

use App\Controllers\BaseController;
use App\Controllers\Concerns\FiltersRequestInput;
use App\Models\JournalEntryModel;
use App\Models\JournalLineModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Daftar dan detail jurnal (§21.6).
 */
class Journal extends BaseController
{
    use FiltersRequestInput;

    public function index(): string
    {
        $entries = new JournalEntryModel();
        $from    = (string) $this->dateInput('from', '');
        $to      = (string) $this->dateInput('to', '');

        $builder = $entries->withTotals();

        if ($from !== '') {
            $builder->where('journal_entries.entry_date >=', $from);
        }

        if ($to !== '') {
            $builder->where('journal_entries.entry_date <=', $to);
        }

        $perPage = config(\Config\Pager::class)->perPage;

        return view('accounting/journal/index', [
            'pageTitle' => 'Jurnal',
            'entries'   => $builder->orderBy('journal_entries.entry_date', 'desc')
                ->orderBy('journal_entries.id', 'desc')
                ->paginate($perPage),
            'pager'     => $entries->pager,
            'filters'   => ['from' => $from, 'to' => $to],
            'balanced'  => service('journalPoster')->ledgerIsBalanced(),
        ]);
    }

    public function show(int $id): string|RedirectResponse
    {
        $entry = (new JournalEntryModel())->find($id);

        if ($entry === null) {
            return redirect()->to('/accounting/journal')->with('error', 'Jurnal tidak ditemukan.');
        }

        $lines = (new JournalLineModel())->ledgerQuery()
            ->where('jl.journal_entry_id', $id)
            ->get()
            ->getResultArray();

        return view('accounting/journal/show', [
            'pageTitle' => 'Jurnal ' . $entry->entry_number,
            'entry'     => $entry,
            'lines'     => $lines,
        ]);
    }
}
