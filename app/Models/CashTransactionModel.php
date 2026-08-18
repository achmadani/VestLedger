<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\CashTransaction;
use CodeIgniter\Model;

class CashTransactionModel extends Model
{
    protected $table         = 'cash_transactions';
    protected $primaryKey    = 'id';
    protected $returnType    = CashTransaction::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'transaction_number', 'transaction_date', 'type', 'securities_account_id',
        'counterpart_account_id', 'amount', 'fee', 'tax', 'net_amount',
        'notes', 'status', 'journal_entry_id', 'created_by',
    ];

    public function withRelations(): self
    {
        return $this->select('cash_transactions.*, s.code AS securities_code, sa.label AS account_label,
                              cs.code AS counterpart_code, ca.label AS counterpart_label')
            ->join('securities_accounts sa', 'sa.id = cash_transactions.securities_account_id')
            ->join('securities s', 's.id = sa.securities_id')
            ->join('securities_accounts ca', 'ca.id = cash_transactions.counterpart_account_id', 'left')
            ->join('securities cs', 'cs.id = ca.securities_id', 'left');
    }
}
