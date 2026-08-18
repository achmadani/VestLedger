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
    case StampDuty  = 'stamp_duty';

    public function label(): string
    {
        return match ($this) {
            self::TopUp      => 'Top Up Dana',
            self::Withdrawal => 'Withdrawal',
            self::Transfer   => 'Transfer Antar Sekuritas',
            self::AdminFee   => 'Biaya Administrasi',
            self::StampDuty  => 'Bea Materai',
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
     * Jenis yang dibuat sistem sendiri, bukan diinput pengguna.
     *
     * Bea materai lahir otomatis dari total transaksi harian, sehingga tidak
     * boleh muncul sebagai pilihan di form maupun dibuat manual.
     */
    public function isSystemGenerated(): bool
    {
        return $this === self::StampDuty;
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
