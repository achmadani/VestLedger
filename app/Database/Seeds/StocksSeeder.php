<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Models\StockModel;
use CodeIgniter\Database\Seeder;

/**
 * Saham contoh dari spesifikasi (§4.2). Idempoten berdasarkan ticker.
 */
class StocksSeeder extends Seeder
{
    public function run(): void
    {
        $stocks = [
            ['ticker' => 'BBCA', 'company_name' => 'Bank Central Asia Tbk',    'sector' => 'Keuangan'],
            ['ticker' => 'BBRI', 'company_name' => 'Bank Rakyat Indonesia Tbk', 'sector' => 'Keuangan'],
            ['ticker' => 'BMRI', 'company_name' => 'Bank Mandiri Tbk',          'sector' => 'Keuangan'],
            ['ticker' => 'TLKM', 'company_name' => 'Telkom Indonesia Tbk',      'sector' => 'Infrastruktur'],
        ];

        $model   = new StockModel();
        $created = 0;

        foreach ($stocks as $row) {
            if ($model->findByTicker($row['ticker']) !== null) {
                continue;
            }

            $model->insert($row + ['is_active' => 1]);
            $created++;
        }

        if (is_cli()) {
            \CodeIgniter\CLI\CLI::write(sprintf('Saham: %d dibuat.', $created), 'green');
        }
    }
}
