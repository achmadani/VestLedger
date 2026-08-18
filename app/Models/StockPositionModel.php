<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\StockPosition;
use CodeIgniter\Model;

class StockPositionModel extends Model
{
    protected $table         = 'stock_positions';
    protected $primaryKey    = 'id';
    protected $returnType    = StockPosition::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'securities_account_id', 'stock_id', 'quantity', 'book_value', 'last_transaction_date',
    ];

    public function findPosition(int $securitiesAccountId, int $stockId): ?StockPosition
    {
        return $this->where('securities_account_id', $securitiesAccountId)
            ->where('stock_id', $stockId)
            ->first();
    }

    /**
     * Posisi yang masih dimiliki, lengkap dengan identitas sekuritas & saham.
     *
     * @return list<StockPosition>
     */
    public function held(?int $securitiesAccountId = null, ?int $stockId = null): array
    {
        $builder = $this->select('stock_positions.*, s.code AS securities_code, s.name AS securities_name,
                                  sa.label AS account_label, st.ticker, st.company_name, st.sector')
            ->join('securities_accounts sa', 'sa.id = stock_positions.securities_account_id')
            ->join('securities s', 's.id = sa.securities_id')
            ->join('stocks st', 'st.id = stock_positions.stock_id')
            ->where('stock_positions.quantity >', 0);

        if ($securitiesAccountId !== null) {
            $builder->where('stock_positions.securities_account_id', $securitiesAccountId);
        }

        if ($stockId !== null) {
            $builder->where('stock_positions.stock_id', $stockId);
        }

        return $builder->orderBy('st.ticker', 'asc')->orderBy('s.code', 'asc')->findAll();
    }

    /**
     * Total kepemilikan per ticker lintas seluruh sekuritas (§5).
     *
     * @return list<array{stock_id:int, ticker:string, company_name:string, quantity:string, book_value:string}>
     */
    public function totalsByTicker(): array
    {
        return $this->db->table('stock_positions sp')
            ->select('sp.stock_id, st.ticker, st.company_name,
                      SUM(sp.quantity) AS quantity, SUM(sp.book_value) AS book_value')
            ->join('stocks st', 'st.id = sp.stock_id')
            ->where('sp.quantity >', 0)
            ->groupBy('sp.stock_id, st.ticker, st.company_name')
            ->orderBy('st.ticker', 'asc')
            ->get()
            ->getResultArray();
    }

    /**
     * Total book value seluruh posisi — pembanding terhadap saldo akun 1100.
     */
    public function totalBookValue(): string
    {
        $row = $this->db->table('stock_positions')
            ->selectSum('book_value', 'total')
            ->get()
            ->getRowArray();

        return (string) ($row['total'] ?? '0');
    }
}
