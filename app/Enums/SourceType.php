<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Asal sebuah jurnal, agar setiap baris buku besar dapat ditelusuri kembali
 * ke transaksi yang melahirkannya (§26 audit trail).
 */
enum SourceType: string
{
    case Cash     = 'cash';
    case Stock    = 'stock';
    case Dividend = 'dividend';
    case Opening  = 'opening';
    case Manual   = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Cash     => 'Transaksi Kas',
            self::Stock    => 'Transaksi Saham',
            self::Dividend => 'Dividen',
            self::Opening  => 'Saldo Awal',
            self::Manual   => 'Jurnal Manual',
        };
    }
}
