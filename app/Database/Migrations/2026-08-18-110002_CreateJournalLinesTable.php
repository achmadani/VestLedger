<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Baris jurnal — sumber kebenaran seluruh laporan keuangan (§28).
 *
 * Dimensi securities_account_id dan stock_id membuat Buku Besar dapat difilter
 * per sekuritas dan per ticker (§21.5) tanpa memecah Chart of Accounts setiap
 * kali ada sekuritas baru.
 */
class CreateJournalLinesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'journal_entry_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'line_no'          => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true],
            'account_id'       => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'debit'            => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'credit'           => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'securities_account_id' => [
                'type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true,
                'comment' => 'Dimensi: rekening sekuritas yang terpengaruh',
            ],
            'stock_id' => [
                'type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true,
                'comment' => 'Dimensi: saham yang terpengaruh',
            ],
            'memo' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['journal_entry_id', 'line_no']);
        // Buku besar per akun, diurutkan menurut tanggal jurnal.
        $this->forge->addKey(['account_id', 'journal_entry_id']);
        $this->forge->addKey('securities_account_id');
        $this->forge->addKey('stock_id');

        $this->forge->addForeignKey('journal_entry_id', 'journal_entries', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('securities_account_id', 'securities_accounts', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('stock_id', 'stocks', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('journal_lines', true);

        // Aturan double-entry ditegakkan sampai lapisan database: satu baris
        // hanya boleh mengisi debit ATAU kredit, tidak keduanya, tidak nol dua-duanya,
        // dan tidak pernah negatif. Nilai negatif akan membuat Trial Balance
        // seolah balance padahal arah pencatatannya salah.
        $this->db->query(
            'ALTER TABLE `journal_lines`
             ADD CONSTRAINT `journal_lines_single_side`
             CHECK (`debit` >= 0 AND `credit` >= 0
                    AND (`debit` = 0 OR `credit` = 0)
                    AND (`debit` > 0 OR `credit` > 0))'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('journal_lines', true);
    }
}
