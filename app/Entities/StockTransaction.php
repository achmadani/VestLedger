<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\PostingStatus;
use App\Enums\StockTransactionType;
use App\ValueObjects\Money;
use App\ValueObjects\Price;
use CodeIgniter\Entity\Entity;

/**
 * @property int    $id
 * @property string $transaction_number
 * @property int    $quantity
 * @property int    $securities_account_id
 * @property int    $stock_id
 */
class StockTransaction extends Entity
{
    protected $casts = [
        'id'                    => 'int',
        'securities_account_id' => 'int',
        'stock_id'              => 'int',
        'quantity'              => 'int',
        'quantity_before'       => '?int',
        'quantity_after'        => '?int',
        'journal_entry_id'      => '?int',
        'created_by'            => '?int',
    ];

    protected $dates = ['transaction_date', 'created_at', 'updated_at'];

    public function type(): StockTransactionType
    {
        return StockTransactionType::from($this->attributes['type']);
    }

    public function status(): PostingStatus
    {
        return PostingStatus::from($this->attributes['status']);
    }

    public function isBuy(): bool
    {
        return $this->type() === StockTransactionType::Buy;
    }

    public function price(): Price
    {
        return Price::of((string) $this->attributes['price']);
    }

    public function grossAmount(): Money
    {
        return Money::of((string) $this->attributes['gross_amount']);
    }

    public function netAmount(): Money
    {
        return Money::of((string) $this->attributes['net_amount']);
    }

    /**
     * Seluruh biaya transaksi: broker fee + pajak + levy.
     */
    public function totalCharges(): Money
    {
        return Money::of((string) $this->attributes['broker_fee'])
            ->add(Money::of((string) $this->attributes['tax']))
            ->add(Money::of((string) $this->attributes['levy']));
    }

    public function bookValueSold(): ?Money
    {
        return isset($this->attributes['book_value_sold']) && $this->attributes['book_value_sold'] !== null
            ? Money::of((string) $this->attributes['book_value_sold'])
            : null;
    }

    /**
     * Realized gain yang masuk akun 4000/4001 (gross − book value sold).
     */
    public function realizedGainGross(): ?Money
    {
        return isset($this->attributes['realized_gain_gross']) && $this->attributes['realized_gain_gross'] !== null
            ? Money::of((string) $this->attributes['realized_gain_gross'])
            : null;
    }

    /**
     * Realized gain versi laporan §11 Step 3 (setelah fee dan pajak).
     */
    public function realizedGainNet(): ?Money
    {
        return isset($this->attributes['realized_gain_net']) && $this->attributes['realized_gain_net'] !== null
            ? Money::of((string) $this->attributes['realized_gain_net'])
            : null;
    }
}
