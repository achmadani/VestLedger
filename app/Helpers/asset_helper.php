<?php

declare(strict_types=1);

/**
 * Helper aset statis.
 */

if (! function_exists('asset_url')) {
    /**
     * URL aset dengan cache-busting berbasis mtime file.
     *
     * Shared hosting umumnya memasang cache header panjang untuk file statis;
     * query string versi memastikan browser mengambil CSS/JS baru setelah deploy
     * tanpa perlu mengganti nama file.
     */
    function asset_url(string $path): string
    {
        $relative = ltrim($path, '/');
        $absolute = FCPATH . $relative;
        $version  = is_file($absolute) ? (string) filemtime($absolute) : null;

        return site_url($relative) . ($version !== null ? '?v=' . $version : '');
    }
}
