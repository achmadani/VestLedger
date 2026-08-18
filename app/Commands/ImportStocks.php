<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Impor master saham dari berkas CSV.
 */
class ImportStocks extends BaseCommand
{
    protected $group       = 'VestLedger';
    protected $name        = 'vestledger:import-stocks';
    protected $description = 'Impor/perbarui master saham dari berkas CSV data IDX.';
    protected $usage       = 'vestledger:import-stocks [berkas.csv]';

    public function run(array $params): int
    {
        $path = $params[0] ?? APPPATH . 'Database/Seeds/data/idx-stocks.csv';

        CLI::write('Mengimpor dari: ' . $path, 'yellow');

        try {
            $result = service('stockImport')->importFile($path);
        } catch (BusinessRuleException $e) {
            CLI::error($e->getMessage());

            foreach ($e->reasons() as $reason) {
                CLI::write('  ' . $reason, 'red');
            }

            return EXIT_ERROR;
        }

        CLI::write(sprintf(
            '  %d saham baru, %d diperbarui, %d dilewati.',
            $result['created'],
            $result['updated'],
            $result['skipped']
        ), 'green');

        foreach (array_slice($result['problems'], 0, 20) as $problem) {
            CLI::write('  ! ' . $problem, 'yellow');
        }

        if (count($result['problems']) > 20) {
            CLI::write(sprintf('  ... dan %d masalah lain.', count($result['problems']) - 20), 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
