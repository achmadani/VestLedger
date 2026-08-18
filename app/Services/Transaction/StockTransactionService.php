<?php

declare(strict_types=1);

namespace App\Services\Transaction;

use App\Entities\StockTransaction;
use App\Enums\AccountCode;
use App\Enums\PostingStatus;
use App\Enums\SourceType;
use App\Enums\StockTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\Models\StockTransactionModel;
use App\Services\Accounting\AuditLogger;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\JournalPoster;
use App\Services\Portfolio\PositionService;
use App\ValueObjects\JournalDraft;
use App\ValueObjects\Money;
use App\ValueObjects\Price;

/**
 * Pembelian dan penjualan saham (§10, §11, §12).
 *
 * Seluruh perhitungannya didokumentasikan di docs/ACCOUNTING.md; kode ini
 * adalah penerapannya, bukan tempat aturan baru diputuskan.
 */
class StockTransactionService
{
    public function __construct(
        private StockTransactionModel $transactions,
        private SecuritiesAccountModel $accounts,
        private StockModel $stocks,
        private PositionService $positions,
        private JournalPoster $poster,
        private DocumentNumberService $numbers,
        private AuditLogger $audit,
    ) {
    }

    /**
     * BELI (§10).
     *
     * Seluruh biaya perolehan — broker fee, pajak, dan levy — dikapitalisasi ke
     * dalam book cost, sesuai keputusan yang dicatat di docs/ACCOUNTING.md.
     * Karena itu pembelian TIDAK pernah menyentuh akun beban.
     *
     * @param array{transaction_date:string, securities_account_id:int, stock_id:int, quantity:mixed, price:mixed, broker_fee?:mixed, tax?:mixed, levy?:mixed, notes?:?string} $input
     */
    public function buy(array $input): StockTransaction
    {
        $context = $this->prepare($input, StockTransactionType::Buy);

        $bookCost = $context['gross']->add($context['charges']);

        $db = db_connect();
        $db->transBegin();

        try {
            $before = $this->positions->current($context['accountId'], $context['stockId']);

            $id = $this->insert($context, [
                'net_amount'        => $bookCost->toDecimalString(),
                'quantity_before'   => $before->quantity,
                'book_value_before' => $before->bookValue()->toDecimalString(),
            ]);

            $after = $this->positions->applyBuy(
                $context['accountId'],
                $context['stockId'],
                $context['quantity'],
                $bookCost,
                $context['date'],
            );

            $this->transactions->update($id, [
                'quantity_after'   => $after->quantity,
                'book_value_after' => $after->bookValue()->toDecimalString(),
            ]);

            $draft = new JournalDraft(
                $context['date'],
                sprintf('Beli %s %s lembar — %s', $context['ticker'], $context['quantity'], $context['number']),
                SourceType::Stock,
                $id,
            );

            $draft->debit(
                AccountCode::StockPortfolio,
                $bookCost,
                $context['accountId'],
                $context['stockId'],
                'Book cost termasuk seluruh biaya perolehan',
            );
            $draft->credit(AccountCode::Cash, $bookCost, $context['accountId'], null, 'Pembayaran pembelian');

            $this->finish($id, $draft, $context, sprintf(
                'Beli %s %s lembar @ %s',
                $context['ticker'],
                $context['quantity'],
                $context['price']->toDecimalString()
            ));

            $db->transCommit();

            return $this->transactions->find($id);
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * JUAL (§11).
     *
     * Jurnal mencatat fee dan pajak sebagai beban terpisah, dan realized gain
     * sebesar gross − book value sold. Metrik laporan "Realized G/L (net)"
     * (§11 Step 3) disimpan terpisah pada kolom realized_gain_net.
     */
    public function sell(array $input): StockTransaction
    {
        $context = $this->prepare($input, StockTransactionType::Sell);

        $db = db_connect();
        $db->transBegin();

        try {
            $before = $this->positions->assertCanSell(
                $context['accountId'],
                $context['stockId'],
                $context['quantity'],
                $context['ticker'],
            );

            $bookValueSold = $this->positions->bookValueForSale($before, $context['quantity']);
            $netProceeds   = $context['gross']->subtract($context['charges']);
            $realizedGross = $context['gross']->subtract($bookValueSold);
            $realizedNet   = $realizedGross->subtract($context['charges']);

            $id = $this->insert($context, [
                'net_amount'          => $netProceeds->toDecimalString(),
                'book_value_sold'     => $bookValueSold->toDecimalString(),
                'realized_gain_gross' => $realizedGross->toDecimalString(),
                'realized_gain_net'   => $realizedNet->toDecimalString(),
                'quantity_before'     => $before->quantity,
                'book_value_before'   => $before->bookValue()->toDecimalString(),
            ]);

            $after = $this->positions->applySell(
                $context['accountId'],
                $context['stockId'],
                $context['quantity'],
                $bookValueSold,
                $context['date'],
            );

            $this->transactions->update($id, [
                'quantity_after'   => $after->quantity,
                'book_value_after' => $after->bookValue()->toDecimalString(),
            ]);

            $draft = new JournalDraft(
                $context['date'],
                sprintf('Jual %s %s lembar — %s', $context['ticker'], $context['quantity'], $context['number']),
                SourceType::Stock,
                $id,
            );

            $draft->debit(AccountCode::Cash, $netProceeds, $context['accountId'], null, 'Penerimaan penjualan');
            $draft->debit(AccountCode::BrokerFee, $context['brokerFee'], $context['accountId'], null, 'Fee penjualan');
            $draft->debit(
                AccountCode::TaxAndLevy,
                $context['tax']->add($context['levy']),
                $context['accountId'],
                null,
                'Pajak & levy penjualan',
            );
            $draft->credit(
                AccountCode::StockPortfolio,
                $bookValueSold,
                $context['accountId'],
                $context['stockId'],
                'Book value yang dilepas',
            );

            // Untung dikreditkan ke 4000; rugi didebitkan ke 4001. Keduanya
            // dicatat sebagai nilai positif di sisi yang benar, bukan sebagai
            // nilai negatif di satu akun.
            if ($realizedGross->isNegative()) {
                $draft->debit(AccountCode::RealizedLoss, $realizedGross->abs(), $context['accountId'], $context['stockId'], 'Rugi realisasi');
            } else {
                $draft->credit(AccountCode::RealizedGain, $realizedGross, $context['accountId'], $context['stockId'], 'Laba realisasi');
            }

            $this->finish($id, $draft, $context, sprintf(
                'Jual %s %s lembar @ %s, realized (net) %s',
                $context['ticker'],
                $context['quantity'],
                $context['price']->toDecimalString(),
                $realizedNet->toDecimalString()
            ));

            $db->transCommit();

            return $this->transactions->find($id);
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Validasi masukan dan perhitungan yang sama untuk beli maupun jual (§27).
     *
     * @return array<string, mixed>
     */
    private function prepare(array $input, StockTransactionType $type): array
    {
        $date     = $this->requireDate($input['transaction_date'] ?? null);
        $quantity = (int) ($input['quantity'] ?? 0);

        if ($quantity <= 0) {
            throw new BusinessRuleException('Jumlah lembar harus lebih besar dari nol.');
        }

        $price = $this->requirePrice($input['price'] ?? null);

        $account = $this->accounts->find((int) ($input['securities_account_id'] ?? 0));

        if ($account === null) {
            throw new BusinessRuleException('Rekening sekuritas wajib dipilih.');
        }

        if (! $account->is_active) {
            throw new BusinessRuleException(sprintf('Rekening "%s" nonaktif dan tidak menerima transaksi baru.', $account->label));
        }

        $stock = $this->stocks->find((int) ($input['stock_id'] ?? 0));

        if ($stock === null) {
            throw new BusinessRuleException('Saham wajib dipilih.');
        }

        if (! $stock->is_active) {
            throw new BusinessRuleException(sprintf('Saham %s berstatus nonaktif.', $stock->ticker));
        }

        $brokerFee = $this->charge($input['broker_fee'] ?? 0, 'Broker fee');
        $tax       = $this->charge($input['tax'] ?? 0, 'Pajak');
        $levy      = $this->charge($input['levy'] ?? 0, 'Levy');

        return [
            'date'      => $date,
            'type'      => $type,
            'accountId' => $account->id,
            'stockId'   => $stock->id,
            'ticker'    => $stock->ticker,
            'quantity'  => $quantity,
            'price'     => $price,
            'gross'     => $price->multiplyByQuantity($quantity),
            'brokerFee' => $brokerFee,
            'tax'       => $tax,
            'levy'      => $levy,
            'charges'   => $brokerFee->add($tax)->add($levy),
            'notes'     => $input['notes'] ?? null,
            'number'    => $this->numbers->next(
                DocumentNumberService::PREFIX_STOCK,
                $date,
                'stock_transactions',
                'transaction_number'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function insert(array $context, array $extra): int
    {
        $id = $this->transactions->insert([
            'transaction_number'    => $context['number'],
            'transaction_date'      => $context['date'],
            'type'                  => $context['type']->value,
            'securities_account_id' => $context['accountId'],
            'stock_id'              => $context['stockId'],
            'quantity'              => $context['quantity'],
            'lots'                  => number_format(
                config(\Config\Investment::class)->sharesToLots($context['quantity']),
                4,
                '.',
                ''
            ),
            'price'                 => $context['price']->toDecimalString(),
            'gross_amount'          => $context['gross']->toDecimalString(),
            'broker_fee'            => $context['brokerFee']->toDecimalString(),
            'tax'                   => $context['tax']->toDecimalString(),
            'levy'                  => $context['levy']->toDecimalString(),
            'notes'                 => $context['notes'],
            'status'                => PostingStatus::Posted->value,
            'created_by'            => auth()->id(),
        ] + $extra, true);

        if ($id === false) {
            throw new BusinessRuleException(
                'Transaksi saham gagal disimpan.',
                array_values($this->transactions->errors())
            );
        }

        return $id;
    }

    private function finish(int $id, JournalDraft $draft, array $context, string $summary): void
    {
        $entry = $this->poster->post($draft, auth()->id());

        $this->transactions->update($id, ['journal_entry_id' => $entry->id]);

        $this->audit->record(
            'created',
            'stock_transaction',
            $id,
            $summary,
            null,
            ['transaction_number' => $context['number'], 'journal' => $entry->entry_number],
        );
    }

    private function requireDate(mixed $date): string
    {
        $date = trim((string) $date);

        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new BusinessRuleException('Tanggal transaksi wajib diisi dengan format YYYY-MM-DD.');
        }

        return $date;
    }

    private function requirePrice(mixed $value): Price
    {
        if ($value === null || $value === '') {
            throw new BusinessRuleException('Harga wajib diisi.');
        }

        try {
            $price = Price::of(is_string($value) ? $value : (float) $value);
        } catch (\InvalidArgumentException) {
            throw new BusinessRuleException('Harga bukan nilai yang sah.');
        }

        if (! $price->isPositive()) {
            throw new BusinessRuleException('Harga harus lebih besar dari nol.');
        }

        return $price;
    }

    private function charge(mixed $value, string $label): Money
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
