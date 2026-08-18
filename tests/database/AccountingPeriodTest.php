<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Exceptions\BusinessRuleException;
use App\Models\AccountingPeriodModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Concerns\TruncatesDomainTables;

/**
 * Aturan urutan buka/tutup periode (§25).
 *
 * @internal
 */
final class AccountingPeriodTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TruncatesDomainTables;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = null;

    private AccountingPeriodModel $periods;

    protected function setUp(): void
    {
        parent::setUp();

        // Setiap test berangkat dari keadaan yang sama; lihat TruncatesDomainTables
        // untuk alasan FK check dimatikan sementara.
        $this->truncateDomainTables();

        // Service di-share antar pemanggilan, jadi instance lamanya (beserta
        // cache di dalamnya) harus dibuang agar tidak membawa state test sebelumnya.
        \Config\Services::reset(true);

        $this->periods = new AccountingPeriodModel();
        service('accountingPeriod')->generateYear(2026);
    }

    /**
     * closed_by memiliki foreign key ke users, jadi test harus memakai user
     * yang benar-benar ada — id karangan justru akan ditolak database.
     */
    private function makeUserId(): int
    {
        $users = new UserModel();
        $user  = new User([
            'username' => 'closer_' . bin2hex(random_bytes(4)),
            'email'    => bin2hex(random_bytes(4)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);

        return (int) $users->getInsertID();
    }

    public function testGenerateYearCreatesTwelveMonthsWithCorrectBoundaries(): void
    {
        $periods = $this->periods->forYear(2026);

        $this->assertCount(12, $periods);
        $this->assertSame('2026-01', $periods[0]->code);
        $this->assertSame('2026-01-01', $periods[0]->start_date->format('Y-m-d'));
        $this->assertSame('2026-01-31', $periods[0]->end_date->format('Y-m-d'));

        // Februari 2026 bukan tahun kabisat -> 28 hari.
        $this->assertSame('2026-02-28', $periods[1]->end_date->format('Y-m-d'));
        $this->assertSame('2026-12-31', $periods[11]->end_date->format('Y-m-d'));
    }

    public function testLeapYearFebruaryHasTwentyNineDays(): void
    {
        service('accountingPeriod')->generateYear(2028);

        $this->assertSame('2028-02-29', $this->periods->findByCode('2028-02')->end_date->format('Y-m-d'));
    }

    public function testGenerateYearIsIdempotent(): void
    {
        $this->assertSame(0, service('accountingPeriod')->generateYear(2026));
        $this->assertCount(12, $this->periods->forYear(2026));
    }

    public function testNewPeriodsStartOpen(): void
    {
        foreach ($this->periods->forYear(2026) as $period) {
            $this->assertTrue($period->isOpen());
        }
    }

    public function testClosingRecordsWhoAndWhen(): void
    {
        $userId  = $this->makeUserId();
        $january = $this->periods->findByCode('2026-01');
        $closed  = service('accountingPeriod')->close($january->id, $userId);

        $this->assertFalse($closed->isOpen());
        $this->assertSame($userId, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }

    /**
     * Tanpa aturan ini, laba periode dihitung di atas periode yang isinya
     * masih bisa berubah.
     */
    public function testPeriodCannotBeClosedWhileAnEarlierPeriodIsStillOpen(): void
    {
        $march = $this->periods->findByCode('2026-03');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/periode sebelumnya yang terbuka/');

        service('accountingPeriod')->close($march->id);
    }

    public function testPeriodsCanBeClosedInOrder(): void
    {
        foreach (['2026-01', '2026-02', '2026-03'] as $code) {
            $period = $this->periods->findByCode($code);
            $closed = service('accountingPeriod')->close($period->id);

            $this->assertFalse($closed->isOpen(), $code . ' seharusnya tertutup.');
        }
    }

    public function testOnlyTheLatestClosedPeriodCanBeReopened(): void
    {
        foreach (['2026-01', '2026-02'] as $code) {
            service('accountingPeriod')->close($this->periods->findByCode($code)->id);
        }

        $january = $this->periods->findByCode('2026-01');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/periode sesudahnya yang tertutup/');

        service('accountingPeriod')->reopen($january->id);
    }

    public function testLatestClosedPeriodReopensAndClearsClosingMetadata(): void
    {
        $userId = $this->makeUserId();

        foreach (['2026-01', '2026-02'] as $code) {
            service('accountingPeriod')->close($this->periods->findByCode($code)->id, $userId);
        }

        $reopened = service('accountingPeriod')->reopen($this->periods->findByCode('2026-02')->id);

        $this->assertTrue($reopened->isOpen());
        $this->assertNull($reopened->closed_at);
        $this->assertNull($reopened->closed_by);
    }

    public function testAlreadyClosedPeriodCannotBeClosedTwice(): void
    {
        $january = $this->periods->findByCode('2026-01');
        service('accountingPeriod')->close($january->id);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/sudah tertutup/');

        service('accountingPeriod')->close($january->id);
    }

    public function testDateInsideOpenPeriodIsPostable(): void
    {
        service('accountingPeriod')->assertDateIsPostable('2026-05-15');

        $this->assertTrue(true, 'Tidak ada exception yang dilempar.');
    }

    public function testDateInsideClosedPeriodIsRejected(): void
    {
        service('accountingPeriod')->close($this->periods->findByCode('2026-01')->id);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/sudah ditutup/');

        service('accountingPeriod')->assertDateIsPostable('2026-01-15');
    }

    public function testDateWithoutAnyPeriodIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Belum ada periode akuntansi/');

        service('accountingPeriod')->assertDateIsPostable('2031-06-10');
    }

    public function testPeriodBoundaryDatesAreCoveredByTheirOwnPeriod(): void
    {
        $this->assertSame('2026-01', $this->periods->findForDate('2026-01-01')->code);
        $this->assertSame('2026-01', $this->periods->findForDate('2026-01-31')->code);
        $this->assertSame('2026-02', $this->periods->findForDate('2026-02-01')->code);
    }

    public function testYearOutsideSupportedRangeIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);

        service('accountingPeriod')->generateYear(1999);
    }
}
