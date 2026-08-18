<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Jenis transaksi kas (§6).
 */
enum CashTransactionType: string
{
    case TopUp      = 'top_up';
    case Withdrawal = 'withdrawal';
    case Transfer   = 'transfer';
    case AdminFee   = 'admin_fee';

    public function label(): string
    {
        return match ($this) {
            self::TopUp      => 'Top Up Dana',
            self::Withdrawal => 'Withdrawal',
            self::Transfer   => 'Transfer Antar Sekuritas',
            self::AdminFee   => 'Biaya Administrasi',
        };
    }

    /**
     * Apakah jenis ini memerlukan rekening tujuan.
     */
    public function needsCounterpart(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * Arah pengaruhnya terhadap kas rekening utama.
     */
    public function increasesCash(): bool
    {
        return $this === self::TopUp;
    }

    /**
     * @return array<string, string>
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
