<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountCode;
use App\Enums\JournalEntryType;
use App\Enums\SourceType;
use App\Exceptions\BusinessRuleException;
use App\Models\OpeningBalanceModel;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\Models\StockPositionModel;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\Money;
use App\ValueObjects\Price;

/**
 * Saldo awal (§19).
 *
 * Dipakai sekali, saat aplikasi mulai digunakan oleh investor yang sudah
 * memiliki kas dan posisi saham sebelumnya.
 *
 * Laba ditahan TIDAK dimasukkan pengguna melainkan dihitung sebagai angka
 * penyeimbang: aset − modal disetor. Meminta pengguna mengetiknya sendiri hanya
 * akan melahirkan saldo awal yang tidak balance, padahal angka itu memang
 * turunan — pemilik tahu berapa asetnya dan berapa yang ia setorkan, selisihnya
 * adalah akumulasi laba masa lalu.
 */
class OpeningBalanceService
{
    public function __construct(
        private OpeningBalanceModel $openings,
        private StockPositionModel $positions,
        private SecuritiesAccountModel $accounts,
        private StockModel $stocks,
        private JournalPoster $poster,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Menghitung ringkasan saldo awal tanpa menyimpannya, untuk pratinjau.
     *
     * @param array{cash?:array<int,mixed>, positions?:list<array<string,mixed>>, paid_in_capital?:mixed} $input
     *
     * @return array{cash:Money, portfolio:Money, assets:Money, capital:Money, retained:Money}
     */
    public function preview(array $input): array
    {
        $cash = Money::zero();

        foreach ((array) ($input['cash'] ?? []) as $amount) {
            $cash = $cash->add($this->money($amount, 'Saldo kas'));
        }

        $portfolio = Money::zero();

        foreach ((array) ($input['positions'] ?? []) as $row) {
            $quantity = (int) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $portfolio = $portfolio->add($this->money($row['book_value'] ?? 0, 'Book value'));
        }

        $assets  = $cash->add($portfolio);
        $capital = $this->money($input['paid_in_capital'] ?? 0, 'Modal disetor');

        return [
            'cash'      => $cash,
            'portfolio' => $portfolio,
            'assets'    => $assets,
            'capital'   => $capital,
            // Bisa negatif: itu berarti akumulasi rugi, dan tetap sah.
            'retained'  => $assets->subtract($capital),
        ];
    }

    /**
     * Menyimpan saldo awal beserta jurnal dan posisinya (§19).
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        if ($this->openings->hasAny()) {
            throw new BusinessRuleException(
                'Saldo awal sudah pernah dibuat. Hapus saldo awal yang lama terlebih dahulu '
                . 'bila ingin menggantinya.'
            );
        }

        $date = trim((string) ($input['as_of_date'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new BusinessRuleException('Tanggal saldo awal wajib diisi dengan format YYYY-MM-DD.');
        }

        $this->assertNoTransactionsOnOrBefore($date);

        $summary = $this->preview($input);

        if ($summary['assets']->isZero() && $summary['capital']->isZero()) {
            throw new BusinessRuleException('Saldo awal kosong: isi minimal satu saldo kas atau satu posisi saham.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $draft = new JournalDraft(
                $date,
                'Saldo awal per ' . $date,
                SourceType::Opening,
                null,
                JournalEntryType::Opening,
            );

            $rows = [];

            // --- Kas per rekening
            foreach ((array) ($input['cash'] ?? []) as $accountId => $amount) {
                $money = $this->money($amount, 'Saldo kas');

                if ($money->isZero()) {
                    continue;
                }

                $account = $this->requireAccount((int) $accountId);

                $draft->debit(AccountCode::Cash, $money, $account->id, null, 'Saldo awal kas');

                $rows[] = [
                    'as_of_date'            => $date,
                    'kind'                  => 'cash',
                    'securities_account_id' => $account->id,
                    'amount'                => $money->toDecimalString(),
                ];
            }

            // --- Posisi saham per rekening
            foreach ((array) ($input['positions'] ?? []) as $row) {
                $quantity = (int) ($row['quantity'] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                $bookValue = $this->money($row['book_value'] ?? 0, 'Book value');

                if ($bookValue->isZero()) {
                    throw new BusinessRuleException(
                        'Posisi saham dengan jumlah lembar tetapi tanpa book value tidak dapat disimpan: '
                        . 'average cost-nya akan menjadi nol.'
                    );
                }

                $account = $this->requireAccount((int) ($row['securities_account_id'] ?? 0));
                $stock   = $this->stocks->find((int) ($row['stock_id'] ?? 0));

                if ($stock === null) {
                    throw new BusinessRuleException('Saham pada salah satu baris posisi tidak ditemukan.');
                }

                $draft->debit(
                    AccountCode::StockPortfolio,
                    $bookValue,
                    $account->id,
                    $stock->id,
                    sprintf('Saldo awal %s %s lembar', $stock->ticker, number_format($quantity, 0, ',', '.')),
                );

                $rows[] = [
                    'as_of_date'            => $date,
                    'kind'                  => 'stock',
                    'securities_account_id' => $account->id,
                    'stock_id'              => $stock->id,
                    'quantity'              => $quantity,
                    'amount'                => $bookValue->toDecimalString(),
                ];

                $this->positions->insert([
                    'securities_account_id' => $account->id,
                    'stock_id'              => $stock->id,
                    'quantity'              => $quantity,
                    'book_value'            => $bookValue->toDecimalString(),
                    'last_transaction_date' => $date,
                ]);
            }

            // --- Sisi kredit: modal disetor dan laba ditahan
            $draft->credit(AccountCode::PaidInCapital, $summary['capital'], null, null, 'Modal disetor awal');

            if (! $summary['retained']->isZero()) {
                // Nilai negatif otomatis dibalik ke sisi debit oleh JournalDraft,
                // sehingga akumulasi rugi tercatat dengan arah yang benar.
                $draft->credit(AccountCode::RetainedEarnings, $summary['retained'], null, null, 'Laba ditahan awal (angka penyeimbang)');
            }

            $entry = $this->poster->post($draft, auth()->id());

            foreach ($rows as $row) {
                $this->openings->insert($row + ['journal_entry_id' => $entry->id, 'created_by' => auth()->id()]);
            }

            $this->openings->insert([
                'as_of_date'       => $date,
                'kind'             => 'paid_in_capital',
                'amount'           => $summary['capital']->toDecimalString(),
                'journal_entry_id' => $entry->id,
                'created_by'       => auth()->id(),
            ]);

            $this->openings->insert([
                'as_of_date'       => $date,
                'kind'             => 'retained_earnings',
                'amount'           => $summary['retained']->toDecimalString(),
                'notes'            => 'Angka penyeimbang: aset dikurangi modal disetor',
                'journal_entry_id' => $entry->id,
                'created_by'       => auth()->id(),
            ]);

            $this->audit->record(
                'created',
                'opening_balance',
                null,
                sprintf(
                    'Saldo awal per %s: aset %s, modal %s, laba ditahan %s',
                    $date,
                    $summary['assets']->toDecimalString(),
                    $summary['capital']->toDecimalString(),
                    $summary['retained']->toDecimalString()
                ),
                null,
                ['journal' => $entry->entry_number],
            );

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Menghapus saldo awal.
     *
     * Jurnalnya dibalik, bukan dihapus (§40.8). Hanya diizinkan bila belum ada
     * transaksi lain sama sekali — begitu ada transaksi yang dibangun di atas
     * posisi awal, average cost-nya sudah ikut terpakai dan menghapus dasarnya
     * akan membuat realized gain/loss yang terlanjur dicatat menjadi salah.
     */
    public function reset(): void
    {
        $batch = $this->openings->currentBatch();

        if ($batch === []) {
            throw new BusinessRuleException('Belum ada saldo awal yang dapat dihapus.');
        }

        foreach (['cash_transactions', 'stock_transactions', 'dividend_transactions'] as $table) {
            if (db_connect()->table($table)->countAllResults() > 0) {
                throw new BusinessRuleException(
                    'Saldo awal tidak dapat dihapus karena sudah ada transaksi yang tercatat. '
                    . 'Transaksi tersebut dibangun di atas posisi awal ini; menghapusnya akan membuat '
                    . 'average cost dan realized gain/loss yang sudah tercatat menjadi salah.'
                );
            }
        }

        $journalId = $batch[0]['journal_entry_id'] ?? null;
        $date      = $batch[0]['as_of_date'];

        $db = db_connect();
        $db->transBegin();

        try {
            if ($journalId !== null) {
                $this->poster->reverse((int) $journalId, (string) $date, 'Pembatalan saldo awal per ' . $date, auth()->id());
            }

            $this->positions->where('last_transaction_date', $date)->delete();
            $this->openings->where('as_of_date', $date)->delete();

            $this->audit->record('reversed', 'opening_balance', null, 'Saldo awal per ' . $date . ' dihapus');

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Ringkasan saldo awal yang tersimpan, untuk ditampilkan.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $batch = $this->openings->currentBatch();

        if ($batch === []) {
            return [];
        }

        $cash      = Money::zero();
        $portfolio = Money::zero();
        $capital   = Money::zero();
        $retained  = Money::zero();
        $positions = [];

        foreach ($batch as $row) {
            $amount = Money::of((string) $row['amount']);

            match ($row['kind']) {
                'cash'              => $cash = $cash->add($amount),
                'stock'             => [$portfolio = $portfolio->add($amount), $positions[] = $row],
                'paid_in_capital'   => $capital = $amount,
                'retained_earnings' => $retained = $amount,
            };
        }

        return [
            'as_of_date' => $batch[0]['as_of_date'],
            'cash'       => $cash,
            'portfolio'  => $portfolio,
            'assets'     => $cash->add($portfolio),
            'capital'    => $capital,
            'retained'   => $retained,
            'positions'  => $positions,
            'rows'       => $batch,
        ];
    }

    private function assertNoTransactionsOnOrBefore(string $date): void
    {
        foreach ([
            'cash_transactions'     => 'transaksi kas',
            'stock_transactions'    => 'transaksi saham',
            'dividend_transactions' => 'dividen',
        ] as $table => $label) {
            $count = db_connect()->table($table)->where('transaction_date <=', $date)->countAllResults();

            if ($count > 0) {
                throw new BusinessRuleException(sprintf(
                    'Sudah ada %d %s bertanggal pada atau sebelum %s. Saldo awal harus mendahului '
                    . 'seluruh transaksi, jika tidak ia bukan lagi saldo AWAL.',
                    $count,
                    $label,
                    $date
                ));
            }
        }
    }

    private function requireAccount(int $id): \App\Entities\SecuritiesAccount
    {
        $account = $this->accounts->find($id);

        if ($account === null) {
            throw new BusinessRuleException('Rekening sekuritas pada saldo awal tidak ditemukan.');
        }

        return $account;
    }

    private function money(mixed $value, string $label): Money
    {
        if ($value === null || trim((string) $value) === '') {
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
