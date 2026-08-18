<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\DividendTransaction;
use CodeIgniter\Model;

class DividendTransactionModel extends Model
{
    protected $table         = 'dividend_transactions';
    protected $primaryKey    = 'id';
    protected $returnType    = DividendTransaction::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'transaction_number', 'transaction_date', 'securities_account_id', 'stock_id',
        'quantity_eligible', 'dividend_per_share', 'gross_dividend', 'tax', 'net_dividend',
        'notes', 'status', 'journal_entry_id', 'created_by',
    ];

    public function withRelations(): self
    {
        return $this->select('dividend_transactions.*, s.code AS securities_code, sa.label AS account_label,
                              st.ticker, st.company_name')
            ->join('securities_accounts sa', 'sa.id = dividend_transactions.securities_account_id')
            ->join('securities s', 's.id = sa.securities_id')
            ->join('stocks st', 'st.id = dividend_transactions.stock_id');
    }
}
