<?php

declare(strict_types=1);

namespace App\Commands;

use App\Enums\AccountCode;
use App\Models\AccountModel;
use App\Models\StockPositionModel;
use App\ValueObjects\Money;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Session;

/**
 * Pemeriksaan kesehatan aplikasi.
 *
 * Dijalankan setelah deployment dan kapan pun ada keraguan atas integritas data.
 * Memeriksa hal-hal yang, bila salah, membuat seluruh laporan keuangan tidak
 * dapat dipercaya — dan yang tidak akan terlihat dari tampilan biasa.
 */
class HealthCheck extends BaseCommand
{
    protected $group       = 'VestLedger';
    protected $name        = 'vestledger:health';
    protected $description = 'Memeriksa integritas akuntansi dan konfigurasi keamanan.';

    private int $failures = 0;

    public function run(array $params): int
    {
        CLI::write('Pemeriksaan Kesehatan VestLedger', 'yellow');
        CLI::write(str_repeat('-', 56));

        $this->checkSystemAccounts();
        $this->checkLedgerBalanced();
        $this->checkEveryJournalBalanced();
        $this->checkPortfolioMatchesLedger();
        $this->checkSessionPath();
        $this->checkEnvironment();

        CLI::write(str_repeat('-', 56));

        if ($this->failures === 0) {
            CLI::write('Semua pemeriksaan lolos.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::write(sprintf('%d pemeriksaan GAGAL.', $this->failures), 'red');

        return EXIT_ERROR;
    }

    private function checkSystemAccounts(): void
    {
        $problems = service('chartOfAccounts')->verifySystemAccounts();

        if ($problems === []) {
            $this->pass('Akun inti lengkap dan sehat');

            return;
        }

        $this->fail('Akun inti bermasalah');

        foreach ($problems as $problem) {
            CLI::write('      ' . $problem, 'red');
        }
    }

    private function checkLedgerBalanced(): void
    {
        $row = db_connect()->query('SELECT SUM(debit) AS d, SUM(credit) AS c FROM journal_lines')->getRowArray();

        $debit  = Money::of((string) ($row['d'] ?? '0'));
        $credit = Money::of((string) ($row['c'] ?? '0'));

        if ($debit->equals($credit)) {
            $this->pass(sprintf('Buku besar balance (%s)', $debit->toDecimalString()));

            return;
        }

        $this->fail(sprintf(
            'Buku besar TIDAK balance: debit %s vs kredit %s',
            $debit->toDecimalString(),
            $credit->toDecimalString()
        ));
    }

    /**
     * Total global bisa saja balance meskipun ada dua jurnal yang sama-sama
     * salah dan saling menutupi, jadi setiap jurnal diperiksa satu per satu.
     */
    private function checkEveryJournalBalanced(): void
    {
        $rows = db_connect()->query(
            'SELECT je.entry_number, SUM(jl.debit) AS d, SUM(jl.credit) AS c
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             GROUP BY je.id, je.entry_number
             HAVING ABS(SUM(jl.debit) - SUM(jl.credit)) > 0.001'
        )->getResultArray();

        if ($rows === []) {
            $this->pass('Setiap jurnal balance satu per satu');

            return;
        }

        $this->fail(sprintf('%d jurnal tidak balance', count($rows)));

        foreach (array_slice($rows, 0, 10) as $row) {
            CLI::write(sprintf('      %s: debit %s vs kredit %s', $row['entry_number'], $row['d'], $row['c']), 'red');
        }
    }

    /**
     * Saldo akun 1100 harus sama dengan jumlah book value seluruh posisi.
     * Bila berbeda, salah satu dari neraca atau portofolio pasti berbohong.
     */
    private function checkPortfolioMatchesLedger(): void
    {
        $accountId = (new AccountModel())->idFor(AccountCode::StockPortfolio);

        $row = db_connect()->query(
            'SELECT SUM(debit) - SUM(credit) AS balance FROM journal_lines WHERE account_id = ?',
            [$accountId]
        )->getRowArray();

        $ledger    = Money::of((string) ($row['balance'] ?? '0'));
        $positions = Money::of((new StockPositionModel())->totalBookValue());

        if ($ledger->equals($positions)) {
            $this->pass(sprintf('Akun 1100 sama dengan total posisi (%s)', $ledger->toDecimalString()));

            return;
        }

        $this->fail(sprintf(
            'Akun 1100 (%s) berbeda dari total posisi (%s). Jalankan `php spark vestledger:rebuild-positions`.',
            $ledger->toDecimalString(),
            $positions->toDecimalString()
        ));
    }

    /**
     * File session di dalam web root dapat diunduh siapa pun yang menebak
     * namanya — termasuk sesi yang sudah terautentikasi.
     */
    private function checkSessionPath(): void
    {
        $savePath = (new Session())->savePath;
        $resolved = realpath($savePath) ?: $savePath;
        $webRoot  = rtrim(realpath(FCPATH) ?: FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with(rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $webRoot)) {
            $this->fail('File session berada DI DALAM web root: ' . $resolved);

            return;
        }

        $this->pass('File session berada di luar web root');
    }

    private function checkEnvironment(): void
    {
        if (ENVIRONMENT === 'production') {
            $this->pass('CI_ENVIRONMENT = production');

            return;
        }

        CLI::write(sprintf('  [!]  CI_ENVIRONMENT = %s (bukan production)', ENVIRONMENT), 'yellow');
    }

    private function pass(string $message): void
    {
        CLI::write('  [OK] ' . $message, 'green');
    }

    private function fail(string $message): void
    {
        $this->failures++;
        CLI::write('  [!!] ' . $message, 'red');
    }
}
