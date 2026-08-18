<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class OpeningBalanceModel extends Model
{
    protected $table         = 'opening_balances';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'as_of_date', 'kind', 'securities_account_id', 'stock_id',
        'quantity', 'amount', 'notes', 'journal_entry_id', 'created_by',
    ];

    /**
     * Batch saldo awal yang sedang berlaku, bila ada.
     *
     * @return list<array<string, mixed>>
     */
    public function currentBatch(): array
    {
        $row = $this->orderBy('as_of_date', 'desc')->orderBy('id', 'desc')->first();

        if ($row === null) {
            return [];
        }

        return $this->where('as_of_date', $row['as_of_date'])->orderBy('kind', 'asc')->orderBy('id', 'asc')->findAll();
    }

    public function hasAny(): bool
    {
        return $this->countAllResults() > 0;
    }
}
