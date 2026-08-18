<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\JournalEntry;
use CodeIgniter\Model;

class JournalEntryModel extends Model
{
    protected $table         = 'journal_entries';
    protected $primaryKey    = 'id';
    protected $returnType    = JournalEntry::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'entry_number', 'entry_date', 'accounting_period_id', 'type',
        'source_type', 'source_id', 'reverses_entry_id', 'description',
        'status', 'created_by',
    ];

    /**
     * Jurnal beserta total debit/kredit-nya.
     *
     * Total dihitung lewat JOIN + GROUP BY, bukan disimpan sebagai kolom —
     * §28 melarang data redundant yang bisa membuat balance tidak sinkron.
     * Satu query untuk seluruh halaman, bukan satu query per baris (§34).
     */
    public function withTotals(): self
    {
        return $this
            ->select('journal_entries.*, COALESCE(SUM(journal_lines.debit), 0) AS total_debit, COALESCE(SUM(journal_lines.credit), 0) AS total_credit')
            ->join('journal_lines', 'journal_lines.journal_entry_id = journal_entries.id', 'left')
            ->groupBy('journal_entries.id');
    }

    public function findBySource(string $sourceType, int $sourceId): ?JournalEntry
    {
        return $this->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('type !=', 'reversal')
            ->first();
    }

    /**
     * Nomor urut berikutnya untuk sebuah bulan.
     */
    public function nextSequence(string $prefix): int
    {
        $row = $this->db->table('journal_entries')
            ->selectMax('entry_number', 'last_number')
            ->like('entry_number', $prefix, 'after')
            ->get()
            ->getRowArray();

        if (empty($row['last_number'])) {
            return 1;
        }

        return ((int) substr((string) $row['last_number'], -4)) + 1;
    }
}
