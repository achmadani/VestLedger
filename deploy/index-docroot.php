<?php

/**
 * Front controller untuk DOCUMENT ROOT cPanel (terpisah dari root repo/aplikasi).
 *
 * Berkas ini adalah salinan public/index.php dengan SATU perbedaan: baris yang
 * me-require app/Config/Paths.php memakai path ABSOLUT ke root repo. Placeholder
 * __APPROOT__ di bawah diganti otomatis oleh .cpanel.yml saat deploy (lihat task
 * yang menyalin index.php ke document root).
 *
 * JANGAN edit berkas ini langsung di server; sunting di repo lalu deploy ulang.
 */

use CodeIgniter\Boot;
use Config\Paths;

$minPhpVersion = '8.2';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

// Root aplikasi (repo clone) berada DI LUAR document root — path absolut
// disisipkan saat deploy menggantikan __APPROOT__.
require '__APPROOT__/app/Config/Paths.php';

$paths = new Paths();

require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
