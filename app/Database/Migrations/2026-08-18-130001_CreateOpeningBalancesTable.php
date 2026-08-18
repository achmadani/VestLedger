<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Saldo awal (§19).
 *
 * Setiap baris adalah satu pos saldo awal — kas pada sebuah rekening, posisi
 * sebuah saham, atau modal disetor. Seluruh baris dengan tanggal yang sama
 * membentuk satu batch dan menghasilkan SATU jurnal saldo awal.
 *
 * Laba ditahan tidak disimpan sebagai baris masukan melainkan dihitung sebagai
 * angka penyeimbang (aset − modal disetor), sehingga saldo awal tidak mungkin
 * tidak balance.
 */
class CreateOpeningBalancesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'as_of_date' => ['type' => 'DATE', 'comment' => 'Tanggal posisi awal; seluruh transaksi harus setelah tanggal ini'],
            'kind'       => [
                'type'       => 'ENUM',
                'constraint' => ['cash', 'stock', 'paid_in_capital', 'retained_earnings'],
            ],
            'securities_account_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'stock_id'              => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'quantity'              => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
            'amount'                => ['type' => 'DECIMAL', 'constraint' => '20,2'],
            'notes'                 => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'journal_entry_id'      => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['as_of_date', 'kind']);
        $this->forge->addKey('journal_entry_id');

        $this->forge->addForeignKey('securities_account_id', 'securities_accounts', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('stock_id', 'stocks', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('journal_entry_id', 'journal_entries', 'id', 'RESTRICT', 'RESTRICT');

        $this->forge->createTable('opening_balances', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('opening_balances', true);
    }
}
