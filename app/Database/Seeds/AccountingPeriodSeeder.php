<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Membuat periode akuntansi untuk tahun berjalan (§25).
 */
class AccountingPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $year    = (int) date('Y');
        $created = service('accountingPeriod')->generateYear($year);

        if (is_cli()) {
            \CodeIgniter\CLI\CLI::write(
                sprintf('Periode akuntansi %d: %d bulan dibuat.', $year, $created),
                'green'
            );
        }
    }
}
