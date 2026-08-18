<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

/*
 |--------------------------------------------------------------------------
 | Rute publik
 |--------------------------------------------------------------------------
 | Akar situs hanya mengarahkan ulang; tidak ada halaman publik di aplikasi ini.
 */
$routes->get('/', 'Home::index');

/*
 |--------------------------------------------------------------------------
 | Rute autentikasi (CodeIgniter Shield)
 |--------------------------------------------------------------------------
 | Registrasi mandiri dinonaktifkan (Config\Auth::$allowRegistration = false)
 | karena aplikasi ini hanya dipakai oleh pemilik portofolio. Akun dibuat lewat
 | perintah CLI `php spark shield:user create -n <username> -e <email> -g owner`.
 */
service('auth')->routes($routes, ['except' => ['register']]);

/*
 |--------------------------------------------------------------------------
 | Rute aplikasi (wajib login)
 |--------------------------------------------------------------------------
 | Filter 'session' memaksa autentikasi, filter 'permission' menegakkan
 | otorisasi per-halaman sesuai matrix di Config\AuthGroups (§36).
 */
$routes->group('', ['filter' => 'session'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Dashboard::index', ['filter' => 'permission:portfolio.view']);
});
