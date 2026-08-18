<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\JournalEntryType;
use App\Enums\PostingStatus;
use App\Enums\SourceType;
use CodeIgniter\Entity\Entity;

/**
 * @property int         $id
 * @property string      $entry_number
 * @property string      $description
 * @property int|null    $source_id
 * @property int|null    $reverses_entry_id
 */
class JournalEntry extends Entity
{
    protected $casts = [
        'id'                   => 'int',
        'accounting_period_id' => 'int',
        'source_id'            => '?int',
        'reverses_entry_id'    => '?int',
        'created_by'           => '?int',
    ];

    protected $dates = ['entry_date', 'created_at', 'updated_at'];

    public function type(): JournalEntryType
    {
        return JournalEntryType::from($this->attributes['type']);
    }

    public function sourceType(): SourceType
    {
        return SourceType::from($this->attributes['source_type']);
    }

    public function status(): PostingStatus
    {
        return PostingStatus::from($this->attributes['status']);
    }

    public function isReversed(): bool
    {
        return $this->status() === PostingStatus::Reversed;
    }

    public function isReversal(): bool
    {
        return $this->type() === JournalEntryType::Reversal;
    }
}
