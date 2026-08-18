<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Exceptions\BusinessRuleException;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Top up, withdrawal, transfer, dan biaya administrasi (§16, §17, §18, §37).
 *
 * @internal
 */
final class CashTransactionTest extends EngineTestCase
{
    // -------------------------------------------------------------- Top Up

    /**
     * §40.3: top up BUKAN pendapatan.
     */
    public function testTopUpIncreasesCashAndEquityNotRevenue(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date'      => '2026-01-05',
            'securities_account_id' => $this->ajaib,
            'amount'                => 10_000_000,
        ]);

        $this->assertMoneyEquals('10000000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertMoneyEquals('10000000.00', $this->accountBalance(AccountCode::PaidInCapital));

        // Tidak ada pendapatan yang tercatat.
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::DividendIncome));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::RealizedGain));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    public function testTopUpFeeIsExpensedWhileCapitalRecordsTheFullDeposit(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date'      => '2026-01-05',
            'securities_account_id' => $this->ajaib,
            'amount'                => 10_000_000,
            'fee'                   => 6_500,
        ]);

        // Kas hanya bertambah sebesar yang benar-benar masuk...
        $this->assertMoneyEquals('9993500.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        // ...tetapi modal disetor mencatat seluruh yang disetorkan pemilik.
        $this->assertMoneyEquals('10000000.00', $this->accountBalance(AccountCode::PaidInCapital));
        $this->assertMoneyEquals('6500.00', $this->accountBalance(AccountCode::AdministrativeExpense));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    // ---------------------------------------------------------- Withdrawal

    /**
     * §40.4: withdrawal BUKAN beban.
     */
    public function testWithdrawalReducesCashAndEquityNotExpense(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);

        $expenseBefore = $this->accountBalance(AccountCode::AdministrativeExpense);

        service('cashTransactions')->withdraw([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib, 'amount' => 2_500_000,
        ]);

        $this->assertMoneyEquals('7500000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertMoneyEquals('2500000.00', $this->accountBalance(AccountCode::OwnerWithdrawal));

        // Modal disetor tetap mencatat bruto; withdrawal tidak menggerusnya.
        $this->assertMoneyEquals('10000000.00', $this->accountBalance(AccountCode::PaidInCapital));
        // Tidak ada beban baru.
        $this->assertTrue($expenseBefore->equals($this->accountBalance(AccountCode::AdministrativeExpense)));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Ekuitas bersih = modal disetor − penarikan pemilik.
     */
    public function testNetEquityReflectsBothCapitalAndWithdrawal(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);
        service('cashTransactions')->withdraw([
            'transaction_date' => '2026-02-10', 'securities_account_id' => $this->ajaib, 'amount' => 2_500_000,
        ]);

        $netEquity = $this->accountBalance(AccountCode::PaidInCapital)
            ->subtract($this->accountBalance(AccountCode::OwnerWithdrawal));

        $this->assertMoneyEquals('7500000.00', $netEquity);
        $this->assertTrue($netEquity->equals($this->accountBalance(AccountCode::Cash, $this->ajaib)));
    }

    // ------------------------------------------------------------ Transfer

    /**
     * §18 & §40.5: transfer internal tidak menambah revenue/expense/profit/loss,
     * dan total kas global harus tetap.
     */
    public function testTransferMovesCashWithoutChangingGlobalTotal(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 10_000_000,
        ]);

        $globalBefore = $this->accountBalance(AccountCode::Cash);

        service('cashTransactions')->transfer([
            'transaction_date'       => '2026-03-01',
            'securities_account_id'  => $this->ajaib,
            'counterpart_account_id' => $this->ipot,
            'amount'                 => 4_000_000,
        ]);

        $this->assertMoneyEquals('6000000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib), 'Kas asal berkurang');
        $this->assertMoneyEquals('4000000.00', $this->accountBalance(AccountCode::Cash, $this->ipot), 'Kas tujuan bertambah');

        $this->assertTrue(
            $globalBefore->equals($this->accountBalance(AccountCode::Cash)),
            'Total kas global harus tetap sama setelah transfer internal.'
        );

        // Tidak ada akun nominal yang tersentuh.
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::AdministrativeExpense));
        $this->assertMoneyEquals('0.00', $this->accountBalance(AccountCode::RealizedGain));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    public function testTransferToTheSameAccountIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/tidak boleh sama/');

        service('cashTransactions')->transfer([
            'transaction_date'       => '2026-03-01',
            'securities_account_id'  => $this->ajaib,
            'counterpart_account_id' => $this->ajaib,
            'amount'                 => 1_000_000,
        ]);
    }

    // ------------------------------------------------------- Biaya & aturan

    public function testAdminFeeIsAnExpenseThatReducesCash(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);
        service('cashTransactions')->adminFee([
            'transaction_date' => '2026-01-31', 'securities_account_id' => $this->ajaib, 'amount' => 11_000,
        ]);

        $this->assertMoneyEquals('989000.00', $this->accountBalance(AccountCode::Cash, $this->ajaib));
        $this->assertMoneyEquals('11000.00', $this->accountBalance(AccountCode::AdministrativeExpense));
        $this->assertEveryJournalBalanced();
    }

    public function testZeroOrNegativeAmountIsRejected(): void
    {
        foreach ([0, -100] as $amount) {
            try {
                service('cashTransactions')->topUp([
                    'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => $amount,
                ]);
                $this->fail('Nominal ' . $amount . ' seharusnya ditolak.');
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }
    }

    /**
     * §25: periode tertutup menolak transaksi baru.
     */
    public function testTransactionIntoAClosedPeriodIsRejectedAndNothingIsWritten(): void
    {
        $period = (new \App\Models\AccountingPeriodModel())->findByCode('2026-01');
        service('accountingPeriod')->close($period->id);

        $journalsBefore = $this->db->table('journal_entries')->countAllResults();

        try {
            service('cashTransactions')->topUp([
                'transaction_date' => '2026-01-15', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
            ]);
            $this->fail('Transaksi ke periode tertutup seharusnya ditolak.');
        } catch (BusinessRuleException $e) {
            $this->assertMatchesRegularExpression('/sudah ditutup/', $e->getMessage());
        }

        // Rollback harus menyeluruh: tidak ada transaksi maupun jurnal yang tertinggal.
        $this->assertSame(0, $this->db->table('cash_transactions')->countAllResults());
        $this->assertSame($journalsBefore, $this->db->table('journal_entries')->countAllResults());
    }

    public function testInactiveAccountCannotReceiveNewTransactions(): void
    {
        $account  = (new \App\Models\SecuritiesAccountModel())->find($this->ajaib);
        service('securityService')->deactivate($account->securities_id);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/nonaktif/');

        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);
    }

    public function testEachTransactionProducesExactlyOneJournal(): void
    {
        service('cashTransactions')->topUp([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib, 'amount' => 1_000_000,
        ]);

        $transaction = (new \App\Models\CashTransactionModel())->first();

        $this->assertNotNull($transaction->journal_entry_id, 'Transaksi wajib tertaut ke jurnalnya.');
        $this->assertSame(1, $this->db->table('journal_entries')->countAllResults());
    }
}
