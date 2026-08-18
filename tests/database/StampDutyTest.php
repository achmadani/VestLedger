<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Enums\AccountCode;
use App\Enums\CashTransactionType;
use App\Enums\PostingStatus;
use App\Models\CashTransactionModel;
use App\Models\StockTransactionModel;
use App\ValueObjects\Money;
use Tests\Support\Engine\EngineTestCase;

/**
 * Bea materai atas konfirmasi transaksi harian.
 *
 * @internal
 */
final class StampDutyTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([$this->ajaib, $this->ipot] as $account) {
            service('cashTransactions')->topUp([
                'transaction_date' => '2026-01-02', 'securities_account_id' => $account, 'amount' => 500_000_000,
            ]);
        }
    }

    private function buy(int $account, string $date, int $quantity, int $price): void
    {
        service('stockTransactions')->buy([
            'transaction_date'      => $date,
            'securities_account_id' => $account,
            'stock_id'              => $this->bbca,
            'quantity'              => $quantity,
            'price'                 => $price,
        ]);
    }

    private function stampDuties(?int $account = null): array
    {
        $model = (new CashTransactionModel())
            ->where('type', CashTransactionType::StampDuty->value)
            ->where('status', PostingStatus::Posted->value);

        if ($account !== null) {
            $model->where('securities_account_id', $account);
        }

        return $model->findAll();
    }

    public function testNoStampDutyBelowTheThreshold(): void
    {
        // Bruto Rp9.000.000 — di bawah Rp10 juta.
        $this->buy($this->ajaib, '2026-01-05', 9_000, 1_000);

        $this->assertSame([], $this->stampDuties());
        $this->assertEveryJournalBalanced();
    }

    public function testExactlyAtTheThresholdIsNotCharged(): void
    {
        // Tepat Rp10.000.000 — aturannya "melebihi", bukan "mencapai".
        $this->buy($this->ajaib, '2026-01-05', 10_000, 1_000);

        $this->assertSame([], $this->stampDuties());
    }

    public function testStampDutyIsChargedOnceAboveTheThreshold(): void
    {
        $this->buy($this->ajaib, '2026-01-05', 11_000, 1_000);

        $duties = $this->stampDuties();

        $this->assertCount(1, $duties);
        $this->assertMoneyEquals('10000.00', $duties[0]->amount());
        $this->assertSame($this->ajaib, $duties[0]->securities_account_id);

        // Bea materai adalah pungutan negara, jadi masuk Pajak & Levy.
        $this->assertTrue($this->accountBalance(AccountCode::TaxAndLevy)->greaterThan(Money::of('9999')));

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Dasar pengenaannya adalah TOTAL nilai transaksi hari itu; beli dan jual
     * dijumlahkan karena keduanya tercantum pada konfirmasi yang sama.
     */
    public function testBuyAndSellOnTheSameDayAreAddedTogether(): void
    {
        // Beli Rp6 juta, lalu jual Rp5 juta -> total Rp11 juta.
        $this->buy($this->ajaib, '2026-01-05', 6_000, 1_000);
        $this->assertSame([], $this->stampDuties(), 'Rp6 juta saja belum melewati ambang');

        service('stockTransactions')->sell([
            'transaction_date' => '2026-01-05', 'securities_account_id' => $this->ajaib,
            'stock_id' => $this->bbca, 'quantity' => 5_000, 'price' => 1_000,
        ]);

        $this->assertCount(1, $this->stampDuties(), 'Beli + jual = Rp11 juta -> kena materai');
    }

    /**
     * Materai dikenakan sekali per hari, bukan per transaksi.
     */
    public function testOnlyOneStampDutyPerAccountPerDay(): void
    {
        $this->buy($this->ajaib, '2026-01-05', 11_000, 1_000);
        $this->buy($this->ajaib, '2026-01-05', 20_000, 1_000);
        $this->buy($this->ajaib, '2026-01-05', 5_000, 1_000);

        $this->assertCount(1, $this->stampDuties());
    }

    /**
     * Tiap broker menerbitkan konfirmasinya sendiri, jadi materainya terpisah.
     */
    public function testEachSecuritiesAccountIsChargedSeparately(): void
    {
        $this->buy($this->ajaib, '2026-01-05', 11_000, 1_000);
        $this->buy($this->ipot, '2026-01-05', 12_000, 1_000);

        $this->assertCount(1, $this->stampDuties($this->ajaib));
        $this->assertCount(1, $this->stampDuties($this->ipot));
        $this->assertCount(2, $this->stampDuties());
    }

    public function testDifferentDaysAreChargedSeparately(): void
    {
        $this->buy($this->ajaib, '2026-01-05', 11_000, 1_000);
        $this->buy($this->ajaib, '2026-01-06', 11_000, 1_000);

        $this->assertCount(2, $this->stampDuties());
    }

    /**
     * Transaksi yang dimasukkan MUNDUR harus ikut memicu materai — inilah
     * alasan perhitungannya dibuat menyesuaikan diri, bukan sekali tambah.
     */
    public function testBackdatedTransactionCrossingTheThresholdTriggersStampDuty(): void
    {
        $this->buy($this->ajaib, '2026-03-10', 6_000, 1_000);
        $this->assertSame([], $this->stampDuties());

        // Transaksi lain di HARI YANG SAMA, dicatat belakangan.
        $this->buy($this->ajaib, '2026-03-10', 5_000, 1_000);

        $duties = $this->stampDuties();
        $this->assertCount(1, $duties);
        $this->assertSame('2026-03-10', $duties[0]->transaction_date->format('Y-m-d'));
    }

    /**
     * Bila transaksi dibatalkan dan total hari itu turun di bawah ambang,
     * materainya ikut dibalik — tidak boleh ada biaya tanpa dasar transaksi.
     */
    public function testReversingBackBelowTheThresholdReversesTheStampDuty(): void
    {
        $this->buy($this->ajaib, '2026-01-05', 6_000, 1_000);
        $this->buy($this->ajaib, '2026-01-05', 5_000, 1_000);

        $this->assertCount(1, $this->stampDuties());

        $latest = (new StockTransactionModel())->orderBy('id', 'desc')->first();
        service('reversals')->reverseStock($latest->id);

        $this->assertSame([], $this->stampDuties(), 'Materai harus ikut dibalik');
        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Membatalkan sebagian transaksi yang tetap menyisakan nilai di atas ambang
     * tidak boleh menghapus materainya.
     */
    public function testStampDutyStaysWhenTurnoverRemainsAboveTheThreshold(): void
    {
        $this->buy($this->ajaib, '2026-01-05', 20_000, 1_000);
        $this->buy($this->ajaib, '2026-01-05', 5_000, 1_000);

        $this->assertCount(1, $this->stampDuties());

        $latest = (new StockTransactionModel())->orderBy('id', 'desc')->first();
        service('reversals')->reverseStock($latest->id);

        $this->assertCount(1, $this->stampDuties(), 'Sisa Rp20 juta masih di atas ambang');
    }

    /**
     * Materai mengurangi kas, sehingga arus kas dan neraca tetap konsisten.
     */
    public function testStampDutyReducesCashAndKeepsTheBooksBalanced(): void
    {
        $cashBefore = $this->accountBalance(AccountCode::Cash, $this->ajaib);

        $this->buy($this->ajaib, '2026-01-05', 11_000, 1_000);

        $cashAfter = $this->accountBalance(AccountCode::Cash, $this->ajaib);
        $spent     = $cashBefore->subtract($cashAfter);

        // Rp11.000.000 + biaya 0,15% (Rp16.500) + materai Rp10.000
        $this->assertMoneyEquals('11026500.00', $spent);

        $this->assertEveryJournalBalanced();
        $this->assertAccountingEquationHolds();
    }

    /**
     * Materai dibuat sistem dan tidak boleh muncul sebagai pilihan di form.
     */
    public function testStampDutyIsNotOfferedAsAManualTransactionType(): void
    {
        $this->assertTrue(CashTransactionType::StampDuty->isSystemGenerated());
        $this->assertFalse(CashTransactionType::AdminFee->isSystemGenerated());
    }
}
