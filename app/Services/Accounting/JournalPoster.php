<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Entities\JournalEntry;
use App\Enums\AccountCode;
use App\Enums\JournalEntryType;
use App\Enums\PostingStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountingPeriodModel;
use App\Models\AccountModel;
use App\Models\JournalEntryModel;
use App\Models\JournalLineModel;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\JournalLineDraft;
use App\ValueObjects\Money;
use LogicException;

/**
 * Satu-satunya pintu masuk ke buku besar (§8).
 *
 * Urutan yang dijalankan persis seperti yang diminta spesifikasi:
 *   1. jurnal disusun (oleh service transaksi, sebagai JournalDraft)
 *   2. jurnal divalidasi
 *   3. debit = kredit diperiksa
 *   4. baru di-commit
 *
 * Poster ini MENOLAK berjalan di luar database transaction. Itulah pengaman
 * terhadap kegagalan yang paling ditakuti spesifikasi: transaksi saham
 * tersimpan tetapi jurnalnya gagal (§8, §40.7).
 */
class JournalPoster
{
    public function __construct(
        private JournalEntryModel $entries,
        private JournalLineModel $lines,
        private AccountModel $accounts,
        private AccountingPeriodModel $periods,
        private AccountingPeriodService $periodService,
        private DocumentNumberService $numbers,
    ) {
    }

    /**
     * Memvalidasi lalu menyimpan sebuah jurnal.
     *
     * @throws BusinessRuleException bila jurnal tidak sah atau periode tertutup
     * @throws LogicException        bila dipanggil di luar database transaction
     */
    public function post(JournalDraft $draft, ?int $userId = null): JournalEntry
    {
        $db = db_connect();

        if ($db->transDepth === 0) {
            // Kesalahan programmer, bukan kesalahan pengguna — karena itu
            // LogicException, dan pesannya ditujukan kepada pengembang.
            throw new LogicException(
                'JournalPoster::post() wajib dipanggil di dalam database transaction, '
                . 'agar transaksi bisnis dan jurnalnya berhasil bersama atau dibatalkan bersama.'
            );
        }

        $this->validate($draft);

        $period = $this->periods->findForDate($draft->date);
        $this->periodService->assertDateIsPostable($draft->date);

        $entryId = $this->entries->insert([
            'entry_number'         => $this->numbers->next(
                DocumentNumberService::PREFIX_JOURNAL,
                $draft->date,
                'journal_entries',
                'entry_number'
            ),
            'entry_date'           => $draft->date,
            'accounting_period_id' => $period->id,
            'type'                 => $draft->type->value,
            'source_type'          => $draft->sourceType->value,
            'source_id'            => $draft->sourceId,
            'reverses_entry_id'    => $draft->reversesEntryId,
            'description'          => $draft->description,
            'status'               => PostingStatus::Posted->value,
            'created_by'           => $userId,
        ], true);

        if ($entryId === false) {
            throw new BusinessRuleException(
                'Jurnal gagal disimpan.',
                array_values($this->entries->errors())
            );
        }

        $lineNo = 0;

        foreach ($draft->lines() as $line) {
            $lineNo++;

            $inserted = $this->lines->insert([
                'journal_entry_id'      => $entryId,
                'line_no'               => $lineNo,
                'account_id'            => $this->resolveAccountId($line),
                'debit'                 => $line->debit()->toDecimalString(),
                'credit'                => $line->credit()->toDecimalString(),
                'securities_account_id' => $line->securitiesAccountId,
                'stock_id'              => $line->stockId,
                'memo'                  => $line->memo,
            ]);

            if ($inserted === false) {
                throw new BusinessRuleException(
                    'Baris jurnal gagal disimpan.',
                    array_values($this->lines->errors())
                );
            }
        }

        return $this->entries->find($entryId);
    }

    /**
     * Membalik jurnal yang sudah posted (§26).
     *
     * Jurnal asli TIDAK diubah isinya dan tidak dihapus — hanya statusnya
     * menjadi Reversed, dan lahir jurnal baru berisi kebalikannya. Buku besar
     * dengan demikian tetap menyimpan kedua sisi cerita (§40.8).
     */
    public function reverse(int $entryId, string $date, string $description, ?int $userId = null): JournalEntry
    {
        $db = db_connect();

        if ($db->transDepth === 0) {
            throw new LogicException('JournalPoster::reverse() wajib dipanggil di dalam database transaction.');
        }

        $original = $this->entries->find($entryId);

        if ($original === null) {
            throw new BusinessRuleException('Jurnal yang hendak dibalik tidak ditemukan.');
        }

        if ($original->isReversed()) {
            throw new BusinessRuleException(sprintf('Jurnal %s sudah pernah dibalik.', $original->entry_number));
        }

        if ($original->isReversal()) {
            throw new BusinessRuleException('Jurnal pembalik tidak dapat dibalik lagi.');
        }

        // Draft pembalik disusun dari baris yang BENAR-BENAR tersimpan, bukan
        // dari perhitungan ulang — sehingga pembalikan selalu persis menghapus
        // dampak jurnal aslinya, apa pun isinya.
        $reversal = new JournalDraft(
            $date,
            $description,
            $original->sourceType(),
            $original->source_id,
            JournalEntryType::Reversal,
            $original->id,
        );

        foreach ($this->lines->forEntry($original->id) as $line) {
            $debit  = $line->debit();
            $credit = $line->credit();

            if ($debit->isPositive()) {
                $reversal->credit(
                    $line->account_id,
                    $debit,
                    $line->securities_account_id,
                    $line->stock_id,
                    'Pembalik: ' . ($line->memo ?? ''),
                );
            } else {
                $reversal->debit(
                    $line->account_id,
                    $credit,
                    $line->securities_account_id,
                    $line->stock_id,
                    'Pembalik: ' . ($line->memo ?? ''),
                );
            }
        }

        $entry = $this->post($reversal, $userId);

        $this->entries->update($original->id, ['status' => PostingStatus::Reversed->value]);

        return $entry;
    }

    /**
     * Memeriksa keseluruhan buku besar: total debit harus sama dengan total kredit.
     *
     * Dipakai sebagai pemeriksaan kesehatan dan oleh test (§37).
     */
    public function ledgerIsBalanced(): bool
    {
        $row = db_connect()->table('journal_lines')
            ->select('SUM(debit) AS total_debit, SUM(credit) AS total_credit')
            ->get()
            ->getRowArray();

        return Money::of((string) ($row['total_debit'] ?? '0'))
            ->equals(Money::of((string) ($row['total_credit'] ?? '0')));
    }

    private function validate(JournalDraft $draft): void
    {
        if ($draft->isEmpty()) {
            throw new BusinessRuleException('Jurnal tanpa satu pun baris tidak dapat disimpan.');
        }

        if (count($draft->lines()) < 2) {
            throw new BusinessRuleException(
                'Jurnal harus memiliki minimal dua baris; pencatatan berpasangan tidak mungkin dengan satu baris.'
            );
        }

        if (! $draft->isBalanced()) {
            $difference = $draft->difference();

            throw new BusinessRuleException(
                'Jurnal tidak balance dan karena itu ditolak.',
                [
                    'Total debit  : ' . $draft->totalDebit()->toDecimalString(),
                    'Total kredit : ' . $draft->totalCredit()->toDecimalString(),
                    'Selisih      : ' . $difference->toDecimalString(),
                ]
            );
        }

        foreach ($draft->lines() as $line) {
            $this->validateDimensions($line);
        }
    }

    /**
     * Dimensi wajib: tanpa ini, Buku Besar tidak dapat difilter per sekuritas
     * maupun per ticker (§21.5) dan saldo kas per sekuritas tidak dapat dihitung.
     */
    private function validateDimensions(JournalLineDraft $line): void
    {
        if ($line->requiresSecuritiesDimension() && $line->securitiesAccountId === null) {
            throw new BusinessRuleException(sprintf(
                'Baris jurnal untuk akun %s wajib menyebut rekening sekuritas.',
                $line->accountLabel()
            ));
        }

        if ($line->requiresStockDimension() && $line->stockId === null) {
            throw new BusinessRuleException(sprintf(
                'Baris jurnal untuk akun %s wajib menyebut saham.',
                $line->accountLabel()
            ));
        }
    }

    private function resolveAccountId(JournalLineDraft $line): int
    {
        if ($line->account instanceof AccountCode) {
            return $this->accounts->idFor($line->account);
        }

        $account = $this->accounts->find($line->account);

        if ($account === null) {
            throw new BusinessRuleException('Akun pada baris jurnal tidak ditemukan.');
        }

        if (! $account->is_postable) {
            throw new BusinessRuleException(sprintf(
                'Akun %s adalah akun header dan tidak dapat menerima baris jurnal.',
                $account->displayName()
            ));
        }

        return $account->id;
    }
}
