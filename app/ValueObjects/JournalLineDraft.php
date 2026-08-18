<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AccountCode;
use App\Enums\BalanceSide;

/**
 * Satu baris jurnal yang belum disimpan.
 */
final class JournalLineDraft
{
    /**
     * @param AccountCode|int $account AccountCode untuk akun inti, atau id akun
     *                                 mentah — dibutuhkan saat membalik jurnal
     *                                 yang menyentuh akun buatan pengguna.
     */
    public function __construct(
        public readonly AccountCode|int $account,
        public readonly BalanceSide $side,
        public readonly Money $amount,
        public readonly ?int $securitiesAccountId = null,
        public readonly ?int $stockId = null,
        public readonly ?string $memo = null,
    ) {
    }

    /**
     * Dimensi yang diwajibkan hanya diketahui untuk akun inti; akun buatan
     * pengguna tidak memiliki kewajiban dimensi.
     */
    public function requiresSecuritiesDimension(): bool
    {
        return $this->account instanceof AccountCode && $this->account->requiresSecuritiesDimension();
    }

    public function requiresStockDimension(): bool
    {
        return $this->account instanceof AccountCode && $this->account->requiresStockDimension();
    }

    public function accountLabel(): string
    {
        return $this->account instanceof AccountCode
            ? $this->account->value . ' ' . $this->account->label()
            : 'akun #' . $this->account;
    }

    public function isDebit(): bool
    {
        return $this->side === BalanceSide::Debit;
    }

    public function debit(): Money
    {
        return $this->isDebit() ? $this->amount : Money::zero();
    }

    public function credit(): Money
    {
        return $this->isDebit() ? Money::zero() : $this->amount;
    }
}
