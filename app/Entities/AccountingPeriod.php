<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\PeriodStatus;
use CodeIgniter\Entity\Entity;

/**
 * Periode akuntansi bulanan.
 *
 * @property int    $id
 * @property string $code
 * @property int    $year
 * @property int    $month
 * @property string $status
 */
class AccountingPeriod extends Entity
{
    protected $casts = [
        'id'        => 'int',
        'year'      => 'int',
        'month'     => 'int',
        'closed_by' => '?int',
    ];

    protected $dates = ['start_date', 'end_date', 'closed_at', 'created_at', 'updated_at'];

    public function status(): PeriodStatus
    {
        return PeriodStatus::from($this->attributes['status']);
    }

    public function isOpen(): bool
    {
        return $this->status() === PeriodStatus::Open;
    }

    /**
     * Nama bulan dalam bahasa Indonesia, mis. "Januari 2026".
     */
    public function displayName(): string
    {
        $months = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return ($months[$this->month] ?? (string) $this->month) . ' ' . $this->year;
    }
}
