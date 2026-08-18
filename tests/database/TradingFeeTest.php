<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Enums\StockTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\SecurityModel;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Tarif biaya transaksi per sekuritas dan pemecahannya.
 *
 * @internal
 */
final class TradingFeeTest extends EngineTestCase
{
    private function security(string $code = 'AJAIB'): \App\Entities\Security
    {
        return (new SecurityModel())->findByCode($code);
    }

    public function testNewSecuritiesGetTheDefaultRates(): void
    {
        $security = $this->security();

        $this->assertSame(0.15, $security->buyFeePercent());
        $this->assertSame(0.25, $security->sellFeePercent());
    }

    /**
     * Tarif all-in dipecah menjadi levy + pajak + sisanya fee broker,
     * dan jumlah ketiganya harus sama PERSIS dengan tarif all-in.
     */
    public function testBuyFeeSplitsIntoLevyAndBrokerFee(): void
    {
        // Bruto Rp10.000.000 dengan tarif beli 0,15%
        $fees = service('tradingFees')->calculate(
            $this->security(),
            StockTransactionType::Buy,
            Money::of('10000000')
        );

        $this->assertMoneyEquals('4300.00', $fees['levy'], 'Levy 0,043%');
        $this->assertMoneyEquals('0.00', $fees['tax'], 'Pembelian tidak dikenakan PPh final');
        $this->assertMoneyEquals('10700.00', $fees['broker_fee'], 'Sisanya fee broker');
        $this->assertMoneyEquals('15000.00', $fees['total'], 'Total tetap 0,15%');
    }

    public function testSellFeeSplitsIntoLevyTaxAndBrokerFee(): void
    {
        $fees = service('tradingFees')->calculate(
            $this->security(),
            StockTransactionType::Sell,
            Money::of('10000000')
        );

        $this->assertMoneyEquals('4300.00', $fees['levy'], 'Levy 0,043%');
        $this->assertMoneyEquals('10000.00', $fees['tax'], 'PPh final 0,1%');
        $this->assertMoneyEquals('10700.00', $fees['broker_fee']);
        $this->assertMoneyEquals('25000.00', $fees['total'], 'Total tetap 0,25%');
    }

    /**
     * Fee broker dihitung sebagai SISA, sehingga totalnya tidak pernah meleset
     * karena pembulatan tiga komponen yang dihitung sendiri-sendiri.
     */
    public function testComponentsAlwaysSumBackToTheAllInRate(): void
    {
        foreach (['1234567', '999', '87654321', '1', '50000000'] as $amount) {
            foreach ([StockTransactionType::Buy, StockTransactionType::Sell] as $type) {
                $gross = Money::of($amount);
                $fees  = service('tradingFees')->calculate($this->security(), $type, $gross);

                $sum = $fees['broker_fee']->add($fees['tax'])->add($fees['levy']);

                $this->assertTrue(
                    $sum->equals($fees['total']),
                    sprintf('Bruto %s: komponen %s vs total %s', $amount, $sum->toDecimalString(), $fees['total']->toDecimalString())
                );
            }
        }
    }

    public function testDifferentSecuritiesUseTheirOwnRates(): void
    {
        service('securityService')->update(
            $this->security('IPOT')->id,
            ['code' => 'IPOT', 'name' => 'Indo Premier', 'buy_fee_percent' => '0.19', 'sell_fee_percent' => '0.29']
        );

        $ajaib = service('tradingFees')->calculate($this->security('AJAIB'), StockTransactionType::Buy, Money::of('10000000'));
        $ipot  = service('tradingFees')->calculate($this->security('IPOT'), StockTransactionType::Buy, Money::of('10000000'));

        $this->assertMoneyEquals('15000.00', $ajaib['total']);
        $this->assertMoneyEquals('19000.00', $ipot['total']);
    }

    /**
     * Tarif di bawah komponen regulatifnya mustahil dan harus ditolak.
     */
    public function testRateBelowRegulatoryChargesIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/lebih kecil daripada levy dan pajak/');

        service('securityService')->update($this->security()->id, [
            'code' => 'AJAIB', 'name' => 'Ajaib', 'sell_fee_percent' => '0.05',
        ]);
    }

    // ------------------------------------------------- Terpakai di transaksi

    /**
     * Transaksi yang tidak menyebutkan biaya sama sekali memakai tarif sekuritasnya.
     */
    public function testBuyWithoutExplicitChargesUsesTheSecuritiesRate(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 100_000_000,
        ]);

        $transaction = service('stockTransactions')->buy([
            'transaction_date'      => '2026-01-05',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity'              => 10_000,
            'price'                 => 1_000,
        ]);

        // Bruto Rp10.000.000 -> total biaya 0,15% = Rp15.000
        $this->assertMoneyEquals('15000.00', $transaction->totalCharges());
        $this->assertMoneyEquals('10015000.00', $transaction->netAmount(), 'Seluruh biaya dikapitalisasi');

        // Beli tidak pernah menyentuh akun beban.
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::BrokerFee));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::TaxAndLevy));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Pada penjualan, komponennya masuk akun yang berbeda-beda.
     */
    public function testSellWithoutExplicitChargesPostsEachComponentToItsOwnAccount(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 100_000_000,
        ]);
        service('stockTransactions')->buy([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 10_000, 'price' => 1_000,
        ]);

        service('stockTransactions')->sell([
            'transaction_date'      => '2026-02-10',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity'              => 10_000,
            'price'                 => 1_000,
        ]);

        // Bruto Rp10.000.000 -> fee broker 10.700, pajak+levy 14.300
        $this->assertMoneyEquals('10700.00', $this->accountBalance(AccountCode::BrokerFee));
        $this->assertMoneyEquals('14300.00', $this->accountBalance(AccountCode::TaxAndLevy));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Biaya yang disebut eksplisit tetap dipakai, karena pengguna berhak
     * menyesuaikannya dengan konfirmasi broker.
     */
    public function testExplicitChargesOverrideTheSecuritiesRate(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-02', 'securities_account_id' => $this->ajaib, 'amount' => 100_000_000,
        ]);

        $transaction = service('stockTransactions')->buy([
            'transaction_date'      => '2026-01-05',
            'securities_account_id' => $this->ajaib,
            'stock_id'              => $this->bbca,
            'quantity'              => 10_000,
            'price'                 => 1_000,
            'broker_fee'            => 7_777,
            'tax'                   => 0,
            'levy'                  => 0,
        ]);

        $this->assertMoneyEquals('7777.00', $transaction->totalCharges());
    }
}
