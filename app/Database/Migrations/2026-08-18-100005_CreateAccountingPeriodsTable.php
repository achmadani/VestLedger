<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Periode akuntansi bulanan (§25).
 *
 * Periode berstatus `closed` menolak posting baru. Koreksi atas periode
 * tertutup dilakukan lewat reversal di periode terbuka, bukan dengan mengubah
 * atau menghapus data lama (§26, §40.8).
 */
class CreateAccountingPeriodsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type'       => 'CHAR',
                'constraint' => 7,
                'comment'    => 'Format YYYY-MM, mis. 2026-01',
            ],
            'year' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
                'unsigned'   => true,
            ],
            'month' => [
                'type'       => 'TINYINT',
                'constraint' => 2,
                'unsigned'   => true,
            ],
            'start_date' => ['type' => 'DATE'],
            'end_date'   => ['type' => 'DATE'],
            'status'     => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'closed'],
                'default'    => 'open',
            ],
            'closed_at' => ['type' => 'DATETIME', 'null' => true],
            'closed_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'users.id yang menutup periode',
            ],
            'notes'      => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey(['year', 'month']);
        // Pencarian periode yang memuat sebuah tanggal transaksi.
        $this->forge->addKey(['start_date', 'end_date']);
        $this->forge->addKey('status');

        $this->forge->createTable('accounting_periods', true);

        // SET NULL: menghapus user tidak boleh menghilangkan periode akuntansi.
        $this->db->query(
            'ALTER TABLE `accounting_periods`
             ADD CONSTRAINT `accounting_periods_closed_by_foreign`
             FOREIGN KEY (`closed_by`) REFERENCES `users`(`id`)
             ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('accounting_periods', true);
    }
}
