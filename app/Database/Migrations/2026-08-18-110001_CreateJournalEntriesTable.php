<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Kepala jurnal (§8, §21.6).
 *
 * Total debit/kredit sengaja TIDAK disimpan di sini. Spesifikasi (§28) melarang
 * data redundant yang dapat membuat balance tidak sinkron; totalnya selalu
 * dihitung dari journal_lines, yang merupakan satu-satunya sumber kebenaran.
 */
class CreateJournalEntriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'entry_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'comment'    => 'Nomor jurnal, mis. JV-2026-01-0001',
            ],
            'entry_date'           => ['type' => 'DATE'],
            'accounting_period_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['normal', 'reversal', 'opening', 'adjustment'],
                'default'    => 'normal',
            ],
            'source_type' => [
                'type'       => 'ENUM',
                'constraint' => ['cash', 'stock', 'dividend', 'opening', 'manual'],
            ],
            'source_id' => [
                'type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true,
                'comment' => 'id baris pada tabel transaksi asal',
            ],
            'reverses_entry_id' => [
                'type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true,
                'comment' => 'Diisi bila jurnal ini membalik jurnal lain',
            ],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255],
            'status'      => [
                'type'       => 'ENUM',
                'constraint' => ['posted', 'reversed'],
                'default'    => 'posted',
            ],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('entry_number');
        // Buku besar & laporan selalu menelusuri jurnal berdasarkan tanggal.
        $this->forge->addKey(['entry_date', 'id']);
        $this->forge->addKey('accounting_period_id');
        // Menemukan jurnal dari transaksi asalnya.
        $this->forge->addKey(['source_type', 'source_id']);
        $this->forge->addKey('status');
        $this->forge->addKey('reverses_entry_id');

        $this->forge->addForeignKey('accounting_period_id', 'accounting_periods', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('journal_entries', true);

        $this->db->query(
            'ALTER TABLE `journal_entries`
             ADD CONSTRAINT `journal_entries_reverses_foreign`
             FOREIGN KEY (`reverses_entry_id`) REFERENCES `journal_entries`(`id`)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        $this->db->query(
            'ALTER TABLE `journal_entries`
             ADD CONSTRAINT `journal_entries_created_by_foreign`
             FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
             ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('journal_entries', true);
    }
}
