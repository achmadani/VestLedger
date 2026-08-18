<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\SecurityModel;
use App\Models\StockModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Master sekuritas, rekening/RDN, dan saham (§4, §5).
 *
 * @internal
 */
final class MasterDataTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TruncatesDomainTables;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SecurityModel $securities;
    private SecuritiesAccountModel $accounts;
    private StockModel $stocks;

    protected function setUp(): void
    {
        parent::setUp();

        // Setiap test berangkat dari keadaan yang sama; lihat TruncatesDomainTables
        // untuk alasan FK check dimatikan sementara.
        $this->truncateDomainTables();

        // Service di-share antar pemanggilan, jadi instance lamanya (beserta
        // cache di dalamnya) harus dibuang agar tidak membawa state test sebelumnya.
        \Config\Services::reset(true);

        $this->securities = new SecurityModel();
        $this->accounts   = new SecuritiesAccountModel();
        $this->stocks     = new StockModel();
    }

    // ------------------------------------------------------------- Sekuritas

    /**
     * Transaksi selalu merujuk REKENING. Sekuritas tanpa rekening berarti
     * sekuritas yang tidak akan pernah bisa dipakai bertransaksi.
     */
    public function testCreatingSecuritiesAlsoCreatesItsFirstAccount(): void
    {
        $security = service('securityService')->create(
            ['code' => 'ajaib', 'name' => 'Ajaib Sekuritas Asia'],
            ['label' => 'RDN Utama', 'account_number' => '1234567890']
        );

        $this->assertSame('AJAIB', $security->code, 'Kode sekuritas harus dinormalkan ke huruf besar.');

        $accounts = $this->accounts->forSecurities($security->id);
        $this->assertCount(1, $accounts);
        $this->assertSame('RDN Utama', $accounts[0]->label);
    }

    public function testDuplicateSecuritiesCodeIsRejectedRegardlessOfCase(): void
    {
        service('securityService')->create(['code' => 'IPOT', 'name' => 'Indo Premier']);

        $this->expectException(BusinessRuleException::class);

        service('securityService')->create(['code' => 'ipot', 'name' => 'Duplikat']);
    }

    /**
     * Kegagalan di tengah proses tidak boleh meninggalkan sekuritas yatim.
     */
    public function testFailedAccountCreationRollsBackTheSecurities(): void
    {
        $before = $this->securities->countAllResults();

        try {
            service('securityService')->create(
                ['code' => 'MIRAE', 'name' => 'Mirae Asset'],
                // bank_name maksimal 100 karakter -> validasi rekening gagal
                ['label' => 'RDN Utama', 'bank_name' => str_repeat('x', 200)]
            );
            $this->fail('Seharusnya melempar BusinessRuleException.');
        } catch (BusinessRuleException) {
            // diharapkan
        }

        $this->assertSame($before, $this->securities->countAllResults(), 'Sekuritas seharusnya ikut dibatalkan.');
        $this->assertNull($this->securities->findByCode('MIRAE'));
    }

    /**
     * Label rekening yang dikosongkan pengguna jatuh ke nilai bawaan,
     * bukan menggagalkan penyimpanan sekuritas.
     */
    public function testBlankAccountLabelFallsBackToDefault(): void
    {
        $security = service('securityService')->create(
            ['code' => 'DFLT', 'name' => 'Uji Default'],
            ['label' => '   ']
        );

        $this->assertSame('RDN Utama', $this->accounts->forSecurities($security->id)[0]->label);
    }

    public function testDeactivatingSecuritiesAlsoDeactivatesItsAccounts(): void
    {
        $security = service('securityService')->create(['code' => 'BCAS', 'name' => 'BCA Sekuritas']);
        service('securityService')->addAccount($security->id, ['label' => 'RDN Kedua']);

        service('securityService')->deactivate($security->id);

        $this->assertFalse($this->securities->find($security->id)->is_active);

        foreach ($this->accounts->forSecurities($security->id) as $account) {
            $this->assertFalse($account->is_active, $account->label . ' seharusnya ikut nonaktif.');
        }
    }

    public function testSecuritiesWithAccountsCannotBeDeleted(): void
    {
        $security = service('securityService')->create(['code' => 'STOCKBIT', 'name' => 'Stockbit Sekuritas']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/masih memiliki 1 rekening/');

        service('securityService')->delete($security->id);
    }

    public function testAccountCannotBeMovedToAnotherSecurities(): void
    {
        $a = service('securityService')->create(['code' => 'AAA', 'name' => 'Sekuritas A']);
        $b = service('securityService')->create(['code' => 'BBB', 'name' => 'Sekuritas B']);

        $account = $this->accounts->forSecurities($a->id)[0];

        service('securityService')->updateAccount($account->id, [
            'label'         => 'Diubah',
            'securities_id' => $b->id, // harus diabaikan
        ]);

        $this->assertSame($a->id, $this->accounts->find($account->id)->securities_id);
    }

    /**
     * §36: nomor rekening tidak ditampilkan terbuka secara default.
     */
    public function testAccountNumberIsMaskedByDefault(): void
    {
        $security = service('securityService')->create(
            ['code' => 'MASK', 'name' => 'Uji Masking'],
            ['label' => 'RDN', 'account_number' => '1234567890']
        );

        $account = $this->accounts->forSecurities($security->id)[0];

        $this->assertSame('••••••7890', $account->maskedAccountNumber());
        $this->assertStringNotContainsString('123456', $account->maskedAccountNumber());
    }

    public function testOptionsOnlyListActiveAccounts(): void
    {
        $active   = service('securityService')->create(['code' => 'ACT', 'name' => 'Aktif']);
        $inactive = service('securityService')->create(['code' => 'INA', 'name' => 'Nonaktif']);
        service('securityService')->deactivate($inactive->id);

        $options = $this->accounts->options();

        $this->assertCount(1, $options);
        $this->assertStringContainsString('ACT', implode(' ', $options));
        $this->assertStringNotContainsString('INA', implode(' ', $options));
    }

    // ----------------------------------------------------------------- Saham

    public function testTickerIsStoredUppercaseAndTrimmed(): void
    {
        $stock = service('stockService')->create([
            'ticker'       => '  bbca ',
            'company_name' => 'Bank Central Asia Tbk',
        ]);

        $this->assertSame('BBCA', $stock->ticker);
    }

    public function testDuplicateTickerIsRejected(): void
    {
        service('stockService')->create(['ticker' => 'BBRI', 'company_name' => 'Bank Rakyat Indonesia']);

        $this->expectException(BusinessRuleException::class);

        service('stockService')->create(['ticker' => 'bbri', 'company_name' => 'Duplikat']);
    }

    public function testBlankSectorIsStoredAsNullNotEmptyString(): void
    {
        $stock = service('stockService')->create([
            'ticker'       => 'TLKM',
            'company_name' => 'Telkom Indonesia Tbk',
            'sector'       => '   ',
        ]);

        $this->assertNull($this->stocks->find($stock->id)->sector);
    }

    public function testInactiveStockIsExcludedFromTransactionOptions(): void
    {
        $active = service('stockService')->create(['ticker' => 'BMRI', 'company_name' => 'Bank Mandiri']);
        $hidden = service('stockService')->create(['ticker' => 'GOTO', 'company_name' => 'GoTo Gojek Tokopedia']);
        service('stockService')->setActive($hidden->id, false);

        $options = $this->stocks->options();

        $this->assertArrayHasKey($active->id, $options);
        $this->assertArrayNotHasKey($hidden->id, $options);
    }

    public function testSectorListIsDistinctAndSkipsEmptyValues(): void
    {
        service('stockService')->create(['ticker' => 'BBCA', 'company_name' => 'BCA', 'sector' => 'Keuangan']);
        service('stockService')->create(['ticker' => 'BBRI', 'company_name' => 'BRI', 'sector' => 'Keuangan']);
        service('stockService')->create(['ticker' => 'TLKM', 'company_name' => 'Telkom', 'sector' => 'Infrastruktur']);
        service('stockService')->create(['ticker' => 'ASII', 'company_name' => 'Astra']);

        $sectors = (new StockModel())->sectors();

        $this->assertSame(['Infrastruktur', 'Keuangan'], $sectors);
    }
}
