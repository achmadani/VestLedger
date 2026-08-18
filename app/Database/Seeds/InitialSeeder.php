<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeder utama untuk instalasi baru.
 *
 * Menjalankan seluruh seeder master data dalam urutan yang benar:
 * Chart of Accounts lebih dulu, karena akun inti adalah prasyarat mesin jurnal.
 *
 *   php spark db:seed InitialSeeder
 */
class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ChartOfAccountsSeeder::class);
        $this->call(SecuritiesSeeder::class);
        $this->call(StocksSeeder::class);
        $this->call(AccountingPeriodSeeder::class);
    }
}
