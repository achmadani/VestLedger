<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Master perusahaan sekuritas / broker (§4.1).
 *
 * Tabel ini mencatat BROKER-nya (Ajaib, IPOT, Mirae, ...). Rekening/RDN milik
 * investor di broker tersebut disimpan terpisah di `securities_accounts`,
 * sehingga satu broker tetap dapat memiliki lebih dari satu rekening bila
 * suatu saat dibutuhkan.
 */
class CreateSecuritiesTable extends Migration
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
                'comment'    => 'Kode singkat, mis. AJAIB, IPOT',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
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
        // Kode sekuritas dipakai manusia sebagai identitas; tidak boleh kembar.
        $this->forge->addUniqueKey('code');
        $this->forge->addKey(['is_active', 'name']);

        $this->forge->createTable('securities', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('securities', true);
    }
}
