<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Penerimaan dividen (§15).
 */
class CreateDividendTransactionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'transaction_number' => ['type' => 'VARCHAR', 'constraint' => 30],
            'transaction_date'   => ['type' => 'DATE', 'comment' => 'Tanggal pembayaran dividen'],
            'securities_account_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'stock_id'              => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'quantity_eligible'  => ['type' => 'BIGINT', 'constraint' => 20, 'comment' => 'Jumlah lembar yang berhak'],
            'dividend_per_share' => ['type' => 'DECIMAL', 'constraint' => '20,4'],
            'gross_dividend'     => ['type' => 'DECIMAL', 'constraint' => '20,2'],
            'tax'                => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'net_dividend'       => ['type' => 'DECIMAL', 'constraint' => '20,2'],
            'notes'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'             => ['type' => 'ENUM', 'constraint' => ['posted', 'reversed'], 'default' => 'posted'],
            'journal_entry_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('transaction_number');
        $this->forge->addKey(['transaction_date', 'id']);
        $this->forge->addKey(['securities_account_id', 'transaction_date']);
        $this->forge->addKey(['stock_id', 'transaction_date']);
        $this->forge->addKey('status');

        $this->forge->addForeignKey('securities_account_id', 'securities_accounts', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('stock_id', 'stocks', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('journal_entry_id', 'journal_entries', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('dividend_transactions', true);

        $this->db->query(
            'ALTER TABLE `dividend_transactions`
             ADD CONSTRAINT `dividend_gross_positive` CHECK (`gross_dividend` > 0),
             ADD CONSTRAINT `dividend_tax_within_gross` CHECK (`tax` >= 0 AND `tax` <= `gross_dividend`),
             ADD CONSTRAINT `dividend_quantity_positive` CHECK (`quantity_eligible` > 0)'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('dividend_transactions', true);
    }
}
