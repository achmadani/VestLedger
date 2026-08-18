<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Klasifikasi akun dalam Chart of Accounts.
 *
 * Tipe akun menentukan dua hal yang dipakai di seluruh mesin akuntansi:
 *  1. saldo normal (debit atau kredit),
 *  2. apakah akun masuk Neraca (riil) atau Laba Rugi (nominal).
 */
enum AccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Equity    = 'equity';
    case Revenue   = 'revenue';
    case Expense   = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset     => 'Aset',
            self::Liability => 'Kewajiban',
            self::Equity    => 'Ekuitas',
            self::Revenue   => 'Pendapatan',
            self::Expense   => 'Beban',
        };
    }

    /**
     * Saldo normal akun.
     *
     * Kenaikan akun bersaldo normal debit dicatat di sisi debit, dan sebaliknya.
     * Nilai ini yang dipakai Trial Balance dan Buku Besar untuk menghitung
     * saldo berjalan dengan arah yang benar.
     */
    public function normalBalance(): BalanceSide
    {
        return match ($this) {
            self::Asset, self::Expense           => BalanceSide::Debit,
            self::Liability, self::Equity, self::Revenue => BalanceSide::Credit,
        };
    }

    /**
     * Akun riil (permanen) muncul di Neraca dan saldonya terbawa antar periode.
     */
    public function isReal(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    /**
     * Akun nominal (temporer) muncul di Laba Rugi dan ditutup tiap akhir periode.
     */
    public function isNominal(): bool
    {
        return ! $this->isReal();
    }

    /**
     * @return array<string, string> untuk dropdown form
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
