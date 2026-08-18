<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Jenis transaksi saham (§10, §11).
 */
enum StockTransactionType: string
{
    case Buy  = 'buy';
    case Sell = 'sell';

    public function label(): string
    {
        return $this === self::Buy ? 'Beli' : 'Jual';
    }

    public function badgeClass(): string
    {
        return $this === self::Buy ? 'badge-info' : 'badge-warning';
    }
}
