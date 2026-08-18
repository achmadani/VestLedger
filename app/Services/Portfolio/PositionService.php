<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Entities\StockPosition;
use App\Enums\StockTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\StockPositionModel;
use App\Models\StockTransactionModel;
use App\ValueObjects\Money;
use LogicException;

/**
 * Posisi saham dan weighted average cost (§12).
 *
 * Yang disimpan hanya quantity (lembar) dan book_value. Average cost tidak
 * pernah disimpan; ia selalu diturunkan book_value / quantity. Jika average
 * cost disimpan dalam bentuk terbulatkan lalu dikalikan kembali saat penjualan,
 * sisa book value akan mengambang beberapa rupiah dan neraca berhenti balance.
 *
 * Tabel posisi adalah calculated state: seluruhnya dapat dibangun ulang dari
 * stock_transactions lewat rebuild() (§28).
 */
class PositionService
{
    public function __construct(
        private StockPositionModel $positions,
        private StockTransactionModel $transactions,
    ) {
    }

    /**
     * Posisi saat ini; mengembalikan posisi kosong bila belum pernah ada.
     */
    public function current(int $securitiesAccountId, int $stockId): StockPosition
    {
        $position = $this->positions->findPosition($securitiesAccountId, $stockId);

        if ($position !== null) {
            return $position;
        }

        return new StockPosition([
            'securities_account_id' => $securitiesAccountId,
            'stock_id'              => $stockId,
            'quantity'              => 0,
            'book_value'            => '0.00',
        ]);
    }

    /**
     * Book value yang dilepas ketika sejumlah lembar dijual (§11 Step 2).
     *
     * Dihitung PROPORSIONAL terhadap quantity, bukan qty × average cost yang
     * dibulatkan. Konsekuensinya, penjualan seluruh posisi selalu melepas
     * seluruh book value tanpa sisa.
     */
    public function bookValueForSale(StockPosition $position, int $quantitySold): Money
    {
        if ($position->quantity <= 0) {
            return Money::zero();
        }

        return $position->bookValue()->proportion($quantitySold, $position->quantity);
    }

    /**
     * §27: jumlah jual tidak boleh melebihi jumlah yang dimiliki.
     */
    public function assertCanSell(int $securitiesAccountId, int $stockId, int $quantity, string $tickerLabel): StockPosition
    {
        $position = $this->current($securitiesAccountId, $stockId);

        if ($position->quantity < $quantity) {
            throw new BusinessRuleException(sprintf(
                'Jumlah jual %s lembar melebihi kepemilikan %s pada rekening ini, yang hanya %s lembar.',
                number_format($quantity, 0, ',', '.'),
                $tickerLabel,
                number_format($position->quantity, 0, ',', '.')
            ));
        }

        return $position;
    }

    /**
     * Menambah posisi akibat pembelian.
     *
     * @param Money $bookCost seluruh biaya perolehan (harga + fee + pajak + levy)
     */
    public function applyBuy(int $securitiesAccountId, int $stockId, int $quantity, Money $bookCost, string $date): StockPosition
    {
        $this->assertInTransaction();

        $position = $this->current($securitiesAccountId, $stockId);

        return $this->persist(
            $position,
            $position->quantity + $quantity,
            $position->bookValue()->add($bookCost),
            $date,
        );
    }

    /**
     * Mengurangi posisi akibat penjualan.
     */
    public function applySell(int $securitiesAccountId, int $stockId, int $quantity, Money $bookValueSold, string $date): StockPosition
    {
        $this->assertInTransaction();

        $position    = $this->current($securitiesAccountId, $stockId);
        $newQuantity = $position->quantity - $quantity;
        $newBookValue = $position->bookValue()->subtract($bookValueSold);

        // Menjual habis harus mengosongkan book value sepenuhnya. Sisa beberapa
        // sen di posisi berjumlah nol lembar akan membuat akun 1100 tidak pernah
        // benar-benar nol dan neraca ikut melenceng.
        if ($newQuantity === 0 && ! $newBookValue->isZero()) {
            throw new LogicException(sprintf(
                'Posisi habis terjual tetapi menyisakan book value %s. Ini bug perhitungan proporsi.',
                $newBookValue->toDecimalString()
            ));
        }

        return $this->persist($position, $newQuantity, $newBookValue, $date);
    }

    /**
     * Membangun ulang SELURUH posisi dari stock_transactions (§28).
     *
     * Ini jaring pengaman konsistensi: bila tabel posisi pernah menyimpang,
     * ia dapat dipulihkan tanpa menyentuh buku besar sama sekali.
     *
     * @return array{positions:int, transactions:int}
     */
    public function rebuildAll(): array
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $db->table('stock_positions')->emptyTable();

            $positionCount    = 0;
            $transactionCount = 0;

            foreach ($this->transactions->distinctPositions() as $key) {
                $accountId = (int) $key['securities_account_id'];
                $stockId   = (int) $key['stock_id'];

                $quantity  = 0;
                $bookValue = Money::zero();
                $lastDate  = null;

                foreach ($this->transactions->forPositionInOrder($accountId, $stockId) as $transaction) {
                    $transactionCount++;
                    $lastDate = $transaction->transaction_date->format('Y-m-d');

                    if ($transaction->type() === StockTransactionType::Buy) {
                        $quantity += $transaction->quantity;
                        // Book cost pembelian = seluruh kas yang keluar (§10 + keputusan
                        // kapitalisasi seluruh biaya; lihat docs/ACCOUNTING.md).
                        $bookValue = $bookValue->add($transaction->netAmount());

                        continue;
                    }

                    $sold = $quantity > 0
                        ? $bookValue->proportion($transaction->quantity, $quantity)
                        : Money::zero();

                    $quantity -= $transaction->quantity;
                    $bookValue = $bookValue->subtract($sold);

                    if ($quantity === 0) {
                        $bookValue = Money::zero();
                    }
                }

                if ($quantity === 0 && $bookValue->isZero()) {
                    continue;
                }

                $this->positions->insert([
                    'securities_account_id' => $accountId,
                    'stock_id'              => $stockId,
                    'quantity'              => $quantity,
                    'book_value'            => $bookValue->toDecimalString(),
                    'last_transaction_date' => $lastDate,
                ]);

                $positionCount++;
            }

            $db->transCommit();

            return ['positions' => $positionCount, 'transactions' => $transactionCount];
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    private function persist(StockPosition $position, int $quantity, Money $bookValue, string $date): StockPosition
    {
        $data = [
            'securities_account_id' => $position->securities_account_id,
            'stock_id'              => $position->stock_id,
            'quantity'              => $quantity,
            'book_value'            => $bookValue->toDecimalString(),
            'last_transaction_date' => $date,
        ];

        if (($position->id ?? 0) <= 0) {
            $id = $this->positions->insert($data, true);
        } else {
            $this->positions->update($position->id, $data);
            $id = $position->id;
        }

        return $this->positions->find($id);
    }

    /**
     * Perubahan posisi harus selalu menyatu dengan transaksi dan jurnalnya (§8).
     */
    private function assertInTransaction(): void
    {
        if (db_connect()->transDepth === 0) {
            throw new LogicException(
                'Perubahan posisi wajib dilakukan di dalam database transaction, '
                . 'agar posisi, transaksi, dan jurnal selalu konsisten.'
            );
        }
    }
}
