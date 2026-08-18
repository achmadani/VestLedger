<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Jenis jurnal.
 */
enum JournalEntryType: string
{
    case Normal     = 'normal';
    case Reversal   = 'reversal';
    case Opening    = 'opening';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Normal     => 'Jurnal Transaksi',
            self::Reversal   => 'Jurnal Pembalik',
            self::Opening    => 'Saldo Awal',
            self::Adjustment => 'Jurnal Penyesuaian',
        };
    }
}
