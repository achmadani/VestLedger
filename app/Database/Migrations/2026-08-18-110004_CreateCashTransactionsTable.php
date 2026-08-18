<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Transaksi kas: top up, withdrawal, transfer antar sekuritas, biaya administrasi
 * (§6, §16, §17, §18).
 *
 * Transfer dicatat sebagai SATU baris dengan rekening asal dan rekening tujuan,
 * bukan dua baris terpisah. Dengan begitu tidak mungkin ada setengah transfer
 * yang tertinggal, dan jurnalnya cukup satu (§18).
 */
class CreateCashTransactionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'transaction_number' => ['type' => 'VARCHAR', 'constraint' => 30],
            'transaction_date'   => ['type' => 'DATE'],
            'type'               => [
                'type'       => 'ENUM',
                'constraint' => ['top_up', 'withdrawal', 'transfer', 'admin_fee'],
            ],
            'securities_account_id' => [
                'type' => 'INT', 'constraint' => 10, 'unsigned' => true,
                'comment' => 'Rekening utama; untuk transfer = rekening ASAL',
            ],
            'counterpart_account_id' => [
                'type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true,
                'comment' => 'Rekening TUJUAN, hanya untuk transfer',
            ],
            'amount'     => ['type' => 'DECIMAL', 'constraint' => '20,2'],
            'fee'        => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'tax'        => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'net_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2'],
            'notes'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'     => ['type' => 'ENUM', 'constraint' => ['posted', 'reversed'], 'default' => 'posted'],
            'journal_entry_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('transaction_number');
        $this->forge->addKey(['transaction_date', 'id']);
        $this->forge->addKey(['securities_account_id', 'transaction_date']);
        $this->forge->addKey(['type', 'transaction_date']);
        $this->forge->addKey('status');

        // Tanpa referential action: MySQL melarang kolom yang dipakai CHECK
        // constraint memiliki aksi ON UPDATE/ON DELETE selain RESTRICT. Primary key
        // di aplikasi ini tidak pernah berubah, jadi CASCADE memang tidak diperlukan.
        $this->forge->addForeignKey('securities_account_id', 'securities_accounts', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('counterpart_account_id', 'securities_accounts', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('journal_entry_id', 'journal_entries', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('cash_transactions', true);

        $this->db->query(
            'ALTER TABLE `cash_transactions`
             ADD CONSTRAINT `cash_transactions_amount_positive` CHECK (`amount` > 0),
             ADD CONSTRAINT `cash_transactions_charges_non_negative` CHECK (`fee` >= 0 AND `tax` >= 0),
             ADD CONSTRAINT `cash_transactions_transfer_has_counterpart`
             CHECK ((`type` <> \'transfer\' AND `counterpart_account_id` IS NULL)
                 OR (`type` =  \'transfer\' AND `counterpart_account_id` IS NOT NULL
                     AND `counterpart_account_id` <> `securities_account_id`))'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('cash_transactions', true);
    }
}
