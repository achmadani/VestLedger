<?php

declare(strict_types=1);

namespace App\Entities;

use CodeIgniter\Entity\Entity;

/**
 * Rekening efek / RDN pada sebuah sekuritas.
 *
 * @property int         $id
 * @property int         $securities_id
 * @property string      $label
 * @property string|null $account_number
 * @property string|null $bank_name
 * @property bool        $is_active
 */
class SecuritiesAccount extends Entity
{
    protected $casts = [
        'id'            => 'int',
        'securities_id' => 'int',
        'is_active'     => 'boolean',
    ];

    protected $dates = ['opened_at', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Nama tampilan lengkap; `securities_code` diisi oleh query join di model.
     */
    public function displayName(): string
    {
        $prefix = $this->attributes['securities_code'] ?? null;

        return $prefix !== null ? $prefix . ' — ' . $this->label : $this->label;
    }

    /**
     * Nomor rekening disamarkan secara default.
     *
     * §36 melarang menampilkan informasi rekening sekuritas secara terbuka;
     * nomor penuh hanya ditampilkan di halaman detail atas permintaan eksplisit.
     */
    public function maskedAccountNumber(): string
    {
        $number = (string) ($this->account_number ?? '');

        if ($number === '') {
            return '-';
        }

        $visible = 4;

        if (strlen($number) <= $visible) {
            return str_repeat('•', strlen($number));
        }

        return str_repeat('•', strlen($number) - $visible) . substr($number, -$visible);
    }
}
