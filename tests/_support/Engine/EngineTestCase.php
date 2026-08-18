<?php

declare(strict_types=1);

namespace Tests\Support\Engine;

use App\Enums\AccountCode;
use App\Models\AccountModel;
use App\Models\JournalLineModel;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\ValueObjects\Money;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Dasar bersama untuk seluruh test mesin transaksi & akuntansi.
 *
 * Menyediakan master data minimal dan sejumlah assertion khas akuntansi,
 * sehingga tiap test dapat fokus pada aturan yang sedang diujinya.
 */
abstract class EngineTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TruncatesDomainTables;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected int $ajaib;
    protected int $ipot;
    protected int $bbca;
    protected int $bbri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateDomainTables();

        \Config\Services::reset(true);

        service('chartOfAccounts')->ensureSystemAccounts();
        service('accountingPeriod')->generateYear(2026);

        $this->ajaib = $this->makeSecuritiesAccount('AJAIB', 'Ajaib Sekuritas');
        $this->ipot  = $this->makeSecuritiesAccount('IPOT', 'Indo Premier Sekuritas');
        $this->bbca  = $this->makeStock('BBCA', 'Bank Central Asia Tbk');
        $this->bbri  = $this->makeStock('BBRI', 'Bank Rakyat Indonesia Tbk');
    }

    protected function makeSecuritiesAccount(string $code, string $name): int
    {
        $security = service('securityService')->create(
            ['code' => $code, 'name' => $name],
            ['label' => 'RDN Utama']
        );

        return (new SecuritiesAccountModel())->forSecurities($security->id)[0]->id;
    }

    protected function makeStock(string $ticker, string $name): int
    {
        return service('stockService')->create(['ticker' => $ticker, 'company_name' => $name])->id;
    }

    // ------------------------------------------------------------ assertion

    /**
     * Saldo sebuah akun, dengan tanda mengikuti saldo normalnya.
     *
     * Akun bersaldo normal debit tampil positif ketika bertambah, begitu pula
     * akun bersaldo normal kredit — sehingga assertion terbaca wajar.
     */
    protected function accountBalance(AccountCode $code, ?int $securitiesAccountId = null): Money
    {
        $accountId = (new AccountModel())->idFor($code);

        $builder = $this->db->table('journal_lines')
            ->select('SUM(debit) AS d, SUM(credit) AS c')
            ->where('account_id', $accountId);

        if ($securitiesAccountId !== null) {
            $builder->where('securities_account_id', $securitiesAccountId);
        }

        $row = $builder->get()->getRowArray();

        $debit  = Money::of((string) ($row['d'] ?? '0'));
        $credit = Money::of((string) ($row['c'] ?? '0'));

        return $code->normalBalance() === \App\Enums\BalanceSide::Debit
            ? $debit->subtract($credit)
            : $credit->subtract($debit);
    }

    /**
     * Aturan fundamental §8: total debit = total kredit di SELURUH buku besar.
     */
    protected function assertLedgerBalanced(string $context = ''): void
    {
        $row = $this->db->table('journal_lines')
            ->select('SUM(debit) AS d, SUM(credit) AS c')
            ->get()
            ->getRowArray();

        $debit  = Money::of((string) ($row['d'] ?? '0'));
        $credit = Money::of((string) ($row['c'] ?? '0'));

        $this->assertTrue(
            $debit->equals($credit),
            sprintf(
                'Buku besar tidak balance%s. Debit %s vs Kredit %s.',
                $context !== '' ? ' (' . $context . ')' : '',
                $debit->toDecimalString(),
                $credit->toDecimalString()
            )
        );
    }

    /**
     * Setiap jurnal, satu per satu, harus balance — bukan hanya totalnya.
     *
     * Dua jurnal yang sama-sama salah bisa saling menutupi pada total global.
     */
    protected function assertEveryJournalBalanced(): void
    {
        $rows = $this->db->table('journal_lines jl')
            ->select('je.entry_number, SUM(jl.debit) AS d, SUM(jl.credit) AS c')
            ->join('journal_entries je', 'je.id = jl.journal_entry_id')
            ->groupBy('je.id, je.entry_number')
            ->get()
            ->getResultArray();

        $this->assertNotSame([], $rows, 'Tidak ada jurnal sama sekali untuk diperiksa.');

        foreach ($rows as $row) {
            $this->assertTrue(
                Money::of((string) $row['d'])->equals(Money::of((string) $row['c'])),
                sprintf('Jurnal %s tidak balance: debit %s vs kredit %s.', $row['entry_number'], $row['d'], $row['c'])
            );
        }
    }

    /**
     * Persamaan akuntansi §21.1: Aset = Kewajiban + Ekuitas + Laba periode berjalan.
     *
     * Karena laba/rugi belum ditutup ke ekuitas, akun nominal ikut diperhitungkan
     * di sisi kanan — persis seperti penyajian Neraca yang diminta spesifikasi.
     */
    protected function assertAccountingEquationHolds(): void
    {
        $rows = (new JournalLineModel())->balancesByAccount();

        $assets      = Money::zero();
        $liabilities = Money::zero();
        $equityAndPl = Money::zero();

        foreach ($rows as $row) {
            $debit  = Money::of((string) $row['total_debit']);
            $credit = Money::of((string) $row['total_credit']);

            $net = match ($row['type']) {
                'asset'   => $debit->subtract($credit),
                default   => $credit->subtract($debit),
            };

            match ($row['type']) {
                'asset'     => $assets = $assets->add($net),
                'liability' => $liabilities = $liabilities->add($net),
                default     => $equityAndPl = $equityAndPl->add($net),
            };
        }

        $this->assertTrue(
            $assets->equals($liabilities->add($equityAndPl)),
            sprintf(
                'Neraca tidak balance. Aset %s vs Kewajiban+Ekuitas+L/R %s.',
                $assets->toDecimalString(),
                $liabilities->add($equityAndPl)->toDecimalString()
            )
        );
    }

    protected function assertMoneyEquals(string $expected, Money $actual, string $message = ''): void
    {
        $this->assertSame($expected, $actual->toDecimalString(), $message);
    }

    protected function position(int $securitiesAccountId, int $stockId): \App\Entities\StockPosition
    {
        return service('positions')->current($securitiesAccountId, $stockId);
    }
}
