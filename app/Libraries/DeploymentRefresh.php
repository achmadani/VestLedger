<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Version;

/**
 * Membereskan sisa deploy pada request pertama setelah versi berubah.
 *
 * Hosting produksi aplikasi ini tidak punya shell, terminal, maupun cron yang
 * dapat diandalkan: pembaruan kode datang lewat `git pull` yang dijalankan
 * cPanel, dan setelah itu TIDAK ADA satu pun perintah yang bisa dijalankan di
 * server. Dua hal karena itu harus dikerjakan aplikasi sendiri:
 *
 *   1. Menghapus cache locator/config CI4. Selama cache itu ada, berkas baru
 *      yang ikut ter-pull — view, migrasi, command — tidak terlihat sama
 *      sekali, dan gejalanya menyesatkan (lihat docs/STATUS.md §4).
 *   2. Menulis writable/build.json, supaya sidebar menampilkan commit yang
 *      benar-benar berjalan. Commit dibaca dari .git (berkas biasa), bukan
 *      dengan memanggil `git`.
 *
 * Pemicunya berkas VERSION, yang aturan repo ini menjamin naik pada setiap
 * push. Selama versi tidak berubah, biayanya satu pembacaan berkas penanda.
 */
final class DeploymentRefresh
{
    private const MARKER = 'deployed.json';

    public static function run(): void
    {
        $version = Version::info()['version'];
        $marker  = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . self::MARKER;

        if (is_file($marker) && trim((string) file_get_contents($marker)) === $version) {
            return;
        }

        self::clearCache();
        self::writeBuildInfo($version);

        @file_put_contents($marker, $version);
    }

    /**
     * Menghapus isi writable/cache, kecuali index.html dan berkas penanda.
     */
    private static function clearCache(): void
    {
        $dir = WRITEPATH . 'cache';

        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }

            if (in_array(basename($file), ['index.html', '.gitkeep', self::MARKER], true)) {
                continue;
            }

            @unlink($file);
        }
    }

    private static function writeBuildInfo(string $version): void
    {
        $commit = self::commitFromGitDir();

        @file_put_contents(WRITEPATH . 'build.json', json_encode([
            'commit'   => $commit,
            'built_at' => date('Y-m-d H:i:s'),
            'version'  => $version,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Membaca commit HEAD langsung dari berkas di .git.
     *
     * Mengembalikan null bila .git tidak ada — deploy lewat unggah berkas,
     * misalnya, tidak membawa serta direktori itu.
     */
    private static function commitFromGitDir(): ?string
    {
        $head = ROOTPATH . '.git' . DIRECTORY_SEPARATOR . 'HEAD';

        if (! is_file($head)) {
            return null;
        }

        $contents = trim((string) file_get_contents($head));

        if (! str_starts_with($contents, 'ref: ')) {
            return $contents !== '' ? $contents : null;
        }

        $ref  = substr($contents, 5);
        $path = ROOTPATH . '.git' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);

        if (is_file($path)) {
            return trim((string) file_get_contents($path)) ?: null;
        }

        // Clone yang masih segar menyimpan ref-nya terpaket, belum sebagai berkas.
        $packed = ROOTPATH . '.git' . DIRECTORY_SEPARATOR . 'packed-refs';

        if (is_file($packed)) {
            foreach (explode("\n", (string) file_get_contents($packed)) as $line) {
                if (str_ends_with(trim($line), ' ' . $ref)) {
                    return strtok(trim($line), ' ') ?: null;
                }
            }
        }

        return null;
    }
}
