<?php

declare(strict_types=1);

namespace App\Entities;

use App\ValueObjects\Money;
use App\ValueObjects\Price;
use CodeIgniter\Entity\Entity;

/**
 * Posisi saham pada satu rekening sekuritas.
 *
 * @property int $id
 * @property int $securities_account_id
 * @property int $stock_id
 * @property int $quantity
 */
class StockPosition extends Entity
{
    protected $casts = [
        // Nullable: posisi yang belum pernah disimpan memang belum punya id,
        // dan cast 'int' akan mengubah null menjadi 0 — membuatnya tampak
        // seperti baris yang sudah ada.
        'id'                    => '?int',
        'securities_account_id' => 'int',
        'stock_id'              => 'int',
        'quantity'              => 'int',
    ];

    protected $dates = ['last_transaction_date', 'created_at', 'updated_at'];

    public function bookValue(): Money
    {
        return Money::of((string) ($this->attributes['book_value'] ?? '0'));
    }

    /**
     * Average cost SELALU diturunkan, tidak pernah dibaca dari kolom tersimpan.
     */
    public function averageCost(): Price
    {
        return Price::averageOf($this->bookValue(), $this->quantity);
    }

    public function isEmpty(): bool
    {
        return $this->quantity <= 0;
    }
}
