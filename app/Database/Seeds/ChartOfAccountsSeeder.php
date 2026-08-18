<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Mengisi Chart of Accounts dengan akun-akun inti (§9).
 *
 * Idempoten: aman dijalankan berulang kali. Akun yang sudah ada tidak ditimpa,
 * hanya dipastikan bertanda `is_system`.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $result = service('chartOfAccounts')->ensureSystemAccounts();

        $this->displayResult($result);
    }

    /**
     * @param array{created:int, marked:int} $result
     */
    private function displayResult(array $result): void
    {
        if (! is_cli()) {
            return;
        }

        \CodeIgniter\CLI\CLI::write(sprintf(
            'Chart of Accounts: %d akun dibuat, %d akun lama ditandai sebagai akun inti.',
            $result['created'],
            $result['marked']
        ), 'green');

        $problems = service('chartOfAccounts')->verifySystemAccounts();

        if ($problems === []) {
            \CodeIgniter\CLI\CLI::write('Seluruh akun inti sehat.', 'green');

            return;
        }

        foreach ($problems as $problem) {
            \CodeIgniter\CLI\CLI::write('  ! ' . $problem, 'yellow');
        }
    }
}
