<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Entities\MarketPrice;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketPriceModel;
use App\Models\StockModel;
use App\Services\Accounting\AuditLogger;
use App\ValueObjects\Price;

/**
 * Input harga pasar (§14).
 *
 * Harga pasar tidak pernah masuk buku besar dan tidak mengubah book cost
 * historis. Karena itu tidak ada jurnal, tidak ada database transaction lintas
 * tabel, dan tidak ada pemeriksaan periode akuntansi di sini — harga hanyalah
 * data referensi untuk pelaporan.
 */
class MarketPriceService
{
    public function __construct(
        private MarketPriceModel $prices,
        private StockModel $stocks,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Menyimpan atau memperbarui harga penutupan sebuah saham pada satu tanggal.
     *
     * Input ulang untuk tanggal yang sama akan MENIMPA, bukan menggandakan —
     * koreksi harga adalah hal wajar dan tidak boleh meninggalkan dua harga
     * berbeda untuk satu hari yang sama.
     */
    public function record(array $input): MarketPrice
    {
        $stock = $this->stocks->find((int) ($input['stock_id'] ?? 0));

        if ($stock === null) {
            throw new BusinessRuleException('Saham wajib dipilih.');
        }

        $date = trim((string) ($input['price_date'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new BusinessRuleException('Tanggal harga wajib diisi dengan format YYYY-MM-DD.');
        }

        if ($date > date('Y-m-d')) {
            throw new BusinessRuleException('Tanggal harga tidak boleh di masa depan.');
        }

        $price = $this->parsePrice($input['closing_price'] ?? null);

        $existing = $this->prices->findForDate($stock->id, $date);

        $data = [
            'stock_id'      => $stock->id,
            'price_date'    => $date,
            'closing_price' => $price->toDecimalString(),
            'notes'         => $input['notes'] ?? null,
            'created_by'    => auth()->id(),
        ];

        if ($existing !== null) {
            if ($this->prices->update($existing->id, $data) === false) {
                throw new BusinessRuleException('Harga gagal diperbarui.', array_values($this->prices->errors()));
            }

            $this->audit->record(
                'updated',
                'market_price',
                $existing->id,
                sprintf('Harga %s %s diubah menjadi %s', $stock->ticker, $date, $price->toDecimalString()),
                ['closing_price' => $existing->closingPrice()->toDecimalString()],
                ['closing_price' => $price->toDecimalString()],
            );

            return $this->prices->find($existing->id);
        }

        $id = $this->prices->insert($data, true);

        if ($id === false) {
            throw new BusinessRuleException('Harga gagal disimpan.', array_values($this->prices->errors()));
        }

        $this->audit->record(
            'created',
            'market_price',
            $id,
            sprintf('Harga %s %s = %s', $stock->ticker, $date, $price->toDecimalString()),
        );

        return $this->prices->find($id);
    }

    /**
     * Input massal: beberapa saham sekaligus untuk satu tanggal.
     *
     * Ini bentuk input yang paling sering dipakai — menyalin harga penutupan
     * seluruh portofolio pada satu hari bursa.
     *
     * @param array<int, mixed> $pricesByStock [stock_id => harga]
     *
     * @return array{saved:int, skipped:int}
     */
    public function recordMany(string $date, array $pricesByStock, ?string $notes = null): array
    {
        $saved   = 0;
        $skipped = 0;

        foreach ($pricesByStock as $stockId => $value) {
            // Baris yang dikosongkan pengguna dilewati, bukan dianggap nol.
            if ($value === null || trim((string) $value) === '') {
                $skipped++;

                continue;
            }

            $this->record([
                'stock_id'      => (int) $stockId,
                'price_date'    => $date,
                'closing_price' => $value,
                'notes'         => $notes,
            ]);

            $saved++;
        }

        if ($saved === 0) {
            throw new BusinessRuleException('Tidak ada harga yang diisi.');
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    public function delete(int $id): void
    {
        $price = $this->prices->find($id);

        if ($price === null) {
            throw new BusinessRuleException('Data harga tidak ditemukan.');
        }

        $this->prices->delete($id);

        $this->audit->record('deleted', 'market_price', $id, 'Harga pasar dihapus');
    }

    private function parsePrice(mixed $value): Price
    {
        if ($value === null || trim((string) $value) === '') {
            throw new BusinessRuleException('Harga penutupan wajib diisi.');
        }

        try {
            $price = Price::of(is_string($value) ? $value : (float) $value);
        } catch (\InvalidArgumentException) {
            throw new BusinessRuleException('Harga penutupan bukan nilai yang sah.');
        }

        if (! $price->isPositive()) {
            throw new BusinessRuleException('Harga penutupan harus lebih besar dari nol.');
        }

        return $price;
    }
}
