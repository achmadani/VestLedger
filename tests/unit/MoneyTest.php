<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ValueObjects\Money;
use App\ValueObjects\Price;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * Aritmetika uang adalah fondasi seluruh mesin akuntansi. Selisih satu sen di
 * sini membuat jurnal tidak balance dan neraca gagal, jadi diuji ketat.
 *
 * @internal
 */
final class MoneyTest extends CIUnitTestCase
{
    public function testFloatRoundingErrorsDoNotLeakIn(): void
    {
        // Justru kasus inilah yang membuat float tidak layak untuk uang.
        $sum = Money::of(0.1)->add(Money::of(0.2));

        $this->assertSame('0.30', $sum->toDecimalString());
        $this->assertTrue($sum->equals(Money::of(0.3)));
    }

    public function testDecimalStringsFromDatabaseKeepTheirPrecision(): void
    {
        $this->assertSame('10020000.00', Money::of('10020000.00')->toDecimalString());
        $this->assertSame('1234567.89', Money::of('1234567.89')->toDecimalString());
        $this->assertSame('-250000.00', Money::of('-250000.00')->toDecimalString());
    }

    public function testThirdDecimalRoundsHalfUp(): void
    {
        $this->assertSame('1.24', Money::of('1.235')->toDecimalString());
        $this->assertSame('1.23', Money::of('1.234')->toDecimalString());
        $this->assertSame('1.24', Money::of('1.236')->toDecimalString());
    }

    public function testAdditionAndSubtractionAreExactOverManyOperations(): void
    {
        $total = Money::zero();

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->add(Money::of('0.01'));
        }

        $this->assertSame('10.00', $total->toDecimalString());
    }

    public function testInvalidStringIsRejectedRatherThanSilentlyBecomingZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('sepuluh ribu');
    }

    // ------------------------------------------------------------- proporsi

    /**
     * Book value yang dilepas saat jual sebagian dihitung proporsional.
     */
    public function testProportionSplitsBookValueWithoutLosingCents(): void
    {
        $bookValue = Money::of('3206000.00');

        // Menjual 1.000 dari 3.000 lembar.
        $sold = $bookValue->proportion(1000, 3000);

        $this->assertSame('1068666.67', $sold->toDecimalString());
    }

    /**
     * Menjual seluruh posisi harus melepas seluruh book value, tanpa sisa.
     */
    public function testProportionOfWholeReturnsTheExactSameValue(): void
    {
        $bookValue = Money::of('3206000.01');

        $this->assertTrue($bookValue->proportion(3000, 3000)->equals($bookValue));
    }

    /**
     * Menjual habis lewat beberapa transaksi parsial tidak boleh menyisakan
     * book value yang mengambang.
     */
    public function testRepeatedPartialSalesConsumeTheEntireBookValue(): void
    {
        $bookValue = Money::of('3206000.00');
        $remaining = $bookValue;
        $quantity  = 3000;

        foreach ([1000, 1000] as $sell) {
            $released  = $remaining->proportion($sell, $quantity);
            $remaining = $remaining->subtract($released);
            $quantity -= $sell;
        }

        // Sisa 1.000 lembar terakhir dijual seluruhnya.
        $released  = $remaining->proportion($quantity, $quantity);
        $remaining = $remaining->subtract($released);

        $this->assertTrue($remaining->isZero(), 'Sisa book value: ' . $remaining->toDecimalString());
    }

    public function testProportionRoundsHalfUp(): void
    {
        // 10.00 / 3 = 3.3333...
        $this->assertSame('3.33', Money::of('10.00')->proportion(1, 3)->toDecimalString());
        // 5.00 / 2 = 2.50 tepat
        $this->assertSame('2.50', Money::of('5.00')->proportion(1, 2)->toDecimalString());
        // 0.05 / 2 = 0.025 -> 0.03
        $this->assertSame('0.03', Money::of('0.05')->proportion(1, 2)->toDecimalString());
    }

    public function testProportionByZeroIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('100.00')->proportion(1, 0);
    }

    // ---------------------------------------------------------------- Price

    /**
     * Contoh §12: 1.000 lembar @ Rp1.000.
     */
    public function testPriceTimesQuantityMatchesSpecExample(): void
    {
        $this->assertSame('1000000.00', Price::of(1000)->multiplyByQuantity(1000)->toDecimalString());
        $this->assertSame('2200000.00', Price::of(1100)->multiplyByQuantity(2000)->toDecimalString());
    }

    public function testFractionalPriceStaysExact(): void
    {
        // Harga pecahan hasil corporate action.
        $this->assertSame('106867.00', Price::of('1068.67')->multiplyByQuantity(100)->toDecimalString());
        $this->assertSame('50.00', Price::of('0.5')->multiplyByQuantity(100)->toDecimalString());
    }

    public function testLargePositionDoesNotOverflowOrLosePrecision(): void
    {
        // 1 juta lembar @ Rp10.000 = Rp10 miliar
        $this->assertSame(
            '10000000000.00',
            Price::of(10000)->multiplyByQuantity(1000000)->toDecimalString()
        );
    }

    /**
     * Contoh §12: book cost Rp3.206.000 atas 3.000 lembar -> Rp1.068,67.
     */
    public function testAverageCostIsDerivedFromBookValueNotStored(): void
    {
        $average = Price::averageOf(Money::of('3206000.00'), 3000);

        // Presisi penuh dipertahankan di dalam perhitungan...
        $this->assertSame('1068.6667', $average->toDecimalString());
        // ...tetapi ditampilkan sesuai contoh §12.
        $this->assertSame('1.068,67', fmt_avg_cost($average->toFloat()));
    }

    public function testAverageCostOfEmptyPositionIsZeroRatherThanDivisionByZero(): void
    {
        $this->assertSame('0.0000', Price::averageOf(Money::of('100.00'), 0)->toDecimalString());
    }

    protected function setUp(): void
    {
        parent::setUp();
        helper('format');
    }
}
