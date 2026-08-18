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
 | Filter 'session' memaksa autentikasi. Setiap rute juga membawa filter
 | 'permission' sendiri, sehingga hak akses ditegakkan di lapisan routing —
 | bukan sekadar disembunyikan dari menu (§36).
 |
 | Aksi yang mengubah data selalu POST, tidak pernah GET, agar tidak dapat
 | dipicu lewat prefetch browser atau tautan yang dibagikan.
 */
$routes->group('', ['filter' => 'session'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Dashboard::index', ['filter' => 'permission:portfolio.view']);

    // ---------------------------------------------------------------- Master data
    $routes->group('master', static function (RouteCollection $routes): void {
        // Sekuritas & rekening/RDN
        $routes->get('securities', 'Master\Securities::index', ['filter' => 'permission:masterdata.view']);
        $routes->get('securities/new', 'Master\Securities::new', ['filter' => 'permission:masterdata.manage']);
        $routes->post('securities', 'Master\Securities::create', ['filter' => 'permission:masterdata.manage']);
        $routes->get('securities/(:num)', 'Master\Securities::show/$1', ['filter' => 'permission:masterdata.view']);
        $routes->get('securities/(:num)/edit', 'Master\Securities::edit/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('securities/(:num)', 'Master\Securities::update/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('securities/(:num)/delete', 'Master\Securities::delete/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('securities/(:num)/deactivate', 'Master\Securities::deactivate/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('securities/(:num)/activate', 'Master\Securities::activate/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('securities/(:num)/accounts', 'Master\Securities::storeAccount/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('securities/(:num)/accounts/(:num)', 'Master\Securities::updateAccount/$1/$2', ['filter' => 'permission:masterdata.manage']);

        // Saham
        $routes->get('stocks', 'Master\Stocks::index', ['filter' => 'permission:masterdata.view']);
        $routes->get('stocks/new', 'Master\Stocks::new', ['filter' => 'permission:masterdata.manage']);
        $routes->post('stocks', 'Master\Stocks::create', ['filter' => 'permission:masterdata.manage']);
        $routes->get('stocks/(:num)/edit', 'Master\Stocks::edit/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('stocks/(:num)', 'Master\Stocks::update/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('stocks/(:num)/delete', 'Master\Stocks::delete/$1', ['filter' => 'permission:masterdata.manage']);

        // Chart of Accounts
        $routes->get('accounts', 'Master\Accounts::index', ['filter' => 'permission:masterdata.view']);
        $routes->get('accounts/new', 'Master\Accounts::new', ['filter' => 'permission:masterdata.manage']);
        $routes->post('accounts', 'Master\Accounts::create', ['filter' => 'permission:masterdata.manage']);
        $routes->get('accounts/(:num)/edit', 'Master\Accounts::edit/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('accounts/(:num)', 'Master\Accounts::update/$1', ['filter' => 'permission:masterdata.manage']);
        $routes->post('accounts/(:num)/delete', 'Master\Accounts::delete/$1', ['filter' => 'permission:masterdata.manage']);
    });

    // ---------------------------------------------------------------- Akuntansi
    $routes->group('accounting', static function (RouteCollection $routes): void {
        $routes->get('periods', 'Accounting\Periods::index', ['filter' => 'permission:report.view']);
        $routes->post('periods/generate', 'Accounting\Periods::generate', ['filter' => 'permission:period.manage']);
        $routes->post('periods/(:num)/close', 'Accounting\Periods::close/$1', ['filter' => 'permission:period.manage']);
        $routes->post('periods/(:num)/reopen', 'Accounting\Periods::reopen/$1', ['filter' => 'permission:period.manage']);
    });
});
