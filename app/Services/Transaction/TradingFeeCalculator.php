<?php

declare(strict_types=1);

namespace App\Services\Transaction;

use App\Entities\Security;
use App\Enums\StockTransactionType;
use App\ValueObjects\Money;
use Config\Investment;

/**
 * Memecah tarif all-in broker menjadi komponen akuntansinya.
 *
 * Broker mengiklankan satu angka — misalnya "beli 0,15%, jual 0,25%" — padahal
 * di dalamnya terkandung tiga hal yang berbeda perlakuan akuntansinya:
 *
 *   - **Levy bursa** (IDX + KPEI + KSEI), dikenakan pada beli maupun jual;
 *   - **PPh final** atas penjualan, hanya pada sisi jual;
 *   - **Fee broker**, yaitu sisanya.
 *
 * Pemecahan ini penting karena tiga komponen tersebut masuk akun berbeda
 * (5000 dan 5200), dan pada sisi beli seluruhnya dikapitalisasi ke book cost.
 * Tanpa pemecahan, akun Pajak & Levy tidak akan pernah terisi dari transaksi
 * saham dan Laba Rugi menyembunyikan komposisi biaya yang sebenarnya.
 *
 * Fee broker dihitung sebagai SISA, bukan dihitung sendiri dari persentase,
 * sehingga jumlah ketiganya selalu sama persis dengan tarif all-in yang
 * tertera di konfirmasi broker.
 */
class TradingFeeCalculator
{
    /**
     * Persen disimpan dengan 5 desimal; dikalikan 100.000 agar menjadi bilangan
     * bulat, lalu dibagi 10.000.000 (100.000 × 100) untuk mengubah persen
     * menjadi pecahan. Seluruhnya tetap dalam aritmetika bilangan bulat.
     */
    private const PERCENT_SCALE   = 100000;
    private const PERCENT_DIVISOR = 100000 * 100;

    public function __construct(private Investment $config)
    {
    }

    /**
     * @return array{broker_fee: Money, tax: Money, levy: Money, total: Money}
     */
    public function calculate(Security $security, StockTransactionType $type, Money $gross): array
    {
        $isSell = $type === StockTransactionType::Sell;

        $allIn = $this->percentOf($gross, $isSell
            ? (float) $security->sell_fee_percent
            : (float) $security->buy_fee_percent);

        $levy = $this->percentOf($gross, $this->config->exchangeLevyPercent);
        $tax  = $isSell ? $this->percentOf($gross, $this->config->sellTaxPercent) : Money::zero();

        $brokerFee = $allIn->subtract($levy)->subtract($tax);

        // Tarif all-in yang lebih kecil daripada komponen regulatifnya akan
        // menghasilkan fee broker negatif. Itu berarti tarif di master sekuritas
        // salah; di sini nilainya dijaga tetap nol agar tidak ada baris jurnal
        // yang terbalik arah, dan validasi master data yang menolaknya.
        if ($brokerFee->isNegative()) {
            $brokerFee = Money::zero();
        }

        return [
            'broker_fee' => $brokerFee,
            'tax'        => $tax,
            'levy'       => $levy,
            'total'      => $brokerFee->add($tax)->add($levy),
        ];
    }

    /**
     * Tarif all-in minimum yang masuk akal untuk sebuah sisi transaksi.
     *
     * Dipakai validasi master sekuritas: tarif di bawah ini mustahil, karena
     * levy dan pajak saja sudah melebihinya.
     */
    public function minimumPercent(StockTransactionType $type): float
    {
        $minimum = $this->config->exchangeLevyPercent;

        if ($type === StockTransactionType::Sell) {
            $minimum += $this->config->sellTaxPercent;
        }

        return $minimum;
    }

    private function percentOf(Money $gross, float $percent): Money
    {
        if ($percent <= 0.0) {
            return Money::zero();
        }

        return $gross->proportion(
            (int) round($percent * self::PERCENT_SCALE),
            self::PERCENT_DIVISOR
        );
    }
}
