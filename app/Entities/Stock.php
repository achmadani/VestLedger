<?php

declare(strict_types=1);

namespace App\Entities;

use CodeIgniter\Entity\Entity;

/**
 * Saham (emiten).
 *
 * @property int         $id
 * @property string      $ticker
 * @property string      $company_name
 * @property string|null $sector
 * @property bool        $is_active
 */
class Stock extends Entity
{
    protected $casts = [
        'id'                 => 'int',
        'is_active'          => 'boolean',
        'shares_outstanding' => '?int',
    ];

    // Kolom tanggal harus terdaftar di sini agar dikembalikan sebagai objek
    // Time; tanpa itu ia tetap berupa string dan pemanggilan ->format() gagal.
    protected $dates = ['listing_date', 'profile_updated_at', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Ticker selalu disimpan huruf besar.
     */
    public function setTicker(string $ticker): self
    {
        $this->attributes['ticker'] = strtoupper(trim($ticker));

        return $this;
    }

    public function displayName(): string
    {
        return $this->ticker . ' — ' . $this->company_name;
    }
}
