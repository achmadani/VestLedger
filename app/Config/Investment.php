<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Parameter domain investasi & akuntansi VestLedger.
 *
 * Semua angka "aturan main" yang bisa berubah di kemudian hari dikumpulkan di sini
 * supaya tidak tersebar sebagai magic number di service maupun view.
 */
class Investment extends BaseConfig
{
    /**
     * Jumlah lembar saham per 1 lot (§7).
     * Bursa Efek Indonesia memakai 100 lembar per lot sejak 2014.
     */
    public int $sharesPerLot = 100;

    /**
     * Mata uang pelaporan.
     */
    public string $currency       = 'IDR';
    public string $currencySymbol = 'Rp';

    /**
     * Jumlah desimal untuk NILAI UANG (amount, book value, jurnal).
     *
     * Kolom uang disimpan sebagai DECIMAL(20,2). Rupiah secara praktik tidak
     * memakai sen, tetapi 2 desimal disimpan agar pembagian book value saat
     * penjualan sebagian tidak kehilangan presisi dan neraca tetap balance.
     */
    public int $moneyScale = 2;

    /**
     * Jumlah desimal untuk HARGA per lembar (price, closing price).
     * DECIMAL(20,4) — mengakomodasi harga pecahan hasil corporate action.
     */
    public int $priceScale = 4;

    /**
     * Jumlah desimal untuk AVERAGE COST per lembar saat ditampilkan.
     *
     * Average cost TIDAK disimpan sebagai sumber kebenaran. Yang disimpan adalah
     * quantity (BIGINT) dan book_value (DECIMAL 20,2); average cost selalu
     * diturunkan = book_value / quantity. Ini mencegah drift pembulatan yang
     * membuat neraca tidak balance (§40.9, §40.10).
     */
    public int $averageCostDisplayScale = 2;

    /**
     * Tema DaisyUI yang tersedia untuk dipilih pengguna (§30).
     * Menambah tema cukup dengan menambah entri di sini DAN di resources/css/app.css.
     */
    public array $themes = [
        'corporate' => 'Corporate',
        'business'  => 'Business',
        'light'     => 'Light',
        'dark'      => 'Dark',
        'emerald'   => 'Emerald',
        'night'     => 'Night',
    ];

    public string $defaultTheme = 'corporate';

    /**
     * Konversi lot -> lembar.
     */
    public function lotsToShares(int|float $lots): int
    {
        return (int) round($lots * $this->sharesPerLot);
    }

    /**
     * Konversi lembar -> lot (boleh pecahan bila ada odd lot).
     */
    public function sharesToLots(int $shares): float
    {
        return $shares / $this->sharesPerLot;
    }
}
