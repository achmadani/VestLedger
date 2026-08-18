<?php

declare(strict_types=1);

namespace App\Services\Transaction;

use App\Enums\PostingStatus;
use App\Enums\StockTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\CashTransactionModel;
use App\Models\DividendTransactionModel;
use App\Models\StockTransactionModel;
use App\Services\Accounting\AuditLogger;
use App\Services\Accounting\JournalPoster;
use App\Services\Portfolio\PositionService;

/**
 * Pembatalan transaksi lewat jurnal pembalik (§26, §40.8).
 *
 * Tidak ada penghapusan. Transaksi asli tetap tersimpan dengan status Reversed,
 * jurnal aslinya tetap utuh, dan lahir jurnal pembalik yang meniadakan dampaknya.
 * Buku besar dengan demikian menyimpan riwayat lengkap: apa yang dicatat, dan
 * kapan itu dibatalkan.
 */
class ReversalService
{
    public function __construct(
        private CashTransactionModel $cash,
        private StockTransactionModel $stocks,
        private DividendTransactionModel $dividends,
        private PositionService $positions,
        private JournalPoster $poster,
        private AuditLogger $audit,
        private StampDutyService $stampDuty,
    ) {
    }

    public function reverseCash(int $id, ?string $date = null, ?string $reason = null): void
    {
        $transaction = $this->cash->find($id);

        if ($transaction === null) {
            throw new BusinessRuleException('Transaksi kas tidak ditemukan.');
        }

        $this->assertReversible($transaction->status(), $transaction->transaction_number);

        $db = db_connect();
        $db->transBegin();

        try {
            $this->reverseJournal(
                $transaction->journal_entry_id,
                $date ?? $transaction->transaction_date->format('Y-m-d'),
                sprintf('Pembatalan %s', $transaction->transaction_number),
                $reason,
            );

            $this->cash->update($id, ['status' => PostingStatus::Reversed->value]);

            $this->audit->record(
                'reversed',
                'cash_transaction',
                $id,
                sprintf('Pembatalan %s%s', $transaction->transaction_number, $reason !== null ? ' — ' . $reason : ''),
                ['status' => PostingStatus::Posted->value],
                ['status' => PostingStatus::Reversed->value],
            );

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Membatalkan transaksi saham.
     *
     * Hanya transaksi TERAKHIR pada sebuah posisi yang boleh dibatalkan.
     * Alasannya bukan kemalasan teknis: average cost bersifat berurutan.
     * Membatalkan pembelian lama akan mengubah average cost yang dipakai
     * penjualan-penjualan sesudahnya, sehingga realized gain/loss yang sudah
     * terlanjur dicatat — dan sudah masuk laporan — menjadi salah.
     */
    public function reverseStock(int $id, ?string $date = null, ?string $reason = null): void
    {
        $transaction = $this->stocks->find($id);

        if ($transaction === null) {
            throw new BusinessRuleException('Transaksi saham tidak ditemukan.');
        }

        $this->assertReversible($transaction->status(), $transaction->transaction_number);

        $later = $this->laterTransactions($transaction);

        if ($later !== []) {
            throw new BusinessRuleException(
                sprintf(
                    'Transaksi %s tidak dapat dibatalkan karena bukan transaksi terakhir pada posisi ini. '
                    . 'Average cost bersifat berurutan, sehingga membatalkannya akan membuat realized gain/loss '
                    . 'transaksi sesudahnya menjadi salah. Batalkan dari yang paling akhir.',
                    $transaction->transaction_number
                ),
                array_map(
                    static fn ($t): string => $t->transaction_number . ' (' . $t->transaction_date->format('Y-m-d') . ')',
                    $later
                )
            );
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $this->reverseJournal(
                $transaction->journal_entry_id,
                $date ?? $transaction->transaction_date->format('Y-m-d'),
                sprintf('Pembatalan %s', $transaction->transaction_number),
                $reason,
            );

            // Kembalikan posisi ke keadaan sebelum transaksi ini. Nilai
            // *_before direkam saat transaksi dibuat, jadi pemulihannya persis.
            $this->restorePosition($transaction);

            $this->stocks->update($id, ['status' => PostingStatus::Reversed->value]);

            // Setelah pembatalan, total hari itu bisa turun di bawah ambang —
            // materainya ikut dibalik agar tidak ada biaya yang tersisa tanpa
            // dasar transaksi.
            $this->stampDuty->syncFor(
                $transaction->securities_account_id,
                $transaction->transaction_date->format('Y-m-d'),
            );

            $this->audit->record(
                'reversed',
                'stock_transaction',
                $id,
                sprintf('Pembatalan %s%s', $transaction->transaction_number, $reason !== null ? ' — ' . $reason : ''),
                ['status' => PostingStatus::Posted->value],
                ['status' => PostingStatus::Reversed->value],
            );

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    public function reverseDividend(int $id, ?string $date = null, ?string $reason = null): void
    {
        $transaction = $this->dividends->find($id);

        if ($transaction === null) {
            throw new BusinessRuleException('Transaksi dividen tidak ditemukan.');
        }

        $this->assertReversible($transaction->status(), $transaction->transaction_number);

        $db = db_connect();
        $db->transBegin();

        try {
            $this->reverseJournal(
                $transaction->journal_entry_id,
                $date ?? $transaction->transaction_date->format('Y-m-d'),
                sprintf('Pembatalan %s', $transaction->transaction_number),
                $reason,
            );

            $this->dividends->update($id, ['status' => PostingStatus::Reversed->value]);

            $this->audit->record(
                'reversed',
                'dividend_transaction',
                $id,
                sprintf('Pembatalan %s%s', $transaction->transaction_number, $reason !== null ? ' — ' . $reason : ''),
                ['status' => PostingStatus::Posted->value],
                ['status' => PostingStatus::Reversed->value],
            );

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Transaksi pada posisi yang sama yang terjadi SETELAH transaksi ini.
     *
     * @return list<\App\Entities\StockTransaction>
     */
    private function laterTransactions(\App\Entities\StockTransaction $transaction): array
    {
        return $this->stocks
            ->where('securities_account_id', $transaction->securities_account_id)
            ->where('stock_id', $transaction->stock_id)
            ->where('status', PostingStatus::Posted->value)
            ->groupStart()
            ->where('transaction_date >', $transaction->transaction_date->format('Y-m-d'))
            ->orGroupStart()
            ->where('transaction_date', $transaction->transaction_date->format('Y-m-d'))
            ->where('id >', $transaction->id)
            ->groupEnd()
            ->groupEnd()
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->findAll();
    }

    private function restorePosition(\App\Entities\StockTransaction $transaction): void
    {
        $quantity  = $transaction->quantity;
        $bookValue = $transaction->type() === StockTransactionType::Buy
            ? $transaction->netAmount()          // book cost yang tadi ditambahkan
            : $transaction->bookValueSold();     // book value yang tadi dilepas

        if ($transaction->type() === StockTransactionType::Buy) {
            $this->positions->applySell(
                $transaction->securities_account_id,
                $transaction->stock_id,
                $quantity,
                $bookValue,
                $transaction->transaction_date->format('Y-m-d'),
            );

            return;
        }

        $this->positions->applyBuy(
            $transaction->securities_account_id,
            $transaction->stock_id,
            $quantity,
            $bookValue,
            $transaction->transaction_date->format('Y-m-d'),
        );
    }

    private function reverseJournal(?int $journalEntryId, string $date, string $description, ?string $reason): void
    {
        if ($journalEntryId === null) {
            throw new BusinessRuleException(
                'Transaksi ini tidak memiliki jurnal, sehingga tidak dapat dibalik. '
                . 'Data kemungkinan tidak konsisten — periksa buku besar.'
            );
        }

        $this->poster->reverse(
            $journalEntryId,
            $date,
            $reason !== null ? $description . ' — ' . $reason : $description,
            auth()->id(),
        );
    }

    private function assertReversible(PostingStatus $status, string $number): void
    {
        if ($status !== PostingStatus::Posted) {
            throw new BusinessRuleException(sprintf('Transaksi %s sudah dibatalkan sebelumnya.', $number));
        }
    }
}
