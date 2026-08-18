<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Models\SecuritiesAccountModel;
use App\Models\SecurityModel;
use CodeIgniter\Database\Seeder;

/**
 * Lima sekuritas yang dipakai pemilik portofolio (§4.1).
 *
 * Idempoten berdasarkan kode sekuritas. Nomor RDN sengaja dikosongkan —
 * data rekening sungguhan diisi sendiri oleh pengguna lewat UI, bukan lewat
 * seeder yang tersimpan di repository (§36).
 */
class SecuritiesSeeder extends Seeder
{
    public function run(): void
    {
        $securities = [
            ['code' => 'AJAIB',    'name' => 'Ajaib Sekuritas Asia'],
            ['code' => 'STOCKBIT', 'name' => 'Stockbit Sekuritas Digital'],
            ['code' => 'IPOT',     'name' => 'Indo Premier Sekuritas'],
            ['code' => 'MIRAE',    'name' => 'Mirae Asset Sekuritas Indonesia'],
            ['code' => 'BCAS',     'name' => 'BCA Sekuritas'],
        ];

        $securityModel = new SecurityModel();
        $accountModel  = new SecuritiesAccountModel();
        $created       = 0;

        foreach ($securities as $row) {
            if ($securityModel->findByCode($row['code']) !== null) {
                continue;
            }

            $id = $securityModel->insert($row + ['is_active' => 1], true);

            $accountModel->insert([
                'securities_id' => $id,
                'label'         => 'RDN Utama',
                'is_active'     => 1,
            ]);

            $created++;
        }

        if (is_cli()) {
            \CodeIgniter\CLI\CLI::write(sprintf('Sekuritas: %d dibuat.', $created), 'green');
        }
    }
}
