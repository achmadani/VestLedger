<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

/**
 * Harga per lembar saham, presisi 4 desimal (§14, kolom DECIMAL(20,4)).
 *
 * Sama seperti Money, disimpan sebagai bilangan bulat minor unit agar
 * perkalian harga × jumlah lembar menghasilkan nilai yang eksak.
 */
final class Price
{
    public const SCALE = 4;

    private const FACTOR = 10000;

    private function __construct(private readonly int $minor)
    {
    }

    public static function of(int|float|string $value): self
    {
        if (is_int($value)) {
            return new self($value * self::FACTOR);
        }

        if (is_float($value)) {
            return new self((int) round($value * self::FACTOR));
        }

        $value = trim($value);

        if ($value === '') {
            return new self(0);
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" bukan harga yang sah.', $value));
        }

        [, $sign, $whole, $fraction] = $m + [3 => ''];

        $fraction = str_pad(substr($fraction, 0, 5), 5, '0');
        $minor    = (int) $whole * self::FACTOR + (int) substr($fraction, 0, 4);

        if ((int) $fraction[4] >= 5) {
            $minor++;
        }

        return new self($sign === '-' ? -$minor : $minor);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function toDecimalString(): string
    {
        $sign  = $this->minor < 0 ? '-' : '';
        $abs   = abs($this->minor);

        return sprintf('%s%d.%04d', $sign, intdiv($abs, self::FACTOR), $abs % self::FACTOR);
    }

    public function toFloat(): float
    {
        return $this->minor / self::FACTOR;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    /**
     * Nilai kotor untuk sejumlah lembar: harga × quantity.
     *
     * Konversi dari presisi 4 desimal ke 2 desimal dilakukan dengan pembulatan
     * half-up, seluruhnya dalam aritmetika bilangan bulat.
     */
    public function multiplyByQuantity(int $quantity): Money
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Jumlah lembar tidak boleh negatif.');
        }

        $product = $this->minor * $quantity;   // presisi 4 desimal
        $sign    = $product < 0 ? -1 : 1;
        $abs     = abs($product);

        // Turunkan dari presisi 4 desimal ke 2 desimal.
        $divisor  = intdiv(self::FACTOR, 10 ** Money::SCALE);
        $quotient = intdiv($abs, $divisor);

        if (($abs % $divisor) * 2 >= $divisor) {
            $quotient++;
        }

        return Money::fromMinor($sign * $quotient);
    }

    /**
     * Harga rata-rata yang diturunkan dari book value dan quantity.
     *
     * Average cost TIDAK PERNAH disimpan; ia selalu dihitung ulang dari
     * book_value / quantity (lihat docs/ACCOUNTING.md).
     */
    public static function averageOf(Money $bookValue, int $quantity): self
    {
        if ($quantity <= 0) {
            return new self(0);
        }

        // book value (2 desimal) -> 4 desimal, lalu bagi quantity dengan half-up.
        $numerator = $bookValue->minor() * 100;
        $sign      = $numerator < 0 ? -1 : 1;
        $abs       = abs($numerator);

        $quotient = intdiv($abs, $quantity);

        if (($abs % $quantity) * 2 >= $quantity) {
            $quotient++;
        }

        return new self($sign * $quotient);
    }
}
