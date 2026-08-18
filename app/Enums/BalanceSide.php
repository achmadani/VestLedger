<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sisi jurnal.
 */
enum BalanceSide: string
{
    case Debit  = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return $this === self::Debit ? 'Debit' : 'Kredit';
    }

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    /**
     * Pengali untuk menghitung saldo akun.
     *
     * Saldo akun = Σ (debit − kredit) × pengali. Dengan begitu akun bersaldo
     * normal kredit (mis. Pendapatan) tetap tampil positif saat bertambah.
     */
    public function signFor(self $normalBalance): int
    {
        return $this === $normalBalance ? 1 : -1;
    }
}
