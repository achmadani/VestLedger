<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;
use XMLReader;
use ZipArchive;

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
 * XLSX adalah ZIP berisi XML, sehingga cukup ext-zip dan XMLReader — keduanya
 * bawaan PHP dan aktif di hosting produksi. XMLReader dipakai supaya berkas
 * dibaca mengalir; sheet IDX berukuran ~800 KB XML dan tidak perlu dimuat utuh
 * ke memori.
 */
class XlsxReader
{
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
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Berkas tidak ditemukan atau tidak dapat dibaca.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Berkas bukan XLSX yang sah (tidak dapat dibuka sebagai arsip).');
        }

        try {
            $sheetPath = $this->firstSheetPath($zip);
            $strings   = $this->sharedStrings($zip);

            yield from $this->readSheet($zip, $sheetPath, $strings);
        } finally {
            $zip->close();
        }
    }

    /**
     * Mencari sheet pertama lewat workbook.xml.rels.
     *
     * Tidak langsung menebak "xl/worksheets/sheet1.xml": penamaan itu memang
     * lazim, tetapi bukan jaminan — berkas hasil ekspor sebagian aplikasi
     * memakai nama lain.
     */
    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (is_string($workbook) && is_string($rels)
            && preg_match('/<sheet[^>]*r:id="([^"]+)"/', $workbook, $sheet) === 1
            && preg_match('/Id="' . preg_quote($sheet[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $target) === 1
        ) {
            $path = ltrim($target[1], '/');

            if (! str_starts_with($path, 'xl/')) {
                $path = 'xl/' . $path;
            }

            if ($zip->locateName($path) !== false) {
                return $path;
            }
        }

        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            return 'xl/worksheets/sheet1.xml';
        }

        throw new RuntimeException('XLSX tidak memuat lembar kerja yang dapat dibaca.');
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
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($xml) || $xml === '') {
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
                    $node      = $reader->readInnerXml();
                    $strings[] = $this->plainText($node);
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
    private function readSheet(ZipArchive $zip, string $sheetPath, array $strings): iterable
    {
        $stream = $zip->getStream($sheetPath);

        if ($stream === false) {
            throw new RuntimeException('Lembar kerja XLSX gagal dibaca.');
        }

        // XMLReader tidak dapat membaca stream ZIP secara langsung, sehingga isi
        // sheet disalin ke berkas sementara lebih dulu. Salinan ini dihapus pada
        // blok finally, termasuk bila pembacaan dihentikan di tengah jalan.
        $temp = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($temp === false) {
            fclose($stream);

            throw new RuntimeException('Gagal menyiapkan berkas sementara untuk membaca XLSX.');
        }

        $out = fopen($temp, 'wb');

        if ($out === false) {
            fclose($stream);
            @unlink($temp);

            throw new RuntimeException('Gagal menyiapkan berkas sementara untuk membaca XLSX.');
        }

        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        $reader = new XMLReader();

        try {
            if (! $reader->open($temp, 'UTF-8', LIBXML_NONET)) {
                throw new RuntimeException('Lembar kerja XLSX gagal dibaca.');
            }

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $number = (int) $reader->getAttribute('r');
                $xml    = $reader->readOuterXml();

                if ($xml === '') {
                    continue;
                }

                $cells = $this->parseRow($xml, $strings);

                if ($cells !== []) {
                    yield $number => $cells;
                }
            }
        } finally {
            $reader->close();
            @unlink($temp);
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
            $index = (int) $raw;

            return $strings[$index] ?? '';
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
