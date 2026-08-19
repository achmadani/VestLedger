<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Exceptions\BusinessRuleException;
use App\Libraries\XlsxReader;
use App\Models\MarketPriceModel;
use App\Models\StockModel;
use App\Models\StockPositionModel;
use App\Services\Accounting\AuditLogger;
use App\ValueObjects\Price;
use RuntimeException;

/**
 * Impor harga penutupan dari berkas XLSX ringkasan perdagangan IDX (§14).
 *
 * Formatnya tetap: baris 1 header, data mulai baris 2, kolom B kode saham,
 * kolom K harga penutupan. Header tetap diperiksa sebelum satu baris pun
 * dibaca — bila pengguna keliru mengunggah berkas IDX yang lain (daftar emiten,
 * misalnya), kolom K berisi hal lain sama sekali dan seluruh portofolio akan
 * dinilai dengan angka yang salah tanpa satu pun pesan error. Kesalahan seperti
 * itu harus ditolak, bukan diimpor.
 *
 * Harga pasar tidak pernah masuk buku besar dan tidak mengubah book cost, jadi
 * tidak ada jurnal maupun pemeriksaan periode di sini (§13, §14).
 */
class MarketPriceImportService
{
    /** Kolom kode saham. */
    private const COLUMN_TICKER = 'B';

    /** Kolom harga penutupan. */
    private const COLUMN_CLOSING = 'K';

    /** Kolom tanggal perdagangan terakhir, dipakai memeriksa kecocokan tanggal. */
    private const COLUMN_TRADE_DATE = 'G';

    /** Data dimulai setelah baris header. */
    private const FIRST_DATA_ROW = 2;

    /** Sebagian nama bulan Indonesia yang dipakai IDX pada kolom tanggal. */
    private const MONTHS = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'mei' => '05', 'may' => '05', 'jun' => '06', 'jul' => '07',
        'agt' => '08', 'agu' => '08', 'aug' => '08', 'sep' => '09',
        'okt' => '10', 'oct' => '10', 'nov' => '11', 'des' => '12', 'dec' => '12',
    ];

    public function __construct(
        private MarketPriceModel $prices,
        private StockModel $stocks,
        private StockPositionModel $positions,
        private AuditLogger $audit,
        private XlsxReader $reader,
    ) {
    }

    /**
     * @param bool $heldOnly hanya saham yang sedang dimiliki
     *
     * @return array{saved:int, updated:int, skipped:int, unknown:int, date:string,
     *               fileDate:?string, problems:list<string>}
     */
    public function importFile(string $path, string $date, bool $heldOnly = true): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new BusinessRuleException('Tanggal harga wajib diisi dengan format YYYY-MM-DD.');
        }

        if ($date > date('Y-m-d')) {
            throw new BusinessRuleException('Tanggal harga tidak boleh di masa depan.');
        }

        $wanted = $this->targetStockIds($heldOnly);

        if ($wanted === []) {
            throw new BusinessRuleException(
                'Belum ada posisi saham yang dimiliki, sehingga tidak ada harga yang perlu diimpor. '
                . 'Pilih "seluruh saham" bila ingin menyimpan harga semua emiten.'
            );
        }

        $byTicker = $this->tickerMap($wanted);

        $parsed   = [];
        $unknown  = 0;
        $skipped  = 0;
        $matched  = 0;
        $problems = [];
        $fileDate = null;
        $seen     = false;

        try {
            foreach ($this->reader->rows($path) as $number => $row) {
                if ($number < self::FIRST_DATA_ROW) {
                    $this->assertHeader($row);
                    $seen = true;

                    continue;
                }

                $fileDate ??= $this->parseTradeDate($row[self::COLUMN_TRADE_DATE] ?? '');

                $ticker = strtoupper(trim($row[self::COLUMN_TICKER] ?? ''));

                if ($ticker === '') {
                    continue;
                }

                $stockId = $byTicker[$ticker] ?? null;

                if ($stockId === null) {
                    $unknown++;

                    continue;
                }

                $matched++;
                $value = trim($row[self::COLUMN_CLOSING] ?? '');

                // Saham yang disuspensi atau tidak diperdagangkan berharga 0 di
                // berkas IDX. Nol BUKAN harga: menyimpannya membuat market value
                // anjlok ke nol, dan CHECK constraint tabel pun menolaknya.
                // Baris seperti itu dilewati agar harga terakhir tetap berlaku.
                if ($value === '' || ! $this->isPositiveNumber($value)) {
                    $skipped++;

                    if (count($problems) < 5) {
                        $problems[] = sprintf('%s dilewati (harga %s).', $ticker, $value === '' ? 'kosong' : $value);
                    }

                    continue;
                }

                $parsed[$stockId] = Price::of($value)->toDecimalString();
            }
        } catch (RuntimeException $e) {
            throw new BusinessRuleException('Berkas XLSX gagal dibaca: ' . $e->getMessage());
        }

        if (! $seen) {
            throw new BusinessRuleException('Berkas XLSX kosong atau tidak memuat baris header.');
        }

        // Tidak satu pun kode saham cocok berarti berkasnya keliru — bukan
        // sekadar hari tanpa perdagangan. Ini dibedakan dari kasus "cocok tetapi
        // seluruh harganya nol", yang bukan kesalahan dan dilaporkan apa adanya.
        if ($matched === 0) {
            throw new BusinessRuleException(
                'Tidak ada kode saham yang cocok dengan saham '
                . ($heldOnly ? 'yang sedang dimiliki' : 'yang terdaftar') . '. '
                . 'Pastikan berkas yang diunggah adalah ringkasan perdagangan IDX.'
            );
        }

        $result = $parsed === [] ? ['saved' => 0, 'updated' => 0] : $this->persist($parsed, $date);

        $this->audit->record(
            'imported',
            'market_price',
            null,
            sprintf(
                'Impor harga pasar %s: %d baru, %d diperbarui, %d dilewati, %d di luar daftar',
                $date,
                $result['saved'],
                $result['updated'],
                $skipped,
                $unknown,
            ),
        );

        return [
            'saved'    => $result['saved'],
            'updated'  => $result['updated'],
            'skipped'  => $skipped,
            'unknown'  => $unknown,
            'date'     => $date,
            'fileDate' => $fileDate,
            'problems' => $problems,
        ];
    }

    /**
     * Menyimpan hasil parsing: yang belum ada disisipkan sekaligus, yang sudah
     * ada diperbarui hanya bila angkanya benar-benar berubah.
     *
     * Sengaja tidak memakai MarketPriceService::record() per baris: satu berkas
     * IDX memuat ratusan emiten, dan jalur itu melakukan satu query pencarian
     * plus satu entri audit untuk setiap harga. Impor mencatat satu entri audit
     * untuk keseluruhan pekerjaan, sebagaimana impor master saham.
     *
     * @param array<int, string> $parsed [stock_id => harga]
     *
     * @return array{saved:int, updated:int}
     */
    private function persist(array $parsed, string $date): array
    {
        $existing = [];

        foreach (
            $this->prices
                ->whereIn('stock_id', array_keys($parsed))
                ->where('price_date', $date)
                ->findAll() as $price
        ) {
            $existing[$price->stock_id] = $price;
        }

        $now     = date('Y-m-d H:i:s');
        $userId  = auth()->id();
        $insert  = [];
        $updated = 0;

        foreach ($parsed as $stockId => $value) {
            $current = $existing[$stockId] ?? null;

            if ($current === null) {
                $insert[] = [
                    'stock_id'      => $stockId,
                    'price_date'    => $date,
                    'closing_price' => $value,
                    'notes'         => 'Impor IDX',
                    'created_by'    => $userId,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];

                continue;
            }

            // Mengunggah ulang berkas yang sama tidak boleh menghasilkan ratusan
            // pembaruan semu; hanya harga yang berbeda yang ditulis ulang.
            if ($current->closingPrice()->toDecimalString() === $value) {
                continue;
            }

            $this->prices->update($current->id, ['closing_price' => $value, 'notes' => 'Impor IDX']);
            $updated++;
        }

        if ($insert !== []) {
            $this->prices->insertBatch($insert);
        }

        return ['saved' => count($insert), 'updated' => $updated];
    }

    /**
     * Id saham yang harganya hendak disimpan.
     *
     * @return list<int>
     */
    private function targetStockIds(bool $heldOnly): array
    {
        if (! $heldOnly) {
            return array_map(static fn ($stock): int => $stock->id, $this->stocks->active());
        }

        $ids = [];

        foreach ($this->positions->held() as $position) {
            $ids[] = (int) $position->stock_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $stockIds
     *
     * @return array<string, int> [TICKER => stock_id]
     */
    private function tickerMap(array $stockIds): array
    {
        $map = [];

        foreach ($this->stocks->whereIn('id', $stockIds)->findAll() as $stock) {
            $map[strtoupper(trim($stock->ticker))] = (int) $stock->id;
        }

        return $map;
    }

    /**
     * @param array<string, string> $header
     */
    private function assertHeader(array $header): void
    {
        $ticker  = strtolower($header[self::COLUMN_TICKER] ?? '');
        $closing = strtolower($header[self::COLUMN_CLOSING] ?? '');

        if (! str_contains($ticker, 'kode') || ! str_contains($closing, 'penutupan')) {
            throw new BusinessRuleException(
                'Susunan kolom tidak dikenali. Berkas harus berupa ringkasan perdagangan IDX '
                . 'dengan kolom B "Kode Saham" dan kolom K "Penutupan". '
                . sprintf('Yang terbaca: B="%s", K="%s".', $header[self::COLUMN_TICKER] ?? '', $header[self::COLUMN_CLOSING] ?? '')
            );
        }
    }

    /**
     * Mengubah "19 Agt 2026" menjadi "2026-08-19".
     *
     * Dipakai hanya untuk memberi tahu pengguna bila tanggal yang dipilih
     * berbeda dengan tanggal di dalam berkas; tidak pernah menimpa pilihannya.
     */
    private function parseTradeDate(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', trim($value), $m) !== 1) {
            return null;
        }

        $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? null;

        return $month === null
            ? null
            : sprintf('%s-%s-%02d', $m[3], $month, (int) $m[1]);
    }

    private function isPositiveNumber(string $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
}
