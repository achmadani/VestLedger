<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Investment;

/**
 * Format angka adalah lapisan presentasi, tetapi salah format pada aplikasi
 * keuangan menyesatkan pembacanya. Karena itu tetap diuji.
 *
 * @internal
 */
final class FormatHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('format');
    }

    public function testNumberUsesIndonesianSeparators(): void
    {
        $this->assertSame('1.234.567', fmt_number(1234567));
        $this->assertSame('1.234.567,89', fmt_number(1234567.891, 2));
        $this->assertSame('0', fmt_number(0));
    }

    public function testRupiahPrefixesSymbolAndKeepsSignInFront(): void
    {
        $this->assertSame('Rp 10.020.000', fmt_rupiah(10020000));
        $this->assertSame('-Rp 250.000', fmt_rupiah(-250000));
        $this->assertSame('-', fmt_rupiah(null));
    }

    public function testSignedAlwaysShowsDirectionWithoutRelyingOnColour(): void
    {
        $this->assertSame('+Rp 10.000.000', fmt_signed(10000000));
        $this->assertSame('-Rp 10.000.000', fmt_signed(-10000000));
        $this->assertSame('Rp 0', fmt_signed(0));
    }

    public function testPriceDropsInsignificantDecimals(): void
    {
        $this->assertSame('1.000', fmt_price(1000.0));
        $this->assertSame('1.068,67', fmt_price(1068.67));
        $this->assertSame('8.200', fmt_price(8200));
    }

    /**
     * Contoh §7: 100 lot = 10.000 lembar.
     */
    public function testLotAndShareConversionMatchesSpecExample(): void
    {
        $this->assertSame('10.000', fmt_qty(10000));
        $this->assertSame('100', fmt_lot(10000));
    }

    public function testOddLotIsShownWithDecimals(): void
    {
        $this->assertSame('10,5', fmt_lot(1050));
    }

    public function testAmountClassReflectsDirection(): void
    {
        $this->assertSame('text-success', amount_class(1));
        $this->assertSame('text-error', amount_class(-1));
        $this->assertSame('text-base-content/60', amount_class(0));
    }

    public function testLotConversionHelpersOnConfig(): void
    {
        $config = new Investment();

        $this->assertSame(10000, $config->lotsToShares(100));
        $this->assertSame(100.0, $config->sharesToLots(10000));
        $this->assertSame(10.5, $config->sharesToLots(1050));
    }
}
