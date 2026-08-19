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
 | Shield hanya mendaftarkan logout sebagai GET. Aplikasi ini memakai form POST
 | ber-CSRF untuk logout, karena logout lewat GET dapat dipicu pihak lain hanya
 | dengan menyisipkan <img src=".../logout"> di halaman mana pun.
 |
 | Rute GET bawaan Shield sengaja dibiarkan agar tautan lama tetap bekerja.
 */
$routes->post('logout', '\CodeIgniter\Shield\Controllers\LoginController::logoutAction');

/*
 | Login dengan akun Google. Kedua rute harus dapat diakses tamu — justru
 | inilah alur yang membuat mereka menjadi pengguna yang login.
 */
$routes->get('auth/google', 'Auth\Google::redirectToProvider');
$routes->get('auth/google/callback', 'Auth\Google::callback');

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
        $routes->get('stocks/import', 'Master\Stocks::importForm', ['filter' => 'permission:masterdata.manage']);
        $routes->post('stocks/import', 'Master\Stocks::import', ['filter' => 'permission:masterdata.manage']);
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

    // Pencarian saham untuk kotak ketik-cari; tetap di balik autentikasi.
    $routes->get('api/stocks/search', 'Api\Stocks::search', ['filter' => 'permission:transaction.view']);

    // ---------------------------------------------------------------- Portofolio
    $routes->get('portfolio', 'Portfolio::index', ['filter' => 'permission:portfolio.view']);
    $routes->get('portfolio/securities', 'Portfolio::securities', ['filter' => 'permission:portfolio.view']);
    $routes->get('portfolio/tickers', 'Portfolio::tickers', ['filter' => 'permission:portfolio.view']);

    $routes->get('market-prices', 'MarketPrices::index', ['filter' => 'permission:portfolio.view']);
    $routes->post('market-prices', 'MarketPrices::store', ['filter' => 'permission:price.manage']);
    $routes->get('market-prices/import', 'MarketPrices::importForm', ['filter' => 'permission:price.manage']);
    $routes->post('market-prices/import', 'MarketPrices::import', ['filter' => 'permission:price.manage']);
    $routes->post('market-prices/(:num)/delete', 'MarketPrices::delete/$1', ['filter' => 'permission:price.manage']);

    // ---------------------------------------------------------------- Transaksi
    $routes->group('transactions', static function (RouteCollection $routes): void {
        $routes->get('/', 'Transactions\Index::index', ['filter' => 'permission:transaction.view']);

        // Transaksi kas: satu controller melayani empat jenis lewat slug.
        $routes->get('(top-up|withdrawal|transfer|fee)', 'Transactions\Cash::form/$1', ['filter' => 'permission:transaction.create']);
        $routes->post('(top-up|withdrawal|transfer|fee)', 'Transactions\Cash::store/$1', ['filter' => 'permission:transaction.create']);

        $routes->get('buy', 'Transactions\Stocks::buyForm', ['filter' => 'permission:transaction.create']);
        $routes->get('sell', 'Transactions\Stocks::sellForm', ['filter' => 'permission:transaction.create']);
        $routes->post('(buy|sell)', 'Transactions\Stocks::store/$1', ['filter' => 'permission:transaction.create']);

        $routes->get('dividend', 'Transactions\Dividends::form', ['filter' => 'permission:transaction.create']);
        $routes->post('dividend', 'Transactions\Dividends::store', ['filter' => 'permission:transaction.create']);

        // Pembatalan selalu POST: aksi berdampak besar tidak boleh dapat dipicu
        // lewat tautan atau prefetch browser.
        $routes->post('(cash|stock|dividend)/(:num)/reverse', 'Transactions\Index::reverse/$1/$2', ['filter' => 'permission:transaction.void']);
    });

    // ---------------------------------------------------------------- Akuntansi
    $routes->group('accounting', static function (RouteCollection $routes): void {
        $routes->get('journal', 'Accounting\Journal::index', ['filter' => 'permission:report.view']);
        $routes->get('journal/(:num)', 'Accounting\Journal::show/$1', ['filter' => 'permission:report.view']);
        $routes->get('ledger', 'Accounting\Ledger::index', ['filter' => 'permission:report.view']);
        $routes->get('trial-balance', 'Reports::trialBalance', ['filter' => 'permission:report.view']);

        $routes->get('opening-balance', 'Accounting\OpeningBalance::index', ['filter' => 'permission:opening.manage']);
        $routes->post('opening-balance', 'Accounting\OpeningBalance::store', ['filter' => 'permission:opening.manage']);
        $routes->post('opening-balance/reset', 'Accounting\OpeningBalance::reset', ['filter' => 'permission:opening.manage']);

        $routes->get('periods', 'Accounting\Periods::index', ['filter' => 'permission:report.view']);
        $routes->post('periods/generate', 'Accounting\Periods::generate', ['filter' => 'permission:period.manage']);
        $routes->post('periods/(:num)/close', 'Accounting\Periods::close/$1', ['filter' => 'permission:period.manage']);
        $routes->post('periods/(:num)/reopen', 'Accounting\Periods::reopen/$1', ['filter' => 'permission:period.manage']);
    });

    // ---------------------------------------------------------------- Laporan
    $routes->group('reports', ['filter' => 'permission:report.view'], static function (RouteCollection $routes): void {
        $routes->get('balance-sheet', 'Reports::balanceSheet');
        $routes->get('income-statement', 'Reports::incomeStatement');
        $routes->get('cash-flow', 'Reports::cashFlow');
        $routes->get('monthly', 'Reports::monthly');
        $routes->get('yearly', 'Reports::yearly');
        $routes->get('realized', 'Reports::realized');
        $routes->get('unrealized', 'Reports::unrealized');
        $routes->get('dividend', 'Reports::dividend');
        $routes->get('broker-fee', 'Reports::brokerFee');
    });

    // ---------------------------------------------------------------- Sistem
    $routes->group('system', static function (RouteCollection $routes): void {
        $routes->get('audit', 'System\Audit::index', ['filter' => 'permission:audit.view']);

        $routes->get('users', 'System\Users::index', ['filter' => 'permission:user.manage']);
        $routes->post('users', 'System\Users::create', ['filter' => 'permission:user.manage']);
        $routes->post('users/(:num)/group', 'System\Users::changeGroup/$1', ['filter' => 'permission:user.manage']);
        $routes->post('users/(:num)/activate', 'System\Users::activate/$1', ['filter' => 'permission:user.manage']);
        $routes->post('users/(:num)/deactivate', 'System\Users::deactivate/$1', ['filter' => 'permission:user.manage']);
    });
});
