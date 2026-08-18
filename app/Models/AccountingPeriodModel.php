<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\AccountingPeriod;
use App\Enums\PeriodStatus;
use CodeIgniter\Model;

class AccountingPeriodModel extends Model
{
    protected $table         = 'accounting_periods';
    protected $primaryKey    = 'id';
    protected $returnType    = AccountingPeriod::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'code', 'year', 'month', 'start_date', 'end_date',
        'status', 'closed_at', 'closed_by', 'notes',
    ];
    protected $validationRules = [
        'code'       => 'required|exact_length[7]|is_unique[accounting_periods.code,id,{id}]',
        'year'       => 'required|is_natural_no_zero|greater_than[1999]|less_than[2200]',
        'month'      => 'required|is_natural_no_zero|less_than_equal_to[12]',
        'start_date' => 'required|valid_date[Y-m-d]',
        'end_date'   => 'required|valid_date[Y-m-d]',
        'status'     => 'required|in_list[open,closed]',
        'notes'      => 'permit_empty|max_length[2000]',
    ];

    /**
     * @return list<AccountingPeriod>
     */
    public function forYear(int $year): array
    {
        return $this->where('year', $year)->orderBy('month', 'asc')->findAll();
    }

    public function findByCode(string $code): ?AccountingPeriod
    {
        return $this->where('code', $code)->first();
    }

    /**
     * Periode yang memuat sebuah tanggal transaksi.
     *
     * Dipakai Phase 3 untuk menolak transaksi yang jatuh pada periode tertutup.
     */
    public function findForDate(string $date): ?AccountingPeriod
    {
        return $this->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->first();
    }

    /**
     * Tahun-tahun yang sudah memiliki periode, terbaru lebih dulu.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $rows = $this->select('year')->distinct()->orderBy('year', 'desc')->findColumn('year');

        return array_map('intval', $rows ?? []);
    }

    /**
     * Periode tertutup paling akhir, untuk memvalidasi urutan buka/tutup.
     */
    public function latestClosed(): ?AccountingPeriod
    {
        return $this->where('status', PeriodStatus::Closed->value)
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->first();
    }

    /**
     * Periode terbuka paling awal — batas bawah yang masih boleh menerima posting.
     */
    public function earliestOpen(): ?AccountingPeriod
    {
        return $this->where('status', PeriodStatus::Open->value)
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->first();
    }
}
