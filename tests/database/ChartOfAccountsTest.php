<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Perlindungan akun inti. Bila salah satu akun ini hilang, dinonaktifkan, atau
 * kodenya berubah, pembuatan jurnal pada Phase 4 akan gagal di tengah transaksi.
 *
 * @internal
 */
final class ChartOfAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TruncatesDomainTables;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = null;

    private AccountModel $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        // Setiap test berangkat dari keadaan yang sama; lihat TruncatesDomainTables
        // untuk alasan FK check dimatikan sementara.
        $this->truncateDomainTables();

        // Service di-share antar pemanggilan, jadi instance lamanya (beserta
        // cache di dalamnya) harus dibuang agar tidak membawa state test sebelumnya.
        \Config\Services::reset(true);

        $this->accounts = new AccountModel();
        service('chartOfAccounts')->ensureSystemAccounts();
    }

    public function testSeederCreatesEveryAccountCodeAndReportsHealthy(): void
    {
        foreach (AccountCode::cases() as $code) {
            $account = $this->accounts->findByCode($code->value);

            $this->assertNotNull($account, 'Akun inti ' . $code->value . ' tidak dibuat.');
            $this->assertTrue($account->is_system);
            $this->assertSame($code->type(), $account->type());
            $this->assertSame($code->normalBalance(), $account->normalBalance());
        }

        $this->assertSame([], service('chartOfAccounts')->verifySystemAccounts());
    }

    public function testSeederIsIdempotent(): void
    {
        $before = $this->accounts->countAllResults();

        $result = service('chartOfAccounts')->ensureSystemAccounts();

        $this->assertSame(0, $result['created']);
        $this->assertSame($before, $this->accounts->countAllResults());
    }

    public function testOwnerWithdrawalIsPersistedAsContraEquity(): void
    {
        $account = $this->accounts->findByCode(AccountCode::OwnerWithdrawal->value);

        $this->assertSame('equity', $account->type);
        $this->assertSame('debit', $account->normal_balance);
        $this->assertTrue($account->isContra());
    }

    public function testSystemAccountCannotBeDeleted(): void
    {
        $cash = $this->accounts->findByCode(AccountCode::Cash->value);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/tidak dapat dihapus/');

        service('chartOfAccounts')->delete($cash->id);
    }

    public function testSystemAccountCodeCannotBeChanged(): void
    {
        $cash = $this->accounts->findByCode(AccountCode::Cash->value);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/tidak boleh diubah kode/');

        service('chartOfAccounts')->update($cash->id, ['code' => '9999']);
    }

    public function testSystemAccountCannotBeDeactivated(): void
    {
        $cash = $this->accounts->findByCode(AccountCode::Cash->value);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/tidak boleh dinonaktifkan/');

        service('chartOfAccounts')->update($cash->id, ['is_active' => 0]);
    }

    public function testSystemAccountNameCanStillBeAdjusted(): void
    {
        $cash    = $this->accounts->findByCode(AccountCode::Cash->value);
        $updated = service('chartOfAccounts')->update($cash->id, ['name' => 'Kas & Setara Kas']);

        $this->assertSame('Kas & Setara Kas', $updated->name);
        $this->assertSame('1000', $updated->code);
        $this->assertTrue($updated->is_system);
    }

    public function testNormalBalanceDefaultsToAccountType(): void
    {
        $account = service('chartOfAccounts')->create([
            'code' => '5300',
            'name' => 'Beban Lain-lain',
            'type' => 'expense',
        ]);

        $this->assertSame('debit', $account->normal_balance);
        $this->assertFalse($account->is_system, 'Akun buatan pengguna tidak boleh menjadi akun inti.');
    }

    public function testContraAccountCanBeCreatedByChoosingOppositeSide(): void
    {
        $account = service('chartOfAccounts')->create([
            'code'           => '1900',
            'name'           => 'Akumulasi Penyusutan',
            'type'           => 'asset',
            'normal_balance' => 'credit',
        ]);

        $this->assertTrue($account->isContra());
    }

    public function testAccountCannotBeItsOwnParent(): void
    {
        $account = service('chartOfAccounts')->create([
            'code' => '5400', 'name' => 'Beban Uji', 'type' => 'expense',
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/induk bagi dirinya sendiri/');

        service('chartOfAccounts')->update($account->id, ['parent_id' => $account->id]);
    }

    public function testParentChildCycleIsRejected(): void
    {
        $parent = service('chartOfAccounts')->create(['code' => '5500', 'name' => 'Induk', 'type' => 'expense']);
        $child  = service('chartOfAccounts')->create([
            'code' => '5501', 'name' => 'Anak', 'type' => 'expense', 'parent_id' => $parent->id,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/lingkaran/');

        // Menjadikan anak sebagai induk dari induknya sendiri.
        service('chartOfAccounts')->update($parent->id, ['parent_id' => $child->id]);
    }

    public function testAccountWithChildrenCannotBeDeleted(): void
    {
        $parent = service('chartOfAccounts')->create(['code' => '5600', 'name' => 'Induk', 'type' => 'expense']);
        service('chartOfAccounts')->create([
            'code' => '5601', 'name' => 'Anak', 'type' => 'expense', 'parent_id' => $parent->id,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/sub-akun/');

        service('chartOfAccounts')->delete($parent->id);
    }

    /**
     * idFor() dipakai mesin jurnal Phase 4 untuk menerjemahkan AccountCode ke id.
     */
    public function testIdForResolvesEverySystemAccount(): void
    {
        $model = new AccountModel();

        foreach (AccountCode::cases() as $code) {
            $this->assertGreaterThan(0, $model->idFor($code));
        }
    }

    public function testVerifyReportsMissingSystemAccount(): void
    {
        $model = new AccountModel();
        $gain  = $model->findByCode(AccountCode::RealizedGain->value);
        $model->delete($gain->id, true);

        $problems = service('chartOfAccounts')->verifySystemAccounts();

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('4000', implode(' ', $problems));
    }
}
