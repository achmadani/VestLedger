<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\PostingStatus;
use App\ValueObjects\Money;
use App\ValueObjects\Price;
use CodeIgniter\Entity\Entity;

/**
 * @property int    $id
 * @property string $transaction_number
 * @property int    $quantity_eligible
 */
class DividendTransaction extends Entity
{
    protected $casts = [
        'id'                    => 'int',
        'securities_account_id' => 'int',
        'stock_id'              => 'int',
        'quantity_eligible'     => 'int',
        'journal_entry_id'      => '?int',
        'created_by'            => '?int',
    ];

    protected $dates = ['transaction_date', 'created_at', 'updated_at'];

    public function status(): PostingStatus
    {
        return PostingStatus::from($this->attributes['status']);
    }

    public function dividendPerShare(): Price
    {
        return Price::of((string) $this->attributes['dividend_per_share']);
    }

    public function grossDividend(): Money
    {
        return Money::of((string) $this->attributes['gross_dividend']);
    }

    public function tax(): Money
    {
        return Money::of((string) $this->attributes['tax']);
    }

    public function netDividend(): Money
    {
        return Money::of((string) $this->attributes['net_dividend']);
    }
}
