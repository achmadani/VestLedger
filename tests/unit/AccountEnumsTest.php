<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Enums\BalanceSide;
use App\Enums\PeriodStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Enum inilah yang menentukan arah setiap baris jurnal. Salah di sini berarti
 * seluruh laporan keuangan salah, jadi diuji secara eksplisit.
 *
 * @internal
 */
final class AccountEnumsTest extends CIUnitTestCase
{
    public function testNormalBalanceFollowsAccountingConvention(): void
    {
        $this->assertSame(BalanceSide::Debit, AccountType::Asset->normalBalance());
        $this->assertSame(BalanceSide::Debit, AccountType::Expense->normalBalance());
        $this->assertSame(BalanceSide::Credit, AccountType::Liability->normalBalance());
        $this->assertSame(BalanceSide::Credit, AccountType::Equity->normalBalance());
        $this->assertSame(BalanceSide::Credit, AccountType::Revenue->normalBalance());
    }

    public function testRealAccountsGoToBalanceSheetAndNominalToIncomeStatement(): void
    {
        $this->assertTrue(AccountType::Asset->isReal());
        $this->assertTrue(AccountType::Equity->isReal());
        $this->assertTrue(AccountType::Revenue->isNominal());
        $this->assertTrue(AccountType::Expense->isNominal());
    }

    /**
     * §17: withdrawal bukan beban, melainkan pengurang ekuitas. Akun 3200
     * bertipe ekuitas tetapi bersaldo normal debit.
     */
    public function testOwnerWithdrawalIsAContraEquityAccount(): void
    {
        $this->assertSame(AccountType::Equity, AccountCode::OwnerWithdrawal->type());
        $this->assertTrue(AccountCode::OwnerWithdrawal->isContra());
        $this->assertSame(BalanceSide::Debit, AccountCode::OwnerWithdrawal->normalBalance());

        // Bandingkan dengan akun ekuitas biasa yang tidak kontra.
        $this->assertFalse(AccountCode::PaidInCapital->isContra());
        $this->assertSame(BalanceSide::Credit, AccountCode::PaidInCapital->normalBalance());
    }

    public function testRealizedGainIsRevenueAndRealizedLossIsExpense(): void
    {
        $this->assertSame(AccountType::Revenue, AccountCode::RealizedGain->type());
        $this->assertSame(AccountType::Expense, AccountCode::RealizedLoss->type());
    }

    /**
     * Kas dan Portofolio Saham selalu melekat pada satu rekening sekuritas,
     * sehingga Buku Besar dapat difilter per sekuritas tanpa memecah CoA (§21.5).
     */
    public function testOnlyCashAndPortfolioCarrySecuritiesDimension(): void
    {
        $this->assertTrue(AccountCode::Cash->requiresSecuritiesDimension());
        $this->assertTrue(AccountCode::StockPortfolio->requiresSecuritiesDimension());
        $this->assertFalse(AccountCode::PaidInCapital->requiresSecuritiesDimension());
        $this->assertFalse(AccountCode::DividendIncome->requiresSecuritiesDimension());

        // Hanya portofolio yang butuh dimensi saham.
        $this->assertTrue(AccountCode::StockPortfolio->requiresStockDimension());
        $this->assertFalse(AccountCode::Cash->requiresStockDimension());
    }

    public function testBalanceSideSignMakesNormalIncreasesPositive(): void
    {
        // Kas (normal debit) bertambah di sisi debit -> +1
        $this->assertSame(1, BalanceSide::Debit->signFor(BalanceSide::Debit));
        // Kas berkurang di sisi kredit -> -1
        $this->assertSame(-1, BalanceSide::Credit->signFor(BalanceSide::Debit));
        // Pendapatan (normal kredit) bertambah di sisi kredit -> +1
        $this->assertSame(1, BalanceSide::Credit->signFor(BalanceSide::Credit));
    }

    public function testClosedPeriodRejectsPostings(): void
    {
        $this->assertTrue(PeriodStatus::Open->acceptsPostings());
        $this->assertFalse(PeriodStatus::Closed->acceptsPostings());
    }

    /**
     * Setiap AccountCode harus punya tipe & saldo normal yang konsisten —
     * penjaga agar penambahan akun inti baru tidak lupa dilengkapi.
     */
    public function testEveryAccountCodeIsFullyDescribed(): void
    {
        foreach (AccountCode::cases() as $code) {
            $this->assertNotSame('', $code->label(), $code->value . ' tidak punya label.');

            $expected = $code->isContra()
                ? $code->type()->normalBalance()->opposite()
                : $code->type()->normalBalance();

            $this->assertSame($expected, $code->normalBalance(), $code->value . ' saldo normalnya tidak konsisten.');
        }
    }
}
