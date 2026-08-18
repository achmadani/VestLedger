<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Membangun ulang tabel posisi dari transaksi (§28).
 *
 * Tabel posisi adalah calculated state; buku besar tidak tersentuh sama sekali
 * oleh perintah ini.
 */
class RebuildPositions extends BaseCommand
{
    protected $group       = 'VestLedger';
    protected $name        = 'vestledger:rebuild-positions';
    protected $description = 'Membangun ulang stock_positions dari stock_transactions.';

    public function run(array $params): int
    {
        CLI::write('Membangun ulang posisi dari transaksi...', 'yellow');

        $result = service('positions')->rebuildAll();

        CLI::write(sprintf(
            '  %d posisi dibangun dari %d transaksi.',
            $result['positions'],
            $result['transactions']
        ), 'green');

        CLI::write('Buku besar tidak disentuh. Jalankan `php spark vestledger:health` untuk memverifikasi.', 'yellow');

        return EXIT_SUCCESS;
    }
}
