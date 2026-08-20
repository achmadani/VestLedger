<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\JournalLine;
use CodeIgniter\Model;

class JournalLineModel extends Model
{
    protected $table         = 'journal_lines';
    protected $primaryKey    = 'id';
    protected $returnType    = JournalLine::class;
    protected $useTimestamps = false;
    protected $allowedFields = [
        'journal_entry_id', 'line_no', 'account_id', 'debit', 'credit',
        'securities_account_id', 'stock_id', 'memo',
    ];

    /**
     * @return list<JournalLine>
     */
    public function forEntry(int $entryId): array
    {
        return $this->where('journal_entry_id', $entryId)->orderBy('line_no', 'asc')->findAll();
    }

    /**
     * Baris jurnal lengkap dengan identitas akun, untuk Buku Besar (§21.5).
     *
     * @param array{account_id?:int, securities_account_id?:int, stock_id?:int, from?:string, to?:string} $filters
     */
    public function ledgerQuery(array $filters = []): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $this->db->table('journal_lines jl')
            ->select('jl.*, je.entry_number, je.entry_date, je.description, je.status AS entry_status,
                      a.code AS account_code, a.name AS account_name, a.type AS account_type,
                      a.normal_balance, s.code AS securities_code, st.ticker')
            ->join('journal_entries je', 'je.id = jl.journal_entry_id')
            ->join('accounts a', 'a.id = jl.account_id')
            ->join('securities_accounts sa', 'sa.id = jl.securities_account_id', 'left')
            ->join('securities s', 's.id = sa.securities_id', 'left')
            ->join('stocks st', 'st.id = jl.stock_id', 'left');

        if (! empty($filters['account_id'])) {
            $builder->where('jl.account_id', $filters['account_id']);
        }

        if (! empty($filters['securities_account_id'])) {
            $builder->where('jl.securities_account_id', $filters['securities_account_id']);
        }

        if (! empty($filters['stock_id'])) {
            $builder->where('jl.stock_id', $filters['stock_id']);
        }

        if (! empty($filters['from'])) {
            $builder->where('je.entry_date >=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $builder->where('je.entry_date <=', $filters['to']);
        }

        return $builder->orderBy('je.entry_date', 'asc')
            ->orderBy('je.id', 'asc')
            ->orderBy('jl.line_no', 'asc');
    }

    /**
     * Saldo per akun untuk Trial Balance / Neraca (§21.4).
     *
     * Satu query agregat untuk seluruh akun sekaligus.
     *
     * $securitiesAccountId membatasi pada satu rekening sekuritas, memakai
     * dimensi yang melekat di setiap baris jurnal (§22). Berguna untuk Laba Rugi
     * per sekuritas; jangan dipakai untuk Neraca, karena baris ekuitas saldo awal
     * memang tidak bermuatan dimensi sekuritas dan akan hilang dari hasil.
     *
     * @return list<array{account_id:int, code:string, name:string, type:string, normal_balance:string, total_debit:string, total_credit:string}>
     */
    public function balancesByAccount(?string $from = null, ?string $to = null, ?int $securitiesAccountId = null): array
    {
        $builder = $this->db->table('journal_lines jl')
            ->select('jl.account_id, a.code, a.name, a.type, a.normal_balance,
                      SUM(jl.debit) AS total_debit, SUM(jl.credit) AS total_credit')
            ->join('journal_entries je', 'je.id = jl.journal_entry_id')
            ->join('accounts a', 'a.id = jl.account_id')
            ->groupBy('jl.account_id, a.code, a.name, a.type, a.normal_balance')
            ->orderBy('a.code', 'asc');

        if ($from !== null) {
            $builder->where('je.entry_date >=', $from);
        }

        if ($to !== null) {
            $builder->where('je.entry_date <=', $to);
        }

        if ($securitiesAccountId !== null && $securitiesAccountId > 0) {
            $builder->where('jl.securities_account_id', $securitiesAccountId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Saldo akun nominal (pendapatan & beban) dipecah per rekening sekuritas.
     *
     * Dipakai Laba Rugi per Sekuritas (§21.6). Baris yang dimensi sekuritasnya
     * kosong TETAP dikembalikan dengan securities_account_id = null, bukan
     * dibuang — kalau ada, rinciannya harus tetap berjumlah sama dengan Laba
     * Rugi global, dan angka yang tak dapat diatribusikan wajib terlihat.
     *
     * @return list<array{securities_account_id:?int, code:string, name:string, type:string, total_debit:string, total_credit:string}>
     */
    public function nominalBalancesBySecurities(?string $from = null, ?string $to = null): array
    {
        $builder = $this->db->table('journal_lines jl')
            ->select('jl.securities_account_id, a.code, a.name, a.type,
                      SUM(jl.debit) AS total_debit, SUM(jl.credit) AS total_credit')
            ->join('journal_entries je', 'je.id = jl.journal_entry_id')
            ->join('accounts a', 'a.id = jl.account_id')
            ->whereIn('a.type', ['revenue', 'expense'])
            ->groupBy('jl.securities_account_id, a.code, a.name, a.type')
            ->orderBy('a.code', 'asc');

        if ($from !== null) {
            $builder->where('je.entry_date >=', $from);
        }

        if ($to !== null) {
            $builder->where('je.entry_date <=', $to);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Saldo kas per rekening sekuritas (§22 Cash Balance per Securities).
     *
     * @return array<int, string> [securities_account_id => saldo desimal]
     */
    public function cashBalanceByAccount(int $cashAccountId, ?string $to = null): array
    {
        $builder = $this->db->table('journal_lines jl')
            ->select('jl.securities_account_id, SUM(jl.debit) - SUM(jl.credit) AS balance')
            ->join('journal_entries je', 'je.id = jl.journal_entry_id')
            ->where('jl.account_id', $cashAccountId)
            ->where('jl.securities_account_id IS NOT NULL')
            ->groupBy('jl.securities_account_id');

        if ($to !== null) {
            $builder->where('je.entry_date <=', $to);
        }

        $balances = [];

        foreach ($builder->get()->getResultArray() as $row) {
            $balances[(int) $row['securities_account_id']] = (string) $row['balance'];
        }

        return $balances;
    }
}
