<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kode akun inti yang dirujuk langsung oleh business logic.
 *
 * Service akuntansi TIDAK BOLEH menulis kode akun sebagai string literal.
 * Semua rujukan lewat enum ini, sehingga:
 *  - salah ketik kode akun menjadi error saat kompilasi, bukan jurnal yang salah,
 *  - akun-akun ini dapat ditandai `is_system` dan dilindungi dari penghapusan.
 *
 * Rincian perlakuan tiap akun ada di docs/ACCOUNTING.md.
 */
enum AccountCode: string
{
    case Cash              = '1000';
    case StockPortfolio    = '1100';
    case PaidInCapital     = '3000';
    case RetainedEarnings  = '3100';
    case OwnerWithdrawal   = '3200';
    case RealizedGain      = '4000';
    case RealizedLoss      = '4001';
    case DividendIncome    = '4100';
    case BrokerFee         = '5000';
    case AdministrativeExpense = '5100';
    case TaxAndLevy        = '5200';

    public function label(): string
    {
        return match ($this) {
            self::Cash                  => 'Kas / Bank / RDN',
            self::StockPortfolio        => 'Portofolio Saham',
            self::PaidInCapital         => 'Modal Disetor',
            self::RetainedEarnings      => 'Laba Ditahan',
            self::OwnerWithdrawal       => 'Penarikan Pemilik',
            self::RealizedGain          => 'Laba Realisasi',
            self::RealizedLoss          => 'Rugi Realisasi',
            self::DividendIncome        => 'Pendapatan Dividen',
            self::BrokerFee             => 'Biaya Broker',
            self::AdministrativeExpense => 'Beban Administrasi',
            self::TaxAndLevy            => 'Pajak & Levy',
        };
    }

    public function type(): AccountType
    {
        return match ($this) {
            self::Cash, self::StockPortfolio => AccountType::Asset,
            self::PaidInCapital, self::RetainedEarnings, self::OwnerWithdrawal => AccountType::Equity,
            self::RealizedGain, self::DividendIncome => AccountType::Revenue,
            self::RealizedLoss, self::BrokerFee,
            self::AdministrativeExpense, self::TaxAndLevy => AccountType::Expense,
        };
    }

    /**
     * Akun kontra membalik saldo normal tipenya.
     *
     * Owner Withdrawal bertipe Equity (saldo normal kredit) tetapi merupakan
     * pengurang ekuitas, sehingga saldo normalnya justru debit (§17).
     */
    public function isContra(): bool
    {
        return $this === self::OwnerWithdrawal;
    }

    public function normalBalance(): BalanceSide
    {
        return $this->isContra()
            ? $this->type()->normalBalance()->opposite()
            : $this->type()->normalBalance();
    }

    /**
     * Apakah akun ini memerlukan dimensi sekuritas pada setiap baris jurnalnya.
     *
     * Kas dan Portofolio Saham selalu melekat pada satu rekening sekuritas,
     * sehingga Buku Besar dapat difilter per sekuritas tanpa memecah CoA (§21.5).
     */
    public function requiresSecuritiesDimension(): bool
    {
        return in_array($this, [self::Cash, self::StockPortfolio], true);
    }

    /**
     * Apakah akun ini memerlukan dimensi saham.
     */
    public function requiresStockDimension(): bool
    {
        return $this === self::StockPortfolio;
    }
}
