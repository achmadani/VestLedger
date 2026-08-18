<?php

declare(strict_types=1);

namespace App\Entities;

use App\ValueObjects\Price;
use CodeIgniter\Entity\Entity;

/**
 * @property int $id
 * @property int $stock_id
 */
class MarketPrice extends Entity
{
    protected $casts = [
        'id'         => '?int',
        'stock_id'   => 'int',
        'created_by' => '?int',
    ];

    protected $dates = ['price_date', 'created_at', 'updated_at'];

    public function closingPrice(): Price
    {
        return Price::of((string) $this->attributes['closing_price']);
    }
}
