<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\CashTransactionType;
use App\Enums\PostingStatus;
use App\ValueObjects\Money;
use CodeIgniter\Entity\Entity;

/**
 * @property int         $id
 * @property string      $transaction_number
 * @property int         $securities_account_id
 * @property int|null    $counterpart_account_id
 * @property string|null $notes
 */
class CashTransaction extends Entity
{
    protected $casts = [
        'id'                     => 'int',
        'securities_account_id'   => 'int',
        'counterpart_account_id'  => '?int',
        'journal_entry_id'        => '?int',
        'created_by'              => '?int',
    ];

    protected $dates = ['transaction_date', 'created_at', 'updated_at'];

    public function type(): CashTransactionType
    {
        return CashTransactionType::from($this->attributes['type']);
    }

    public function status(): PostingStatus
    {
        return PostingStatus::from($this->attributes['status']);
    }

    public function amount(): Money
    {
        return Money::of((string) $this->attributes['amount']);
    }

    public function netAmount(): Money
    {
        return Money::of((string) $this->attributes['net_amount']);
    }
}
