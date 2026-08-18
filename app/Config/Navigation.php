<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Struktur menu sidebar.
 *
 * Menu didefinisikan sebagai data, bukan HTML, sehingga:
 *  - View sidebar tetap tipis dan tidak berisi logika (§29),
 *  - setiap phase berikutnya cukup mengubah 'enabled' => true di sini,
 *  - hak akses menu memakai permission Shield yang sama dengan filter route (§36).
 *
 * Item dengan 'enabled' => false ditampilkan sebagai placeholder non-aktif,
 * supaya kerangka aplikasi terlihat utuh tanpa menghasilkan link 404.
 */
class Navigation extends BaseConfig
{
    /**
     * @return list<array{label:string, icon:string, items:list<array{label:string, route:string, permission:?string, enabled:bool, phase:?string}>}>
     */
    public function menu(): array
    {
        return [
            [
                'label' => 'Ringkasan',
                'icon'  => 'dashboard',
                'items' => [
                    ['label' => 'Dashboard', 'route' => '/dashboard', 'permission' => 'portfolio.view', 'enabled' => true, 'phase' => null],
                ],
            ],
            [
                'label' => 'Portofolio',
                'icon'  => 'chart',
                'items' => [
                    ['label' => 'Portofolio Global', 'route' => '/portfolio', 'permission' => 'portfolio.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Per Sekuritas', 'route' => '/portfolio/securities', 'permission' => 'portfolio.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Per Saham', 'route' => '/portfolio/tickers', 'permission' => 'portfolio.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Harga Pasar', 'route' => '/market-prices', 'permission' => 'price.manage', 'enabled' => true, 'phase' => null],
                ],
            ],
            [
                'label' => 'Transaksi',
                'icon'  => 'transaction',
                'items' => [
                    ['label' => 'Semua Transaksi', 'route' => '/transactions', 'permission' => 'transaction.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Beli Saham', 'route' => '/transactions/buy', 'permission' => 'transaction.create', 'enabled' => true, 'phase' => null],
                    ['label' => 'Jual Saham', 'route' => '/transactions/sell', 'permission' => 'transaction.create', 'enabled' => true, 'phase' => null],
                    ['label' => 'Dividen', 'route' => '/transactions/dividend', 'permission' => 'transaction.create', 'enabled' => true, 'phase' => null],
                    ['label' => 'Top Up Dana', 'route' => '/transactions/top-up', 'permission' => 'transaction.create', 'enabled' => true, 'phase' => null],
                    ['label' => 'Withdrawal', 'route' => '/transactions/withdrawal', 'permission' => 'transaction.create', 'enabled' => true, 'phase' => null],
                    ['label' => 'Transfer Antar Sekuritas', 'route' => '/transactions/transfer', 'permission' => 'transaction.create', 'enabled' => true, 'phase' => null],
                    ['label' => 'Biaya Administrasi', 'route' => '/transactions/fee', 'permission' => 'transaction.create', 'enabled' => true, 'phase' => null],
                ],
            ],
            [
                'label' => 'Akuntansi',
                'icon'  => 'book',
                'items' => [
                    ['label' => 'Jurnal', 'route' => '/accounting/journal', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Buku Besar', 'route' => '/accounting/ledger', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Neraca Saldo', 'route' => '/accounting/trial-balance', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Periode Akuntansi', 'route' => '/accounting/periods', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Saldo Awal', 'route' => '/accounting/opening-balance', 'permission' => 'opening.manage', 'enabled' => true, 'phase' => null],
                ],
            ],
            [
                'label' => 'Laporan',
                'icon'  => 'report',
                'items' => [
                    ['label' => 'Neraca', 'route' => '/reports/balance-sheet', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Laba Rugi', 'route' => '/reports/income-statement', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Arus Kas', 'route' => '/reports/cash-flow', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Laporan Bulanan', 'route' => '/reports/monthly', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Laporan Tahunan', 'route' => '/reports/yearly', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Realized Gain/Loss', 'route' => '/reports/realized', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Unrealized Gain/Loss', 'route' => '/reports/unrealized', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Dividen', 'route' => '/reports/dividend', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Broker Fee', 'route' => '/reports/broker-fee', 'permission' => 'report.view', 'enabled' => true, 'phase' => null],
                ],
            ],
            [
                'label' => 'Master Data',
                'icon'  => 'database',
                'items' => [
                    ['label' => 'Sekuritas', 'route' => '/master/securities', 'permission' => 'masterdata.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Saham', 'route' => '/master/stocks', 'permission' => 'masterdata.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Chart of Accounts', 'route' => '/master/accounts', 'permission' => 'masterdata.view', 'enabled' => true, 'phase' => null],
                ],
            ],
            [
                'label' => 'Sistem',
                'icon'  => 'shield',
                'items' => [
                    ['label' => 'Audit Trail', 'route' => '/system/audit', 'permission' => 'audit.view', 'enabled' => true, 'phase' => null],
                    ['label' => 'Pengguna', 'route' => '/system/users', 'permission' => 'user.manage', 'enabled' => false, 'phase' => 'Phase 9'],
                ],
            ],
        ];
    }
}
