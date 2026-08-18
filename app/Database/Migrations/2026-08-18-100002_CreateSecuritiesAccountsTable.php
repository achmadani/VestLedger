<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rekening efek / RDN milik investor pada sebuah sekuritas (§4.1, §5).
 *
 * INI adalah entitas yang dirujuk oleh setiap transaksi kas maupun transaksi
 * saham, dan menjadi dimensi `securities_account_id` pada baris jurnal.
 * Portofolio "per sekuritas" dikelompokkan lewat relasi ke `securities`.
 */
class CreateSecuritiesAccountsTable extends Migration
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
            'securities_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'label' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'comment'    => 'Nama tampilan rekening, mis. "RDN Utama"',
            ],
            'account_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'null'       => true,
                'comment'    => 'Nomor rekening efek / RDN',
            ],
            'bank_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'comment'    => 'Bank tempat RDN dibuka',
            ],
            'opened_at' => ['type' => 'DATE', 'null' => true],
            'notes'     => ['type' => 'TEXT', 'null' => true],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['securities_id', 'is_active']);
        // Satu nomor rekening hanya boleh muncul sekali dalam satu sekuritas.
        $this->forge->addUniqueKey(['securities_id', 'account_number']);

        // RESTRICT: sekuritas yang masih punya rekening tidak boleh terhapus,
        // karena rekening itulah yang dirujuk seluruh histori transaksi.
        $this->forge->addForeignKey('securities_id', 'securities', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('securities_accounts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('securities_accounts', true);
    }
}
