<?php

declare(strict_types=1);

namespace App\Services\Transaction;

use App\Enums\AccountCode;
use App\Enums\CashTransactionType;
use App\Enums\PostingStatus;
use App\Enums\SourceType;
use App\Models\CashTransactionModel;
use App\Services\Accounting\AuditLogger;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\JournalPoster;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\Money;
use Config\Investment;
use LogicException;

/**
 * Bea materai atas konfirmasi transaksi harian.
 *
 * Setiap broker menerbitkan satu Trade Confirmation per hari, dan bea materai
 * melekat pada dokumen itu — bukan pada tiap transaksi. Karena itu:
 *
 *   - dasar pengenaannya adalah TOTAL nilai bruto seluruh pembelian dan
 *     penjualan pada satu rekening sekuritas di tanggal yang sama;
 *   - materai dikenakan SEKALI untuk hari itu, bukan per transaksi.
 *
 * Perhitungannya bersifat menyesuaikan diri (sync), bukan sekali tambah:
 * setiap kali transaksi hari itu berubah — termasuk transaksi yang dimasukkan
 * mundur maupun dibatalkan — nilainya dihitung ulang dan materai dibuat atau
 * dibalik seperlunya. Tanpa itu, transaksi backdate yang melewati ambang batas
 * tidak akan pernah menghasilkan materai.
 */
class StampDutyService
{
    public function __construct(
        private CashTransactionModel $transactions,
        private JournalPoster $poster,
        private DocumentNumberService $numbers,
        private AuditLogger $audit,
        private Investment $config,
    ) {
    }

    /**
     * Menyesuaikan bea materai untuk satu rekening pada satu tanggal.
     *
     * Wajib dipanggil di dalam database transaction milik pemanggil, agar
     * materai dan transaksi yang memicunya berhasil bersama atau dibatalkan
     * bersama (§8).
     */
    public function syncFor(int $securitiesAccountId, string $date): void
    {
        if (db_connect()->transDepth === 0) {
            throw new LogicException(
                'StampDutyService::syncFor() wajib dipanggil di dalam database transaction.'
            );
        }

        $turnover = $this->dailyTurnover($securitiesAccountId, $date);
        $existing = $this->existingDuty($securitiesAccountId, $date);
        $required = $turnover->greaterThan(Money::of($this->config->stampDutyThreshold));

        if ($required && $existing === null) {
            $this->charge($securitiesAccountId, $date, $turnover);

            return;
        }

        if (! $required && $existing !== null) {
            $this->reverse($existing, $date, $turnover);
        }
    }

    /**
     * Total nilai bruto seluruh transaksi saham pada satu rekening di satu tanggal.
     *
     * Pembelian dan penjualan DIJUMLAHKAN, bukan disalinghapuskan: keduanya
     * tercantum pada konfirmasi harian yang sama.
     */
    public function dailyTurnover(int $securitiesAccountId, string $date): Money
    {
        $row = db_connect()->query(
            "SELECT SUM(gross_amount) AS total
             FROM stock_transactions
             WHERE securities_account_id = ? AND transaction_date = ? AND status = ?",
            [$securitiesAccountId, $date, PostingStatus::Posted->value]
        )->getRowArray();

        return Money::of((string) ($row['total'] ?? '0'));
    }

    private function existingDuty(int $securitiesAccountId, string $date): ?\App\Entities\CashTransaction
    {
        return $this->transactions
            ->where('securities_account_id', $securitiesAccountId)
            ->where('transaction_date', $date)
            ->where('type', CashTransactionType::StampDuty->value)
            ->where('status', PostingStatus::Posted->value)
            ->first();
    }

    private function charge(int $securitiesAccountId, string $date, Money $turnover): void
    {
        $amount = Money::of($this->config->stampDutyAmount);
        $number = $this->numbers->next(
            DocumentNumberService::PREFIX_CASH,
            $date,
            'cash_transactions',
            'transaction_number'
        );

        $id = $this->transactions->insert([
            'transaction_number'    => $number,
            'transaction_date'      => $date,
            'type'                  => CashTransactionType::StampDuty->value,
            'securities_account_id' => $securitiesAccountId,
            'amount'                => $amount->toDecimalString(),
            'fee'                   => '0.00',
            'tax'                   => '0.00',
            'net_amount'            => $amount->negate()->toDecimalString(),
            // Angka ditulis apa adanya: service tidak boleh bergantung pada
            // helper presentasi, yang belum tentu termuat di konteks CLI.
            'notes'                 => sprintf(
                'Otomatis: total transaksi hari ini %s melebihi ambang %s',
                $turnover->toDecimalString(),
                number_format($this->config->stampDutyThreshold, 2, '.', '')
            ),
            'status'                => PostingStatus::Posted->value,
            'created_by'            => auth()->id(),
        ], true);

        $draft = new JournalDraft(
            $date,
            'Bea materai konfirmasi transaksi — ' . $number,
            SourceType::Cash,
            $id,
        );

        // Bea materai adalah pungutan negara atas dokumen, bukan jasa broker,
        // sehingga masuk akun Pajak & Levy dan bukan Biaya Broker.
        $draft->debit(AccountCode::TaxAndLevy, $amount, $securitiesAccountId, null, 'Bea materai');
        $draft->credit(AccountCode::Cash, $amount, $securitiesAccountId, null, 'Pembayaran bea materai');

        $entry = $this->poster->post($draft, auth()->id());

        $this->transactions->update($id, ['journal_entry_id' => $entry->id]);

        $this->audit->record(
            'created',
            'cash_transaction',
            $id,
            sprintf('Bea materai %s otomatis pada %s', $amount->toDecimalString(), $date),
        );
    }

    private function reverse(\App\Entities\CashTransaction $duty, string $date, Money $turnover): void
    {
        if ($duty->journal_entry_id !== null) {
            $this->poster->reverse(
                $duty->journal_entry_id,
                $date,
                'Pembatalan bea materai — total transaksi turun menjadi ' . $turnover->toDecimalString(),
                auth()->id(),
            );
        }

        $this->transactions->update($duty->id, ['status' => PostingStatus::Reversed->value]);

        $this->audit->record(
            'reversed',
            'cash_transaction',
            $duty->id,
            sprintf('Bea materai dibatalkan: total transaksi %s tidak lagi melewati ambang', $turnover->toDecimalString()),
        );
    }
}
