<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status transaksi dan jurnal (§6, §26).
 *
 * Tidak ada status "deleted": transaksi accounting yang sudah posted tidak
 * pernah dihapus. Pembatalan menghasilkan jurnal pembalik, dan transaksi asli
 * berubah status menjadi Reversed — tetap ada, tetap terbaca (§40.8).
 */
enum PostingStatus: string
{
    case Posted   = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Posted   => 'Posted',
            self::Reversed => 'Dibatalkan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Posted   => 'badge-success',
            self::Reversed => 'badge-error',
        };
    }

    /**
     * Apakah transaksi ini masih mempengaruhi saldo dan posisi portofolio.
     */
    public function isEffective(): bool
    {
        return $this === self::Posted;
    }
}
