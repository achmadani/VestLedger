<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status periode akuntansi (§25).
 */
enum PeriodStatus: string
{
    case Open   = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return $this === self::Open ? 'Terbuka' : 'Ditutup';
    }

    public function badgeClass(): string
    {
        return $this === self::Open ? 'badge-success' : 'badge-neutral';
    }

    /**
     * Periode yang sudah ditutup tidak menerima transaksi baru maupun perubahan.
     * Koreksi dilakukan lewat reversal di periode terbuka (§25, §26).
     */
    public function acceptsPostings(): bool
    {
        return $this === self::Open;
    }
}
