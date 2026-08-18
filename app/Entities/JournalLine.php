<?php

declare(strict_types=1);

namespace App\Entities;

use App\ValueObjects\Money;
use CodeIgniter\Entity\Entity;

/**
 * @property int         $id
 * @property int         $journal_entry_id
 * @property int         $account_id
 * @property string|null $memo
 */
class JournalLine extends Entity
{
    protected $casts = [
        'id'                    => 'int',
        'journal_entry_id'      => 'int',
        'line_no'               => 'int',
        'account_id'            => 'int',
        'securities_account_id' => '?int',
        'stock_id'              => '?int',
    ];

    public function debit(): Money
    {
        return Money::of((string) $this->attributes['debit']);
    }

    public function credit(): Money
    {
        return Money::of((string) $this->attributes['credit']);
    }

    /**
     * Nilai bertanda dari sudut pandang debit: positif untuk debit,
     * negatif untuk kredit. Dipakai menghitung saldo berjalan buku besar.
     */
    public function signedAmount(): Money
    {
        return $this->debit()->subtract($this->credit());
    }
}
