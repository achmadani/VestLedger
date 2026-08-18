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
        'id'        => 'int',
        'is_active' => 'boolean',
    ];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

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
