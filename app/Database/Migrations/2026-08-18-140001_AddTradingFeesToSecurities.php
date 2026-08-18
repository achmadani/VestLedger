<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Persentase biaya transaksi per sekuritas.
 *
 * Tiap broker memasang tarifnya sendiri, dan tarif itulah yang menentukan biaya
 * pada setiap pembelian dan penjualan. Menyimpannya di master sekuritas membuat
 * form transaksi dapat mengisi biayanya sendiri, sehingga tidak perlu dihitung
 * manual setiap kali.
 *
 * Nilai disimpan sebagai PERSEN (0.15 berarti 0,15%), bukan pecahan, agar sama
 * dengan cara tarif itu ditulis di lembar tarif broker.
 */
class AddTradingFeesToSecurities extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('securities', [
            'buy_fee_percent' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,5',
                'default'    => 0.15000,
                'null'       => false,
                'comment'    => 'Persen all-in sisi beli, sudah termasuk levy',
                'after'      => 'name',
            ],
            'sell_fee_percent' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,5',
                'default'    => 0.25000,
                'null'       => false,
                'comment'    => 'Persen all-in sisi jual, sudah termasuk PPh final dan levy',
                'after'      => 'buy_fee_percent',
            ],
        ]);

        $this->db->query(
            'ALTER TABLE `securities`
             ADD CONSTRAINT `securities_fee_percent_range`
             CHECK (`buy_fee_percent` >= 0 AND `buy_fee_percent` < 100
                AND `sell_fee_percent` >= 0 AND `sell_fee_percent` < 100)'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `securities` DROP CONSTRAINT `securities_fee_percent_range`');
        $this->forge->dropColumn('securities', ['buy_fee_percent', 'sell_fee_percent']);
    }
}
