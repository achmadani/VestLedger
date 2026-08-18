<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\BusinessRuleException;

/**
 * Penomoran dokumen: nomor transaksi dan nomor jurnal (§6).
 *
 * Format: PREFIX-YYYYMM-NNNN, mis. STK-202601-0007.
 * Nomor bersifat urut per bulan sehingga mudah dibaca dan dicari manusia.
 */
class DocumentNumberService
{
    public const PREFIX_CASH     = 'CSH';
    public const PREFIX_STOCK    = 'STK';
    public const PREFIX_DIVIDEND = 'DIV';
    public const PREFIX_JOURNAL  = 'JV';

    /**
     * @param string $table  tabel tempat nomor disimpan
     * @param string $column kolom nomor
     */
    public function next(string $prefix, string $date, string $table, string $column): string
    {
        $period = $this->periodPart($date);
        $stem   = sprintf('%s-%s-', $prefix, $period);

        $row = db_connect()->table($table)
            ->select($column)
            ->like($column, $stem, 'after')
            ->orderBy($column, 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $sequence = $row === null ? 1 : ((int) substr((string) $row[$column], -4)) + 1;

        if ($sequence > 9999) {
            throw new BusinessRuleException(
                'Nomor dokumen untuk periode ' . $period . ' sudah melewati batas 9999 per bulan.'
            );
        }

        return $stem . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function periodPart(string $date): string
    {
        return str_replace('-', '', substr($date, 0, 7));
    }
}
