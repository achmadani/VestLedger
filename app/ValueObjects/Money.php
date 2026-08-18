<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

/**
 * Nilai uang dengan aritmetika EKSAK.
 *
 * Nilai disimpan sebagai bilangan bulat "minor unit" (sen), bukan float.
 * Alasannya sederhana: 0.1 + 0.2 !== 0.3 pada float, dan pada aplikasi
 * akuntansi selisih satu sen membuat jurnal tidak balance dan neraca gagal.
 *
 * Sengaja TIDAK memakai bcmath agar tidak ada ketergantungan ekstensi yang
 * mungkin tidak tersedia di shared hosting (§35). PHP_INT_MAX setara sekitar
 * 92 kuadriliun rupiah — jauh di atas kebutuhan portofolio mana pun.
 */
final class Money
{
    public const SCALE = 2;

    private const FACTOR = 100;

    private function __construct(private readonly int $minor)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Dari bilangan bulat minor unit (sen).
     */
    public static function fromMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * Dari nilai desimal — string dari database/form, atau int/float dari perhitungan.
     *
     * String diurai secara tekstual (tanpa melewati float sama sekali) sehingga
     * nilai dari kolom DECIMAL tidak pernah kehilangan presisi.
     */
    public static function of(int|float|string $value): self
    {
        if (is_int($value)) {
            return new self($value * self::FACTOR);
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Nilai uang harus berupa bilangan berhingga.');
            }

            // round() sebelum cast: (int) memotong, dan 0.29 * 100 = 28.999... pada float.
            return new self((int) round($value * self::FACTOR));
        }

        return self::fromString($value);
    }

    private static function fromString(string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            return self::zero();
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" bukan nilai uang yang sah.', $value));
        }

        [, $sign, $whole, $fraction] = $m + [3 => ''];

        // Bulatkan half-up pada digit ke-3, lalu ambil dua digit pertama.
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $minor    = (int) $whole * self::FACTOR + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minor++;
        }

        return new self($sign === '-' ? -$minor : $minor);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    /**
     * Representasi desimal untuk disimpan ke kolom DECIMAL(20,2).
     */
    public function toDecimalString(): string
    {
        $sign  = $this->minor < 0 ? '-' : '';
        $abs   = abs($this->minor);
        $whole = intdiv($abs, self::FACTOR);
        $cents = $abs % self::FACTOR;

        return sprintf('%s%d.%02d', $sign, $whole, $cents);
    }

    /**
     * Untuk ditampilkan lewat helper format. Aman karena hasilnya selalu
     * berada jauh di dalam jangkauan presisi eksak float.
     */
    public function toFloat(): float
    {
        return $this->minor / self::FACTOR;
    }

    public function add(self ...$others): self
    {
        $sum = $this->minor;

        foreach ($others as $other) {
            $sum += $other->minor;
        }

        return new self($sum);
    }

    public function subtract(self ...$others): self
    {
        $result = $this->minor;

        foreach ($others as $other) {
            $result -= $other->minor;
        }

        return new self($result);
    }

    public function negate(): self
    {
        return new self(-$this->minor);
    }

    public function abs(): self
    {
        return new self(abs($this->minor));
    }

    /**
     * Membagi nilai ini secara proporsional: hasil = nilai × pembilang / penyebut,
     * dibulatkan half-up.
     *
     * Inilah cara book value dilepas saat penjualan sebagian (lihat
     * docs/ACCOUNTING.md): proporsi terhadap quantity, BUKAN qty × average cost
     * yang dibulatkan — supaya tidak ada sisa book value yang mengambang.
     */
    public function proportion(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Pembagi proporsi tidak boleh nol.');
        }

        if ($numerator === $denominator) {
            return $this;
        }

        $product = $this->minor * $numerator;
        $sign    = ($product < 0) !== ($denominator < 0) ? -1 : 1;

        $absProduct     = abs($product);
        $absDenominator = abs($denominator);

        // Pembulatan half-up tanpa menyentuh float.
        $quotient = intdiv($absProduct, $absDenominator);

        if (($absProduct % $absDenominator) * 2 >= $absDenominator) {
            $quotient++;
        }

        return new self($sign * $quotient);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        return $this->minor < $other->minor;
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
