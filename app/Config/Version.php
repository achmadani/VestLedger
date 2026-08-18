<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Versi aplikasi.
 *
 * Nomor versi dibaca dari berkas VERSION di akar proyek, dan metadata build
 * (commit serta tanggal) dari writable/build.json bila ada.
 *
 * Keduanya berupa BERKAS, bukan hasil pemanggilan `git` saat runtime: server
 * produksi sering tidak memiliki direktori .git sama sekali, dan memanggil
 * proses eksternal pada setiap request jelas tidak pantas.
 */
class Version extends BaseConfig
{
    private static ?array $cache = null;

    /**
     * @return array{version:string, commit:?string, built_at:?string}
     */
    public static function info(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $version = 'dev';
        $file    = ROOTPATH . 'VERSION';

        if (is_file($file)) {
            $version = trim((string) file_get_contents($file)) ?: 'dev';
        }

        $commit   = null;
        $builtAt  = null;
        $buildFile = WRITEPATH . 'build.json';

        if (is_file($buildFile)) {
            $build = json_decode((string) file_get_contents($buildFile), true);

            if (is_array($build)) {
                $commit  = isset($build['commit']) ? (string) $build['commit'] : null;
                $builtAt = isset($build['built_at']) ? (string) $build['built_at'] : null;
            }
        }

        return self::$cache = ['version' => $version, 'commit' => $commit, 'built_at' => $builtAt];
    }

    public static function string(): string
    {
        return 'v' . self::info()['version'];
    }

    /**
     * Versi lengkap dengan commit pendek, mis. "v0.5.1 · 107e507".
     */
    public static function full(): string
    {
        $info  = self::info();
        $label = 'v' . $info['version'];

        if ($info['commit'] !== null && $info['commit'] !== '') {
            $label .= ' · ' . substr($info['commit'], 0, 7);
        }

        return $label;
    }
}
