<?php

declare(strict_types=1);

namespace App\Services\Transaction;

use App\Entities\DividendTransaction;
use App\Enums\AccountCode;
use App\Enums\PostingStatus;
use App\Enums\SourceType;
use App\Exceptions\BusinessRuleException;
use App\Models\DividendTransactionModel;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\Services\Accounting\AuditLogger;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\JournalPoster;
use App\Services\Portfolio\PositionService;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\Money;
use App\ValueObjects\Price;

/**
 * Penerimaan dividen (§15).
 *
 * Dividen adalah PENDAPATAN — berbeda dari top up yang merupakan setoran modal
 * (§40.3). Pendapatan dicatat sebesar dividen bruto, dan pajaknya menjadi beban
 * tersendiri, bukan dikurangkan diam-diam dari pendapatan.
 */
class DividendTransactionService
{
    public function __construct(
        private DividendTransactionModel $transactions,
        private SecuritiesAccountModel $accounts,
        private StockModel $stocks,
        private PositionService $positions,
        private JournalPoster $poster,
        private DocumentNumberService $numbers,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @param array{transaction_date:string, securities_account_id:int, stock_id:int, quantity_eligible:mixed, dividend_per_share:mixed, tax?:mixed, notes?:?string} $input
     */
    public function record(array $input): DividendTransaction
    {
        $date = trim((string) ($input['transaction_date'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new BusinessRuleException('Tanggal dividen wajib diisi dengan format YYYY-MM-DD.');
        }

        $account = $this->accounts->find((int) ($input['securities_account_id'] ?? 0));

        if ($account === null || ! $account->is_active) {
            throw new BusinessRuleException('Rekening sekuritas wajib dipilih dan harus berstatus aktif.');
        }

        $stock = $this->stocks->find((int) ($input['stock_id'] ?? 0));

        if ($stock === null) {
            throw new BusinessRuleException('Saham wajib dipilih.');
        }

        $quantity = (int) ($input['quantity_eligible'] ?? 0);

        if ($quantity <= 0) {
            throw new BusinessRuleException('Jumlah lembar yang berhak dividen harus lebih besar dari nol.');
        }

        $perShare = Price::of(is_string($input['dividend_per_share'] ?? '') ? $input['dividend_per_share'] : (float) ($input['dividend_per_share'] ?? 0));

        if (! $perShare->isPositive()) {
            throw new BusinessRuleException('Dividen per lembar harus lebih besar dari nol.');
        }

        $gross = $perShare->multiplyByQuantity($quantity);
        $tax   = $this->tax($input['tax'] ?? 0);

        if ($tax->greaterThan($gross)) {
            throw new BusinessRuleException('Pajak dividen tidak boleh melebihi dividen bruto.');
        }

        $net = $gross->subtract($tax);

        // Peringatan lunak: dividen atas saham yang tidak dimiliki di rekening
        // ini hampir selalu berarti salah pilih rekening. Tidak diblokir, karena
        // dividen bisa saja dibayarkan setelah posisi dijual habis.
        $position = $this->positions->current($account->id, $stock->id);

        $db = db_connect();
        $db->transBegin();

        try {
            $number = $this->numbers->next(
                DocumentNumberService::PREFIX_DIVIDEND,
                $date,
                'dividend_transactions',
                'transaction_number'
            );

            $id = $this->transactions->insert([
                'transaction_number'    => $number,
                'transaction_date'      => $date,
                'securities_account_id' => $account->id,
                'stock_id'              => $stock->id,
                'quantity_eligible'     => $quantity,
                'dividend_per_share'    => $perShare->toDecimalString(),
                'gross_dividend'        => $gross->toDecimalString(),
                'tax'                   => $tax->toDecimalString(),
                'net_dividend'          => $net->toDecimalString(),
                'notes'                 => $input['notes'] ?? null,
                'status'                => PostingStatus::Posted->value,
                'created_by'            => auth()->id(),
            ], true);

            if ($id === false) {
                throw new BusinessRuleException(
                    'Dividen gagal disimpan.',
                    array_values($this->transactions->errors())
                );
            }

            $draft = new JournalDraft(
                $date,
                sprintf('Dividen %s — %s', $stock->ticker, $number),
                SourceType::Dividend,
                $id,
            );

            $draft->debit(AccountCode::Cash, $net, $account->id, null, 'Dividen diterima');
            $draft->debit(AccountCode::TaxAndLevy, $tax, $account->id, null, 'Pajak dividen');
            $draft->credit(AccountCode::DividendIncome, $gross, $account->id, $stock->id, 'Dividen bruto');

            $entry = $this->poster->post($draft, auth()->id());

            $this->transactions->update($id, ['journal_entry_id' => $entry->id]);

            $this->audit->record(
                'created',
                'dividend_transaction',
                $id,
                sprintf('Dividen %s %s lembar, bruto %s', $stock->ticker, $quantity, $gross->toDecimalString()),
                null,
                [
                    'transaction_number' => $number,
                    'journal'            => $entry->entry_number,
                    'posisi_saat_itu'    => $position->quantity,
                ],
            );

            $db->transCommit();

            return $this->transactions->find($id);
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    private function tax(mixed $value): Money
    {
        if ($value === null || $value === '') {
            return Money::zero();
        }

        try {
            $money = Money::of(is_string($value) ? $value : (float) $value);
        } catch (\InvalidArgumentException) {
            throw new BusinessRuleException('Pajak dividen bukan nilai yang sah.');
        }

        if ($money->isNegative()) {
            throw new BusinessRuleException('Pajak dividen tidak boleh negatif.');
        }

        return $money;
    }
}
