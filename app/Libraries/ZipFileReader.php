<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;
use ZipArchive;

/**
 * Membaca satu entri dari berkas ZIP, dengan atau tanpa ekstensi zip.
 *
 * Hosting produksi aplikasi ini TIDAK memiliki `ext-zip`, dan menyalakannya di
 * cPanel bukan hal yang selalu tersedia. Karena XLSX pada dasarnya hanyalah ZIP
 * berisi XML, membaca kontainernya sendiri jauh lebih murah daripada menuntut
 * ekstensi yang belum tentu ada — apalagi dibanding menambah dependency.
 *
 * Bila `ext-zip` ada, ia dipakai karena sudah teruji dan lebih cepat. Bila
 * tidak, direktori pusat ZIP dibaca langsung. Yang didukung hanya dua metode
 * penyimpanan yang benar-benar dipakai berkas XLSX: `Stored` (tanpa kompresi,
 * inilah yang dipakai ekspor IDX) dan `Deflate` (dipakai Excel bila berkas
 * disimpan ulang), yang membutuhkan zlib — bawaan PHP.
 */
class ZipFileReader
{
    private const EOCD_SIGNATURE    = "PK\x05\x06";
    private const CENTRAL_SIGNATURE = "PK\x01\x02";
    private const LOCAL_SIGNATURE   = "PK\x03\x04";

    private const METHOD_STORED  = 0;
    private const METHOD_DEFLATE = 8;

    /** Penanda ukuran/offset yang sebenarnya tersimpan di kolom ZIP64. */
    private const ZIP64_MARKER = 0xFFFFFFFF;

    /**
     * @param bool $preferNative pakai ext-zip bila tersedia; dimatikan di test
     *                           agar jalur murni-PHP ikut teruji
     */
    public function __construct(private bool $preferNative = true)
    {
    }

    /**
     * Isi sebuah entri, atau null bila entri itu tidak ada di dalam arsip.
     */
    public function read(string $path, string $entry): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Berkas tidak ditemukan atau tidak dapat dibaca.');
        }

        if ($this->preferNative && class_exists(ZipArchive::class)) {
            return $this->readWithExtension($path, $entry);
        }

        return $this->readWithoutExtension($path, $entry);
    }

    private function readWithExtension(string $path, string $entry): ?string
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Berkas bukan arsip yang sah.');
        }

        try {
            $contents = $zip->getFromName($entry);

            return $contents === false ? null : $contents;
        } finally {
            $zip->close();
        }
    }

    private function readWithoutExtension(string $path, string $entry): ?string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Berkas arsip gagal dibuka.');
        }

        try {
            $directory = $this->centralDirectory($handle, $path);

            if (! isset($directory[$entry])) {
                return null;
            }

            return $this->extract($handle, $directory[$entry]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Membaca direktori pusat ZIP.
     *
     * Ukuran diambil dari direktori pusat, BUKAN dari header lokal: berkas yang
     * ditulis secara mengalir menyisakan ukuran nol di header lokal dan
     * meletakkan angka sebenarnya di belakang data.
     *
     * @param resource $handle
     *
     * @return array<string, array{method:int, size:int, offset:int}>
     */
    private function centralDirectory($handle, string $path): array
    {
        $fileSize = (int) filesize($path);

        // Komentar arsip boleh sepanjang 65.535 byte, dan EOCD berada tepat
        // sebelumnya; itulah sebabnya pencarian dibatasi sejauh ini dari ujung.
        $tailSize = min($fileSize, 65_557);
        fseek($handle, $fileSize - $tailSize);
        $tail = (string) fread($handle, $tailSize);

        $eocd = strrpos($tail, self::EOCD_SIGNATURE);

        if ($eocd === false) {
            throw new RuntimeException('Berkas bukan arsip ZIP yang sah.');
        }

        $record = substr($tail, $eocd, 22);

        if (strlen($record) < 22) {
            throw new RuntimeException('Arsip ZIP terpotong.');
        }

        $fields = unpack('vdisk/vstart/vhere/vtotal/Vsize/Voffset', substr($record, 4, 18));

        if ($fields === false) {
            throw new RuntimeException('Direktori arsip ZIP tidak dapat dibaca.');
        }

        if ($fields['offset'] === self::ZIP64_MARKER || $fields['total'] === 0xFFFF) {
            throw new RuntimeException('Arsip ZIP64 belum didukung tanpa ekstensi zip.');
        }

        fseek($handle, $fields['offset']);
        $entries = [];

        for ($i = 0; $i < $fields['total']; $i++) {
            $header = (string) fread($handle, 46);

            if (strlen($header) < 46 || ! str_starts_with($header, self::CENTRAL_SIGNATURE)) {
                break;
            }

            $entry = unpack(
                'vversion/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed'
                . '/vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Voffset',
                substr($header, 4, 42),
            );

            if ($entry === false) {
                throw new RuntimeException('Entri arsip ZIP tidak dapat dibaca.');
            }

            $name = (string) fread($handle, $entry['namelen']);

            if ($entry['extralen'] > 0) {
                fseek($handle, $entry['extralen'], SEEK_CUR);
            }

            if ($entry['commentlen'] > 0) {
                fseek($handle, $entry['commentlen'], SEEK_CUR);
            }

            $entries[$name] = [
                'method' => $entry['method'],
                'size'   => $entry['compressed'],
                'offset' => $entry['offset'],
            ];
        }

        return $entries;
    }

    /**
     * @param resource                                      $handle
     * @param array{method:int, size:int, offset:int} $entry
     */
    private function extract($handle, array $entry): string
    {
        fseek($handle, $entry['offset']);
        $local = (string) fread($handle, 30);

        if (strlen($local) < 30 || ! str_starts_with($local, self::LOCAL_SIGNATURE)) {
            throw new RuntimeException('Header entri arsip ZIP tidak sah.');
        }

        $lengths = unpack('vnamelen/vextralen', substr($local, 26, 4));

        if ($lengths === false) {
            throw new RuntimeException('Header entri arsip ZIP tidak dapat dibaca.');
        }

        fseek($handle, $lengths['namelen'] + $lengths['extralen'], SEEK_CUR);

        $data = $entry['size'] > 0 ? (string) fread($handle, $entry['size']) : '';

        if ($entry['method'] === self::METHOD_STORED) {
            return $data;
        }

        if ($entry['method'] !== self::METHOD_DEFLATE) {
            throw new RuntimeException('Metode kompresi ZIP tidak didukung: ' . $entry['method'] . '.');
        }

        if (! function_exists('gzinflate')) {
            throw new RuntimeException('Berkas terkompresi memerlukan ekstensi zlib, yang tidak aktif di server ini.');
        }

        $inflated = @gzinflate($data);

        if ($inflated === false) {
            throw new RuntimeException('Isi entri arsip ZIP gagal didekompresi.');
        }

        return $inflated;
    }
}
