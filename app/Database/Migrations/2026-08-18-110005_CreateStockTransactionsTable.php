<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Transaksi beli & jual saham (§6, §10, §11).
 *
 * Kolom *_before dan *_after merekam posisi tepat sebelum dan sesudah transaksi.
 * Ini bukan data redundant yang berbahaya, melainkan jejak audit: ia
 * memungkinkan realized gain/loss lama diperiksa ulang, dan menjadi pembanding
 * ketika stock_positions dibangun ulang dari nol (§28).
 */
class CreateStockTransactionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'transaction_number' => ['type' => 'VARCHAR', 'constraint' => 30],
            'transaction_date'   => ['type' => 'DATE'],
            'type'               => ['type' => 'ENUM', 'constraint' => ['buy', 'sell']],
            'securities_account_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'stock_id'              => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],

            'quantity' => ['type' => 'BIGINT', 'constraint' => 20, 'comment' => 'Jumlah LEMBAR'],
            'lots'     => ['type' => 'DECIMAL', 'constraint' => '16,4', 'comment' => 'Turunan dari quantity, disimpan untuk tampilan'],
            'price'    => ['type' => 'DECIMAL', 'constraint' => '20,4'],

            'gross_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2'],
            'broker_fee'   => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'tax'          => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'levy'         => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'net_amount'   => [
                'type' => 'DECIMAL', 'constraint' => '20,2',
                'comment' => 'Beli: kas keluar (gross + biaya). Jual: kas masuk (gross - biaya)',
            ],

            'book_value_sold' => [
                'type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true,
                'comment' => 'Hanya untuk jual: bagian book value yang dilepas',
            ],
            'realized_gain_gross' => [
                'type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true,
                'comment' => 'gross_amount - book_value_sold; inilah yang masuk akun 4000/4001',
            ],
            'realized_gain_net' => [
                'type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true,
                'comment' => 'gross - book_value_sold - fee - tax - levy; metrik laporan §11 Step 3',
            ],

            'quantity_before'   => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
            'book_value_before' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'quantity_after'    => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
            'book_value_after'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],

            'notes'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['posted', 'reversed'], 'default' => 'posted'],
            'journal_entry_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('transaction_number');
        $this->forge->addKey(['transaction_date', 'id']);
        // Membangun ulang posisi menelusuri transaksi satu posisi secara berurutan.
        $this->forge->addKey(['securities_account_id', 'stock_id', 'transaction_date', 'id']);
        $this->forge->addKey(['stock_id', 'transaction_date']);
        $this->forge->addKey(['type', 'transaction_date']);
        $this->forge->addKey('status');

        $this->forge->addForeignKey('securities_account_id', 'securities_accounts', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('stock_id', 'stocks', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('journal_entry_id', 'journal_entries', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('stock_transactions', true);

        $this->db->query(
            'ALTER TABLE `stock_transactions`
             ADD CONSTRAINT `stock_transactions_quantity_positive` CHECK (`quantity` > 0),
             ADD CONSTRAINT `stock_transactions_price_positive` CHECK (`price` > 0),
             ADD CONSTRAINT `stock_transactions_charges_non_negative`
             CHECK (`broker_fee` >= 0 AND `tax` >= 0 AND `levy` >= 0)'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('stock_transactions', true);
    }
}
