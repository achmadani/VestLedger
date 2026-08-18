<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Posisi saham per rekening sekuritas (§5, §12).
 *
 * Tabel ini adalah CALCULATED STATE: seluruh isinya dapat dibangun ulang dari
 * stock_transactions (§28). Ia ada demi kecepatan, bukan sebagai sumber kebenaran.
 *
 * Yang disimpan hanya quantity dan book_value. Average cost TIDAK disimpan —
 * ia selalu diturunkan book_value / quantity, agar pembulatan tidak pernah
 * menumpuk dan membuat neraca tidak balance (lihat docs/ACCOUNTING.md).
 */
class CreateStockPositionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'securities_account_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'stock_id'              => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'quantity'              => [
                'type' => 'BIGINT', 'constraint' => 20, 'default' => 0,
                'comment' => 'Jumlah LEMBAR — unit utama perhitungan (§7)',
            ],
            'book_value' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'last_transaction_date' => ['type' => 'DATE', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        // Satu posisi per kombinasi rekening + saham.
        $this->forge->addUniqueKey(['securities_account_id', 'stock_id']);
        // Portofolio per ticker menjumlahkan lintas sekuritas (§5).
        $this->forge->addKey(['stock_id', 'securities_account_id']);

        $this->forge->addForeignKey('securities_account_id', 'securities_accounts', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('stock_id', 'stocks', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('stock_positions', true);

        // Posisi tidak boleh negatif: menjual lebih banyak daripada yang dimiliki
        // adalah kesalahan data, bukan short selling — aplikasi ini tidak
        // mendukungnya dan spesifikasi mensyaratkan sell qty <= current qty (§27).
        $this->db->query(
            'ALTER TABLE `stock_positions`
             ADD CONSTRAINT `stock_positions_non_negative`
             CHECK (`quantity` >= 0 AND `book_value` >= 0)'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('stock_positions', true);
    }
}
