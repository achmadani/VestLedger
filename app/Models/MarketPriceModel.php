<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\MarketPrice;
use CodeIgniter\Model;

class MarketPriceModel extends Model
{
    protected $table         = 'market_prices';
    protected $primaryKey    = 'id';
    protected $returnType    = MarketPrice::class;
    protected $useTimestamps = true;
    protected $allowedFields = ['stock_id', 'price_date', 'closing_price', 'notes', 'created_by'];
    protected $validationRules = [
        'stock_id'      => 'required|is_natural_no_zero|is_not_unique[stocks.id]',
        'price_date'    => 'required|valid_date[Y-m-d]',
        'closing_price' => 'required|greater_than[0]',
        'notes'         => 'permit_empty|max_length[255]',
    ];
    protected $validationMessages = [
        'stock_id'      => ['required' => 'Saham wajib dipilih.'],
        'price_date'    => ['required' => 'Tanggal harga wajib diisi.'],
        'closing_price' => [
            'required'     => 'Harga penutupan wajib diisi.',
            'greater_than' => 'Harga penutupan harus lebih besar dari nol.',
        ],
    ];

    public function findForDate(int $stockId, string $date): ?MarketPrice
    {
        return $this->where('stock_id', $stockId)->where('price_date', $date)->first();
    }

    /**
     * Harga penutupan TERBARU per saham, pada atau sebelum sebuah tanggal (§14).
     *
     * Dikerjakan dalam satu query untuk seluruh saham sekaligus — bukan satu
     * query per saham, yang akan menjadi N+1 saat portofolio membesar (§34).
     *
     * @return array<int, array{price:string, date:string}>
     */
    public function latestPrices(?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');

        // Ambil tanggal terbaru per saham lebih dulu, lalu gabungkan kembali
        // untuk memperoleh harganya.
        $sql = 'SELECT mp.stock_id, mp.closing_price, mp.price_date
                FROM market_prices mp
                JOIN (
                    SELECT stock_id, MAX(price_date) AS latest_date
                    FROM market_prices
                    WHERE price_date <= ?
                    GROUP BY stock_id
                ) newest ON newest.stock_id = mp.stock_id AND newest.latest_date = mp.price_date';

        $prices = [];

        foreach ($this->db->query($sql, [$asOf])->getResultArray() as $row) {
            $prices[(int) $row['stock_id']] = [
                'price' => (string) $row['closing_price'],
                'date'  => (string) $row['price_date'],
            ];
        }

        return $prices;
    }

    /**
     * Riwayat harga lengkap dengan identitas saham.
     */
    public function withStock(): self
    {
        return $this->select('market_prices.*, s.ticker, s.company_name')
            ->join('stocks s', 's.id = market_prices.stock_id');
    }
}
