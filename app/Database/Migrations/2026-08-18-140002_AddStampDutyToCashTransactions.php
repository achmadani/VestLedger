<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Menambahkan jenis transaksi kas "stamp_duty" (bea materai).
 *
 * Bea materai melekat pada Trade Confirmation harian yang diterbitkan tiap
 * broker, bukan pada transaksi satu per satu. Karena itu ia dicatat sebagai
 * transaksi kas tersendiri per sekuritas per tanggal, dan dibuat sistem —
 * bukan diinput pengguna.
 */
class AddStampDutyToCashTransactions extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `cash_transactions`
             MODIFY COLUMN `type` ENUM('top_up','withdrawal','transfer','admin_fee','stamp_duty') NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE `cash_transactions`
             MODIFY COLUMN `type` ENUM('top_up','withdrawal','transfer','admin_fee') NOT NULL"
        );
    }
}
