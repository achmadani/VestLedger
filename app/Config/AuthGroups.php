<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

/**
 * VestLedger authorization model.
 *
 * Aplikasi ini dipakai oleh satu investor (owner), namun struktur group/permission
 * dibuat eksplisit agar penambahan peran di masa depan (mis. akuntan, auditor)
 * tidak perlu mengubah business logic — cukup mengubah matrix di bawah.
 */
class AuthGroups extends ShieldAuthGroups
{
    /**
     * Group default untuk user yang baru dibuat.
     * Sengaja dipilih group paling terbatas (viewer), sehingga user baru
     * tidak pernah otomatis mendapat hak tulis atas data keuangan.
     */
    public string $defaultGroup = 'viewer';

    /**
     * @var array<string, array<string, string>>
     */
    public array $groups = [
        'owner' => [
            'title'       => 'Owner',
            'description' => 'Pemilik portofolio. Akses penuh termasuk tutup periode dan reversal.',
        ],
        'accountant' => [
            'title'       => 'Accountant',
            'description' => 'Input transaksi dan koreksi jurnal, tanpa hak tutup/buka periode.',
        ],
        'viewer' => [
            'title'       => 'Viewer',
            'description' => 'Hanya dapat melihat portofolio dan laporan (read-only).',
        ],
    ];

    /**
     * Permission yang dikenal sistem. Permission di luar daftar ini tidak dapat dipakai.
     */
    public array $permissions = [
        'masterdata.view'    => 'Melihat master data (sekuritas, saham, CoA)',
        'masterdata.manage'  => 'Menambah/mengubah master data',
        'transaction.view'   => 'Melihat transaksi',
        'transaction.create' => 'Membuat transaksi baru',
        'transaction.void'   => 'Membatalkan/reversal transaksi yang sudah posted',
        'price.manage'       => 'Input harga pasar (closing price)',
        'portfolio.view'     => 'Melihat portofolio dan posisi saham',
        'report.view'        => 'Melihat laporan keuangan dan laporan investasi',
        'period.manage'      => 'Membuka/menutup periode akuntansi',
        'opening.manage'     => 'Mengisi dan mengubah opening balance',
        'audit.view'         => 'Melihat audit trail',
        'user.manage'        => 'Mengelola user aplikasi',
    ];

    /**
     * Pemetaan permission ke group.
     */
    public array $matrix = [
        'owner' => [
            'masterdata.*',
            'transaction.*',
            'price.*',
            'portfolio.*',
            'report.*',
            'period.*',
            'opening.*',
            'audit.*',
            'user.*',
        ],
        'accountant' => [
            'masterdata.view',
            'masterdata.manage',
            'transaction.view',
            'transaction.create',
            'transaction.void',
            'price.manage',
            'portfolio.view',
            'report.view',
            'audit.view',
        ],
        'viewer' => [
            'masterdata.view',
            'transaction.view',
            'portfolio.view',
            'report.view',
        ],
    ];
}
