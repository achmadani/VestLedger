<?php

declare(strict_types=1);

namespace App\Services\Transaction;

use App\Entities\CashTransaction;
use App\Enums\AccountCode;
use App\Enums\CashTransactionType;
use App\Enums\PostingStatus;
use App\Enums\SourceType;
use App\Exceptions\BusinessRuleException;
use App\Models\CashTransactionModel;
use App\Models\SecuritiesAccountModel;
use App\Services\Accounting\AuditLogger;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\JournalPoster;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\Money;

/**
 * Transaksi kas: top up, withdrawal, transfer antar sekuritas, biaya administrasi
 * (§16, §17, §18).
 *
 * Konvensi nilai yang dipakai seluruh jenis transaksi kas:
 *   - `amount` adalah nilai POKOK transaksi
 *   - `fee` adalah biaya yang menyertainya, selalu dibebankan ke akun 5100
 *   - `net_amount` adalah pergerakan kas yang sesungguhnya pada rekening utama
 */
class CashTransactionService
{
    public function __construct(
        private CashTransactionModel $transactions,
        private SecuritiesAccountModel $accounts,
        private JournalPoster $poster,
        private DocumentNumberService $numbers,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Top up dana (§16). Top up BUKAN pendapatan (§40.3).
     *
     * Kas bertambah sebesar amount − fee; modal disetor bertambah sebesar
     * amount penuh, karena itulah yang benar-benar disetor pemilik.
     *
     * @param array{transaction_date:string, securities_account_id:int, amount:mixed, fee?:mixed, notes?:?string} $input
     */
    public function topUp(array $input): CashTransaction
    {
        $amount = $this->positiveMoney($input['amount'] ?? 0, 'Nominal top up');
        $fee    = $this->nonNegativeMoney($input['fee'] ?? 0, 'Biaya');

        if ($fee->greaterThan($amount)) {
            throw new BusinessRuleException('Biaya tidak boleh melebihi nominal top up.');
        }

        $accountId = $this->requireAccount($input['securities_account_id'] ?? 0);
        $cashIn    = $amount->subtract($fee);

        return $this->record(
            CashTransactionType::TopUp,
            $input,
            $accountId,
            null,
            $amount,
            $fee,
            $cashIn,
            function (JournalDraft $draft) use ($amount, $fee, $cashIn, $accountId): void {
                $draft->debit(AccountCode::Cash, $cashIn, $accountId, null, 'Top up dana');
                $draft->debit(AccountCode::AdministrativeExpense, $fee, $accountId, null, 'Biaya top up');
                $draft->credit(AccountCode::PaidInCapital, $amount, null, null, 'Setoran modal');
            },
        );
    }

    /**
     * Withdrawal (§17). Withdrawal BUKAN beban (§40.4).
     *
     * Pokoknya masuk ke akun kontra-ekuitas 3200; biayanya saja yang menjadi beban.
     */
    public function withdraw(array $input): CashTransaction
    {
        $amount = $this->positiveMoney($input['amount'] ?? 0, 'Nominal withdrawal');
        $fee    = $this->nonNegativeMoney($input['fee'] ?? 0, 'Biaya');

        $accountId = $this->requireAccount($input['securities_account_id'] ?? 0);
        $cashOut   = $amount->add($fee);

        return $this->record(
            CashTransactionType::Withdrawal,
            $input,
            $accountId,
            null,
            $amount,
            $fee,
            $cashOut->negate(),
            function (JournalDraft $draft) use ($amount, $fee, $cashOut, $accountId): void {
                $draft->debit(AccountCode::OwnerWithdrawal, $amount, null, null, 'Penarikan pemilik');
                $draft->debit(AccountCode::AdministrativeExpense, $fee, $accountId, null, 'Biaya penarikan');
                $draft->credit(AccountCode::Cash, $cashOut, $accountId, null, 'Withdrawal');
            },
        );
    }

    /**
     * Transfer antar sekuritas (§18).
     *
     * Tanpa biaya, total kas global TIDAK berubah dan tidak ada satu pun akun
     * pendapatan/beban yang tersentuh (§40.5) — kedua sisinya adalah akun kas
     * yang sama, hanya berbeda dimensi rekening.
     *
     * Biaya transfer, bila ada, adalah peristiwa ekonomi tersendiri: uang benar-benar
     * keluar, jadi ia dicatat sebagai beban administrasi.
     */
    public function transfer(array $input): CashTransaction
    {
        $amount = $this->positiveMoney($input['amount'] ?? 0, 'Nominal transfer');
        $fee    = $this->nonNegativeMoney($input['fee'] ?? 0, 'Biaya');

        $sourceId      = $this->requireAccount($input['securities_account_id'] ?? 0);
        $destinationId = $this->requireAccount($input['counterpart_account_id'] ?? 0, 'Rekening tujuan');

        if ($sourceId === $destinationId) {
            throw new BusinessRuleException('Rekening asal dan rekening tujuan tidak boleh sama.');
        }

        $cashOut = $amount->add($fee);

        return $this->record(
            CashTransactionType::Transfer,
            $input,
            $sourceId,
            $destinationId,
            $amount,
            $fee,
            $cashOut->negate(),
            function (JournalDraft $draft) use ($amount, $fee, $cashOut, $sourceId, $destinationId): void {
                $draft->debit(AccountCode::Cash, $amount, $destinationId, null, 'Transfer masuk');
                $draft->debit(AccountCode::AdministrativeExpense, $fee, $sourceId, null, 'Biaya transfer');
                $draft->credit(AccountCode::Cash, $cashOut, $sourceId, null, 'Transfer keluar');
            },
        );
    }

    /**
     * Biaya administrasi rekening (§6 nomor 7).
     */
    public function adminFee(array $input): CashTransaction
    {
        $amount    = $this->positiveMoney($input['amount'] ?? 0, 'Nominal biaya');
        $accountId = $this->requireAccount($input['securities_account_id'] ?? 0);

        return $this->record(
            CashTransactionType::AdminFee,
            $input,
            $accountId,
            null,
            $amount,
            Money::zero(),
            $amount->negate(),
            function (JournalDraft $draft) use ($amount, $accountId): void {
                $draft->debit(AccountCode::AdministrativeExpense, $amount, $accountId, null, 'Biaya administrasi');
                $draft->credit(AccountCode::Cash, $amount, $accountId, null, 'Pembayaran biaya');
            },
        );
    }

    /**
     * Menyimpan transaksi dan jurnalnya sebagai satu kesatuan atomik (§8).
     *
     * @param callable(JournalDraft): void $buildJournal
     */
    private function record(
        CashTransactionType $type,
        array $input,
        int $accountId,
        ?int $counterpartId,
        Money $amount,
        Money $fee,
        Money $netAmount,
        callable $buildJournal,
    ): CashTransaction {
        $date = $this->requireDate($input['transaction_date'] ?? null);

        $db = db_connect();
        $db->transBegin();

        try {
            $number = $this->numbers->next(
                DocumentNumberService::PREFIX_CASH,
                $date,
                'cash_transactions',
                'transaction_number'
            );

            $id = $this->transactions->insert([
                'transaction_number'     => $number,
                'transaction_date'       => $date,
                'type'                   => $type->value,
                'securities_account_id'  => $accountId,
                'counterpart_account_id' => $counterpartId,
                'amount'                 => $amount->toDecimalString(),
                'fee'                    => $fee->toDecimalString(),
                'tax'                    => '0.00',
                'net_amount'             => $netAmount->toDecimalString(),
                'notes'                  => $input['notes'] ?? null,
                'status'                 => PostingStatus::Posted->value,
                'created_by'             => auth()->id(),
            ], true);

            if ($id === false) {
                throw new BusinessRuleException(
                    'Transaksi kas gagal disimpan.',
                    array_values($this->transactions->errors())
                );
            }

            $draft = new JournalDraft(
                $date,
                $type->label() . ' — ' . $number,
                SourceType::Cash,
                $id,
            );

            $buildJournal($draft);

            $entry = $this->poster->post($draft, auth()->id());

            $this->transactions->update($id, ['journal_entry_id' => $entry->id]);

            $this->audit->record(
                'created',
                'cash_transaction',
                $id,
                sprintf('%s %s pada %s', $type->label(), $amount->toDecimalString(), $date),
                null,
                ['transaction_number' => $number, 'journal' => $entry->entry_number],
            );

            $db->transCommit();

            return $this->transactions->find($id);
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    private function requireAccount(mixed $id, string $label = 'Rekening sekuritas'): int
    {
        $id = (int) $id;

        if ($id <= 0) {
            throw new BusinessRuleException($label . ' wajib dipilih.');
        }

        $account = $this->accounts->find($id);

        if ($account === null) {
            throw new BusinessRuleException($label . ' tidak ditemukan.');
        }

        if (! $account->is_active) {
            throw new BusinessRuleException(sprintf(
                '%s "%s" berstatus nonaktif dan tidak dapat menerima transaksi baru.',
                $label,
                $account->label
            ));
        }

        return $id;
    }

    private function requireDate(mixed $date): string
    {
        $date = trim((string) $date);

        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new BusinessRuleException('Tanggal transaksi wajib diisi dengan format YYYY-MM-DD.');
        }

        return $date;
    }

    private function positiveMoney(mixed $value, string $label): Money
    {
        $money = $this->nonNegativeMoney($value, $label);

        if ($money->isZero()) {
            throw new BusinessRuleException($label . ' harus lebih besar dari nol.');
        }

        return $money;
    }

    private function nonNegativeMoney(mixed $value, string $label): Money
    {
        if ($value === null || $value === '') {
            return Money::zero();
        }

        try {
            $money = Money::of(is_string($value) ? $value : (float) $value);
        } catch (\InvalidArgumentException) {
            throw new BusinessRuleException($label . ' bukan nilai yang sah.');
        }

        if ($money->isNegative()) {
            throw new BusinessRuleException($label . ' tidak boleh negatif.');
        }

        return $money;
    }
}
