<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\AccountType;
use App\Enums\BalanceSide;
use CodeIgniter\Entity\Entity;

/**
 * Akun dalam Chart of Accounts.
 *
 * @property int         $id
 * @property string      $code
 * @property string      $name
 * @property string      $type
 * @property string      $normal_balance
 * @property int|null    $parent_id
 * @property bool        $is_postable
 * @property bool        $is_system
 * @property bool        $is_active
 */
class Account extends Entity
{
    protected $casts = [
        'id'          => 'int',
        'parent_id'   => '?int',
        'is_postable' => 'boolean',
        'is_system'   => 'boolean',
        'is_active'   => 'boolean',
    ];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    public function type(): AccountType
    {
        return AccountType::from($this->attributes['type']);
    }

    public function normalBalance(): BalanceSide
    {
        return BalanceSide::from($this->attributes['normal_balance']);
    }

    /**
     * Akun kontra: saldo normalnya berlawanan dengan saldo normal tipenya
     * (mis. 3200 Owner Withdrawal — bertipe ekuitas tetapi bersaldo normal debit).
     */
    public function isContra(): bool
    {
        return $this->normalBalance() !== $this->type()->normalBalance();
    }

    public function displayName(): string
    {
        return $this->code . ' — ' . $this->name;
    }
}
