<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Harga pasar penutupan per saham (§14).
 *
 * Harga pasar TIDAK PERNAH menyentuh buku besar. Ia hanya dipakai menghitung
 * market value dan unrealized gain/loss untuk pelaporan, dan tidak mengubah
 * book cost historis sedikit pun (§13, §14).
 */
class CreateMarketPricesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'stock_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'price_date' => ['type' => 'DATE'],
            'closing_price' => ['type' => 'DECIMAL', 'constraint' => '20,4'],
            'notes'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        // Satu harga penutupan per saham per tanggal; input ulang menimpa, bukan menggandakan.
        $this->forge->addUniqueKey(['stock_id', 'price_date']);
        // Pencarian harga terbaru pada atau sebelum sebuah tanggal.
        $this->forge->addKey(['price_date', 'stock_id']);

        $this->forge->addForeignKey('stock_id', 'stocks', 'id', 'RESTRICT', 'RESTRICT');

        $this->forge->createTable('market_prices', true);

        $this->db->query(
            'ALTER TABLE `market_prices`
             ADD CONSTRAINT `market_prices_positive` CHECK (`closing_price` > 0)'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('market_prices', true);
    }
}
