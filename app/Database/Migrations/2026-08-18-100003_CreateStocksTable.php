<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Master saham (§4.2).
 */
class CreateStocksTable extends Migration
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
            'ticker' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'comment'    => 'Kode saham, selalu huruf besar, mis. BBCA',
            ],
            'company_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'sector' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
            ],
            'notes' => ['type' => 'TEXT', 'null' => true],
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
        $this->forge->addUniqueKey('ticker');
        $this->forge->addKey(['is_active', 'ticker']);
        $this->forge->addKey('sector');

        $this->forge->createTable('stocks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('stocks', true);
    }
}
