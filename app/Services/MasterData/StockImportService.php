<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Models\StockModel;
use App\Services\Accounting\AuditLogger;

/**
 * Impor master saham dari berkas CSV data IDX (§4.2).
 *
 * Formatnya CSV, bukan XLSX, dan itu disengaja: membaca XLSX memerlukan
 * PhpSpreadsheet — sekitar 5 MB dependency beserta ekstensi zip dan xml — hanya
 * untuk pekerjaan yang dilakukan beberapa kali setahun. Excel dan Google Sheets
 * sama-sama dapat menyimpan sebagai CSV dalam satu langkah (§34).
 *
 * Impor bersifat UPSERT berdasarkan ticker: saham yang sudah ada diperbarui
 * profilnya, yang belum ada ditambahkan. Tidak ada yang dihapus — saham yang
 * ter-delisting tetap dibutuhkan riwayat transaksinya.
 */
class StockImportService
{
    /** Kolom yang wajib ada di header CSV. */
    private const REQUIRED = ['ticker', 'company_name'];

    /** Kolom profil yang ikut diperbarui bila tersedia. */
    private const PROFILE = [
        'sector', 'sub_sector', 'industry', 'sub_industry', 'sub_industry_code',
        'index_membership', 'listing_date', 'listing_board',
        'shares_outstanding', 'market_cap',
    ];

    public function __construct(
        private StockModel $stocks,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return array{created:int, updated:int, skipped:int, problems:list<string>}
     */
    public function importFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new BusinessRuleException('Berkas CSV tidak ditemukan atau tidak dapat dibaca: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new BusinessRuleException('Berkas CSV gagal dibuka.');
        }

        try {
            return $this->importHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array{created:int, updated:int, skipped:int, problems:list<string>}
     */
    public function importHandle($handle): array
    {
        $header = fgetcsv($handle);

        if ($header === false || $header === null) {
            throw new BusinessRuleException('Berkas CSV kosong.');
        }

        // Buang BOM UTF-8 yang sering disisipkan Excel di sel pertama.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header    = array_map(static fn ($h): string => strtolower(trim((string) $h)), $header);

        foreach (self::REQUIRED as $column) {
            if (! in_array($column, $header, true)) {
                throw new BusinessRuleException(sprintf(
                    'Kolom "%s" tidak ada di berkas. Header yang ditemukan: %s',
                    $column,
                    implode(', ', $header)
                ));
            }
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $problems = [];
        $line     = 1;

        $db = db_connect();
        $db->transBegin();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;

                if ($row === [null] || $row === []) {
                    continue;
                }

                $data   = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
                $ticker = strtoupper(trim((string) ($data['ticker'] ?? '')));

                if ($ticker === '') {
                    $skipped++;

                    continue;
                }

                $payload = $this->buildPayload($data);

                if ($payload['company_name'] === '') {
                    $problems[] = sprintf('Baris %d (%s): nama perusahaan kosong, dilewati.', $line, $ticker);
                    $skipped++;

                    continue;
                }

                $existing = $this->stocks->findByTicker($ticker);

                if ($existing === null) {
                    $ok = $this->stocks->insert($payload + ['ticker' => $ticker, 'is_active' => 1]);
                    $ok !== false ? $created++ : $problems[] = sprintf('Baris %d (%s): %s', $line, $ticker, implode(' ', $this->stocks->errors()));

                    continue;
                }

                // Status aktif TIDAK ikut ditimpa: saham yang sengaja
                // dinonaktifkan pengguna harus tetap nonaktif setelah impor.
                $payload['id'] = $existing->id;
                $ok = $this->stocks->update($existing->id, $payload);
                $ok !== false ? $updated++ : $problems[] = sprintf('Baris %d (%s): %s', $line, $ticker, implode(' ', $this->stocks->errors()));
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $this->audit->record(
            'imported',
            'stock',
            null,
            sprintf('Impor master saham: %d baru, %d diperbarui, %d dilewati', $created, $updated, $skipped),
        );

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'problems' => $problems];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $data): array
    {
        $payload = ['company_name' => trim((string) ($data['company_name'] ?? ''))];

        foreach (self::PROFILE as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            $value = trim((string) ($data[$column] ?? ''));

            $payload[$column] = match (true) {
                $value === ''                                          => null,
                in_array($column, ['shares_outstanding', 'market_cap'], true) => $this->numeric($value),
                $column === 'listing_date'                             => $this->date($value),
                default                                                => $value,
            };
        }

        $payload['profile_updated_at'] = date('Y-m-d');

        return $payload;
    }

    private function numeric(string $value): ?string
    {
        $clean = str_replace([',', ' '], '', $value);

        return is_numeric($clean) ? $clean : null;
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd M Y', 'd/m/Y', 'd-m-Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);

            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }
}
