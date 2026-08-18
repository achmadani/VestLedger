<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Profil emiten dari data IDX (§4.2).
 *
 * Klasifikasi IDX bertingkat empat — sektor, subsektor, industri, subindustri —
 * dan keempatnya disimpan karena laporan portofolio dapat dikelompokkan pada
 * tingkat mana pun.
 *
 * `market_cap` adalah POTRET pada tanggal impor, bukan nilai berjalan: kapitalisasi
 * pasar berubah setiap hari mengikuti harga. Ia disimpan apa adanya sebagai
 * keterangan profil, dan tidak pernah dipakai menghitung nilai portofolio —
 * itu selalu dihitung dari harga pasar di tabel market_prices.
 */
class AddIdxProfileToStocks extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('stocks', [
            'sub_sector'        => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'sector'],
            'industry'          => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'sub_sector'],
            'sub_industry'      => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'industry'],
            'sub_industry_code' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'after' => 'sub_industry'],
            'index_membership'  => [
                'type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'sub_industry_code',
                'comment' => 'Daftar indeks yang memuat saham ini, dipisah koma',
            ],
            'listing_date'  => ['type' => 'DATE', 'null' => true, 'after' => 'index_membership'],
            'listing_board' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'listing_date'],
            'shares_outstanding' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true, 'after' => 'listing_board'],
            'market_cap' => [
                'type' => 'DECIMAL', 'constraint' => '24,2', 'null' => true, 'after' => 'shares_outstanding',
                'comment' => 'Potret saat impor; bukan nilai berjalan',
            ],
            'profile_updated_at' => ['type' => 'DATE', 'null' => true, 'after' => 'market_cap'],
        ]);

        $this->forge->addKey('sector', false, false, 'stocks_sector');
        $this->db->query('CREATE INDEX `stocks_listing_board` ON `stocks` (`listing_board`)');
        $this->db->query('CREATE INDEX `stocks_sub_sector` ON `stocks` (`sub_sector`)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX `stocks_listing_board` ON `stocks`');
        $this->db->query('DROP INDEX `stocks_sub_sector` ON `stocks`');

        $this->forge->dropColumn('stocks', [
            'sub_sector', 'industry', 'sub_industry', 'sub_industry_code',
            'index_membership', 'listing_date', 'listing_board',
            'shares_outstanding', 'market_cap', 'profile_updated_at',
        ]);
    }
}
