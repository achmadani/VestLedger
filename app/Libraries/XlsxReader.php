<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;
use XMLReader;

/**
 * Pembaca XLSX seadanya: hanya sel bernilai, hanya sheet pertama.
 *
 * Ini BUKAN pengganti PhpSpreadsheet, dan memang tidak berusaha menjadi itu.
 * §34 menolak PhpSpreadsheet karena ~5 MB dependency untuk pekerjaan yang
 * dilakukan sesekali, dan alasan itu masih berlaku. Yang dibutuhkan di sini
 * jauh lebih sempit: membaca kolom teks dan angka dari satu sheet berkas ekspor
 * IDX yang formatnya selalu sama.
 *
 * Yang TIDAK dilakukan, dan tidak perlu: rumus (yang dibaca hanya nilai hasil
 * kalkulasi yang sudah tersimpan), format tampilan, tanggal serial Excel,
 * beberapa sheet, sel bergabung, maupun gaya.
 *
 * Kontainer ZIP-nya dibaca lewat ZipFileReader, yang tidak bergantung pada
 * `ext-zip` — hosting produksi tidak memilikinya.
 */
class XlsxReader
{
    public function __construct(private ?ZipFileReader $zip = null)
    {
        $this->zip ??= new ZipFileReader();
    }

    /**
     * Membaca sheet pertama sebagai baris berindeks huruf kolom.
     *
     * Sel kosong TIDAK muncul sebagai kunci — XLSX memang tidak menuliskannya.
     * Karena itu pembacaan selalu lewat huruf kolom (`$row['K']`), tidak pernah
     * lewat posisi, sebab posisi akan bergeser pada baris yang selnya bolong.
     *
     * @return iterable<int, array<string, string>> nomor baris => [huruf kolom => nilai]
     */
    public function rows(string $path): iterable
    {
        $sheet = $this->zip->read($path, $this->firstSheetPath($path));

        if ($sheet === null || $sheet === '') {
            throw new RuntimeException('XLSX tidak memuat lembar kerja yang dapat dibaca.');
        }

        yield from $this->readSheet($sheet, $this->sharedStrings($path));
    }

    /**
     * Mencari sheet pertama lewat workbook.xml.rels.
     *
     * Tidak langsung menebak "xl/worksheets/sheet1.xml": penamaan itu memang
     * lazim, tetapi bukan jaminan — berkas hasil ekspor sebagian aplikasi
     * memakai nama lain.
     */
    private function firstSheetPath(string $path): string
    {
        $workbook = $this->zip->read($path, 'xl/workbook.xml');
        $rels     = $this->zip->read($path, 'xl/_rels/workbook.xml.rels');

        if ($workbook !== null && $rels !== null
            && preg_match('/<sheet[^>]*r:id="([^"]+)"/', $workbook, $sheet) === 1
            && preg_match('/Id="' . preg_quote($sheet[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $target) === 1
        ) {
            $found = ltrim($target[1], '/');

            if (! str_starts_with($found, 'xl/')) {
                $found = 'xl/' . $found;
            }

            return $found;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * Memuat tabel string bersama, bila ada.
     *
     * Berkas ekspor IDX menyimpan teks langsung di dalam sel (t="str"), sehingga
     * tabel ini kerap tidak ada sama sekali. Excel menyimpannya terpisah, jadi
     * keduanya tetap harus didukung.
     *
     * @return list<string>
     */
    private function sharedStrings(string $path): array
    {
        $xml = $this->zip->read($path, 'xl/sharedStrings.xml');

        if ($xml === null || $xml === '') {
            return [];
        }

        $reader  = new XMLReader();
        $strings = [];

        if (! $reader->XML($xml, 'UTF-8', LIBXML_NONET)) {
            return [];
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    // Satu entri bisa terpecah menjadi beberapa <t> bila sebagian
                    // hurufnya diberi format berbeda; seluruhnya digabung.
                    $strings[] = $this->plainText($reader->readInnerXml());
                }
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /**
     * @param list<string> $strings
     *
     * @return iterable<int, array<string, string>>
     */
    private function readSheet(string $xml, array $strings): iterable
    {
        $reader = new XMLReader();

        if (! $reader->XML($xml, 'UTF-8', LIBXML_NONET)) {
            throw new RuntimeException('Lembar kerja XLSX gagal dibaca.');
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $number = (int) $reader->getAttribute('r');
                $outer  = $reader->readOuterXml();

                if ($outer === '') {
                    continue;
                }

                $cells = $this->parseRow($outer, $strings);

                if ($cells !== []) {
                    yield $number => $cells;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param list<string> $strings
     *
     * @return array<string, string>
     */
    private function parseRow(string $xml, array $strings): array
    {
        if (preg_match_all('/<c\b([^>]*)(?:\/>|>(.*?)<\/c>)/s', $xml, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $cells = [];

        foreach ($matches as $match) {
            $attributes = $match[1];
            $body       = $match[2] ?? '';

            if (preg_match('/\br="([A-Z]+)\d+"/', $attributes, $ref) !== 1) {
                continue;
            }

            $type  = preg_match('/\bt="([^"]+)"/', $attributes, $t) === 1 ? $t[1] : 'n';
            $value = $this->cellValue($type, $body, $strings);

            if ($value !== '') {
                $cells[$ref[1]] = $value;
            }
        }

        return $cells;
    }

    /**
     * @param list<string> $strings
     */
    private function cellValue(string $type, string $body, array $strings): string
    {
        if ($type === 'inlineStr') {
            return $this->plainText($body);
        }

        if (preg_match('/<v>(.*?)<\/v>/s', $body, $v) !== 1) {
            return '';
        }

        $raw = $this->plainText($v[1]);

        // t="s" berarti isinya indeks ke tabel string bersama, bukan angka.
        if ($type === 's') {
            return $strings[(int) $raw] ?? '';
        }

        return $raw;
    }

    /**
     * Membuang tag XML lalu menormalkan entitas menjadi teks biasa.
     */
    private function plainText(string $xml): string
    {
        $text = preg_replace('/<[^>]*>/', '', $xml) ?? '';

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}
