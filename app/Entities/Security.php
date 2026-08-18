<?php

declare(strict_types=1);

namespace App\Entities;

use CodeIgniter\Entity\Entity;

/**
 * Perusahaan sekuritas / broker.
 *
 * @property int         $id
 * @property string      $code
 * @property string      $name
 * @property string|null $notes
 * @property bool        $is_active
 */
class Security extends Entity
{
    protected $casts = [
        'id'        => 'int',
        'is_active' => 'boolean',
    ];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    public function displayName(): string
    {
        return $this->code . ' — ' . $this->name;
    }
}
