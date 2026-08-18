<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Chart of Accounts (§9).
 *
 * Struktur dibuat hierarkis (parent_id) agar penambahan akun baru maupun
 * pengelompokan sub-akun tidak memerlukan perubahan skema.
 */
class CreateAccountsTable extends Migration
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
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['asset', 'liability', 'equity', 'revenue', 'expense'],
            ],
            'normal_balance' => [
                'type'       => 'ENUM',
                'constraint' => ['debit', 'credit'],
                'comment'    => 'Disimpan eksplisit agar akun kontra (mis. 3200) dapat menyimpang dari saldo normal tipenya',
            ],
            'parent_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'is_postable' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'comment'    => 'Akun header hanya untuk pengelompokan, tidak menerima baris jurnal',
            ],
            'is_system' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'comment'    => 'Akun inti yang dirujuk App\\Enums\\AccountCode; tidak boleh dihapus atau diubah kodenya',
            ],
            'description' => ['type' => 'TEXT', 'null' => true],
            'is_active'   => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        // Laporan keuangan selalu menelusuri akun berdasarkan tipe lalu kode.
        $this->forge->addKey(['type', 'code']);
        $this->forge->addKey('parent_id');
        $this->forge->addKey(['is_active', 'is_postable']);

        $this->forge->createTable('accounts', true);

        // Self-reference ditambahkan setelah tabel ada.
        $this->db->query(
            'ALTER TABLE `accounts`
             ADD CONSTRAINT `accounts_parent_id_foreign`
             FOREIGN KEY (`parent_id`) REFERENCES `accounts`(`id`)
             ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('accounts', true);
    }
}
