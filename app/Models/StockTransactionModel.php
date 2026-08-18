<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\StockTransaction;
use CodeIgniter\Model;

class StockTransactionModel extends Model
{
    protected $table         = 'stock_transactions';
    protected $primaryKey    = 'id';
    protected $returnType    = StockTransaction::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'transaction_number', 'transaction_date', 'type', 'securities_account_id', 'stock_id',
        'quantity', 'lots', 'price', 'gross_amount', 'broker_fee', 'tax', 'levy', 'net_amount',
        'book_value_sold', 'realized_gain_gross', 'realized_gain_net',
        'quantity_before', 'book_value_before', 'quantity_after', 'book_value_after',
        'notes', 'status', 'journal_entry_id', 'created_by',
    ];

    public function withRelations(): self
    {
        return $this->select('stock_transactions.*, s.code AS securities_code, sa.label AS account_label,
                              st.ticker, st.company_name')
            ->join('securities_accounts sa', 'sa.id = stock_transactions.securities_account_id')
            ->join('securities s', 's.id = sa.securities_id')
            ->join('stocks st', 'st.id = stock_transactions.stock_id');
    }

    /**
     * Seluruh transaksi satu posisi, berurutan menurut waktu.
     *
     * Inilah masukan untuk membangun ulang posisi dari nol (§28). Urutan harus
     * deterministik: tanggal lebih dulu, lalu id — dua transaksi pada tanggal
     * yang sama harus selalu diproses dalam urutan yang sama, jika tidak
     * average cost hasil rebuild bisa berbeda dari yang tercatat.
     *
     * @return list<StockTransaction>
     */
    public function forPositionInOrder(int $securitiesAccountId, int $stockId): array
    {
        return $this->where('securities_account_id', $securitiesAccountId)
            ->where('stock_id', $stockId)
            ->where('status', 'posted')
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->findAll();
    }

    /**
     * Kombinasi rekening+saham yang pernah ditransaksikan.
     *
     * @return list<array{securities_account_id:int, stock_id:int}>
     */
    public function distinctPositions(): array
    {
        return $this->db->table('stock_transactions')
            ->select('securities_account_id, stock_id')
            ->distinct()
            ->get()
            ->getResultArray();
    }
}
