<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

/**
 * Mengosongkan seluruh tabel domain sebelum sebuah test.
 *
 * Foreign key check dimatikan sementara: tabel-tabel ini saling merujuk —
 * termasuk journal_entries yang merujuk dirinya sendiri lewat reverses_entry_id —
 * sehingga tidak ada satu urutan penghapusan yang selalu aman. Menambah tabel
 * baru di kemudian hari juga tidak akan diam-diam merusak test lama.
 *
 * Hanya dipakai pada database test.
 */
trait TruncatesDomainTables
{
    /**
     * Urutan tidak penting karena FK check dimatikan, tetapi tetap ditulis dari
     * anak ke induk agar maksudnya terbaca.
     *
     * @var list<string>
     */
    protected array $domainTables = [
        'journal_lines',
        'cash_transactions',
        'stock_transactions',
        'dividend_transactions',
        'journal_entries',
        'stock_positions',
        'market_prices',
        'audit_logs',
        'securities_accounts',
        'securities',
        'stocks',
        'accounts',
        'accounting_periods',
    ];

    protected function truncateDomainTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->domainTables as $table) {
            $this->db->table($table)->truncate();
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
