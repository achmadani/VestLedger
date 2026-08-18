<?php

declare(strict_types=1);

use Config\Investment;

/**
 * Helper format angka/uang untuk presentation layer.
 *
 * Helper ini HANYA memformat. Tidak ada business logic akuntansi di sini (§29).
 * Format mengikuti konvensi Indonesia: pemisah ribuan "." dan desimal ",".
 */

if (! function_exists('investment_config')) {
    function investment_config(): Investment
    {
        /** @var Investment $config */
        $config = config(Investment::class);

        return $config;
    }
}

if (! function_exists('fmt_number')) {
    /**
     * Format angka gaya Indonesia: 1234567.5 -> "1.234.567,50"
     */
    function fmt_number(int|float|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, $decimals, ',', '.');
    }
}

if (! function_exists('fmt_money')) {
    /**
     * Nilai uang tanpa simbol: "10.020.000" (default tanpa sen).
     */
    function fmt_money(int|float|string|null $value, ?int $decimals = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return fmt_number($value, $decimals ?? 0);
    }
}

if (! function_exists('fmt_rupiah')) {
    /**
     * Nilai uang lengkap dengan simbol: "Rp 10.020.000".
     */
    function fmt_rupiah(int|float|string|null $value, ?int $decimals = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $formatted = fmt_number(abs((float) $value), $decimals ?? 0);
        $sign      = (float) $value < 0 ? '-' : '';

        return $sign . investment_config()->currencySymbol . ' ' . $formatted;
    }
}

if (! function_exists('fmt_signed')) {
    /**
     * Nilai uang bertanda eksplisit untuk gain/loss: "+Rp 10.000.000".
     * Dipakai agar arah untung/rugi terbaca tanpa harus melihat warna saja
     * (aksesibilitas: warna tidak boleh menjadi satu-satunya penanda).
     */
    function fmt_signed(int|float|string|null $value, ?int $decimals = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $value = (float) $value;
        $sign  = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return $sign . investment_config()->currencySymbol . ' '
            . fmt_number(abs($value), $decimals ?? 0);
    }
}

if (! function_exists('fmt_price')) {
    /**
     * Harga per lembar. Desimal nol di belakang dibuang: 1000.0000 -> "1.000",
     * 1068.6700 -> "1.068,67".
     */
    function fmt_price(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $value    = (float) $value;
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : investment_config()->priceScale;

        $formatted = fmt_number($value, $decimals);

        // Buang nol tidak signifikan di belakang koma: "1.068,6700" -> "1.068,67"
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted;
    }
}

if (! function_exists('fmt_avg_cost')) {
    /**
     * Average cost per lembar.
     *
     * Ditampilkan dengan presisi lebih rendah daripada harga transaksi
     * (Config\Investment::$averageCostDisplayScale), karena average cost adalah
     * nilai TURUNAN dari book_value / quantity — bukan harga yang pernah
     * benar-benar terjadi. Contoh §12: Rp3.206.000 / 3.000 = Rp1.068,67.
     */
    function fmt_avg_cost(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return fmt_number($value, investment_config()->averageCostDisplayScale);
    }
}

if (! function_exists('fmt_qty')) {
    /**
     * Jumlah lembar saham: 10000 -> "10.000".
     */
    function fmt_qty(int|float|string|null $shares): string
    {
        if ($shares === null || $shares === '') {
            return '-';
        }

        return fmt_number((int) $shares, 0);
    }
}

if (! function_exists('fmt_lot')) {
    /**
     * Jumlah lot dari lembar: 10000 lembar -> "100" lot.
     * Odd lot ditampilkan dengan 2 desimal: 1050 lembar -> "10,5".
     */
    function fmt_lot(int|float|string|null $shares): string
    {
        if ($shares === null || $shares === '') {
            return '-';
        }

        $lots = investment_config()->sharesToLots((int) $shares);

        if (fmod($lots, 1.0) === 0.0) {
            return fmt_number($lots, 0);
        }

        return rtrim(rtrim(fmt_number($lots, 2), '0'), ',');
    }
}

if (! function_exists('fmt_percent')) {
    /**
     * Persentase: 12.3456 -> "12,35%".
     */
    function fmt_percent(int|float|string|null $value, int $decimals = 2, bool $signed = false): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $value = (float) $value;
        $sign  = $signed && $value > 0 ? '+' : '';

        return $sign . fmt_number($value, $decimals) . '%';
    }
}

if (! function_exists('fmt_date')) {
    /**
     * Tanggal gaya Indonesia: "2026-01-31" -> "31 Jan 2026".
     */
    function fmt_date(string|null $date, string $format = 'd M Y'): string
    {
        if ($date === null || $date === '') {
            return '-';
        }

        try {
            return (new DateTimeImmutable($date))->format($format);
        } catch (Exception) {
            return $date;
        }
    }
}

if (! function_exists('amount_class')) {
    /**
     * Kelas warna DaisyUI untuk nilai gain/loss.
     * Selalu dipakai BERSAMA fmt_signed(), bukan sebagai satu-satunya penanda arah.
     */
    function amount_class(int|float|string|null $value): string
    {
        $value = (float) ($value ?? 0);

        if ($value > 0) {
            return 'text-success';
        }

        if ($value < 0) {
            return 'text-error';
        }

        return 'text-base-content/60';
    }
}

if (! function_exists('component')) {
    /**
     * Merender komponen UI reusable dari app/Views/components.
     *
     * saveData=false agar props satu komponen tidak bocor ke komponen berikutnya.
     */
    function component(string $name, array $props = []): string
    {
        return view('components/' . $name, $props, ['saveData' => false]);
    }
}
