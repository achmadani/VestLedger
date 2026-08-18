<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Entities\AccountingPeriod;
use App\Enums\PeriodStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountingPeriodModel;
use DateTimeImmutable;

/**
 * Business logic periode akuntansi (§25).
 *
 * Dua aturan urutan yang ditegakkan di sini menjaga agar buku tidak pernah
 * berlubang:
 *  1. Sebuah periode hanya boleh DITUTUP bila semua periode sebelumnya sudah tertutup.
 *  2. Sebuah periode hanya boleh DIBUKA KEMBALI bila tidak ada periode
 *     setelahnya yang sudah tertutup.
 *
 * Tanpa aturan pertama, laba ditahan periode berjalan dihitung di atas periode
 * yang isinya masih bisa berubah. Tanpa aturan kedua, membuka periode lama
 * mengubah saldo awal periode-periode sesudahnya yang sudah dinyatakan final.
 */
class AccountingPeriodService
{
    public function __construct(private AccountingPeriodModel $periods)
    {
    }

    /**
     * Membuat 12 periode untuk satu tahun. Idempoten — periode yang sudah ada dilewati.
     *
     * @return int jumlah periode yang baru dibuat
     */
    public function generateYear(int $year): int
    {
        if ($year < 2000 || $year > 2199) {
            throw new BusinessRuleException('Tahun periode harus di antara 2000 dan 2199.');
        }

        $db = db_connect();
        $db->transStart();

        $created = 0;

        for ($month = 1; $month <= 12; $month++) {
            $code = sprintf('%04d-%02d', $year, $month);

            if ($this->periods->findByCode($code) !== null) {
                continue;
            }

            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

            $this->periods->insert([
                'code'       => $code,
                'year'       => $year,
                'month'      => $month,
                'start_date' => $start->format('Y-m-d'),
                'end_date'   => $start->modify('last day of this month')->format('Y-m-d'),
                'status'     => PeriodStatus::Open->value,
            ]);

            $created++;
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            throw new BusinessRuleException('Pembuatan periode dibatalkan karena terjadi kegagalan.');
        }

        return $created;
    }

    /**
     * Menutup periode.
     */
    public function close(int $id, ?int $userId = null, ?string $notes = null): AccountingPeriod
    {
        $period = $this->requirePeriod($id);

        if (! $period->isOpen()) {
            throw new BusinessRuleException(sprintf('Periode %s memang sudah tertutup.', $period->displayName()));
        }

        $openEarlier = $this->openPeriodsBefore($period);

        if ($openEarlier !== []) {
            throw new BusinessRuleException(
                sprintf('Periode %s belum dapat ditutup karena masih ada periode sebelumnya yang terbuka.', $period->displayName()),
                array_map(static fn (AccountingPeriod $p): string => $p->displayName() . ' masih terbuka', $openEarlier)
            );
        }

        $this->periods->update($id, [
            'status'    => PeriodStatus::Closed->value,
            'closed_at' => date('Y-m-d H:i:s'),
            'closed_by' => $userId,
            'notes'     => $notes ?? $period->notes,
        ]);

        return $this->periods->find($id);
    }

    /**
     * Membuka kembali periode yang sudah ditutup.
     *
     * Hanya periode tertutup PALING AKHIR yang boleh dibuka, agar periode
     * sesudahnya tidak kehilangan dasar saldo awalnya.
     */
    public function reopen(int $id): AccountingPeriod
    {
        $period = $this->requirePeriod($id);

        if ($period->isOpen()) {
            throw new BusinessRuleException(sprintf('Periode %s memang sedang terbuka.', $period->displayName()));
        }

        $closedLater = $this->closedPeriodsAfter($period);

        if ($closedLater !== []) {
            throw new BusinessRuleException(
                sprintf(
                    'Periode %s tidak dapat dibuka kembali karena sudah ada periode sesudahnya yang tertutup. '
                    . 'Tutup ulang dari yang paling akhir, atau lakukan koreksi lewat jurnal reversal di periode terbuka.',
                    $period->displayName()
                ),
                array_map(static fn (AccountingPeriod $p): string => $p->displayName() . ' sudah tertutup', $closedLater)
            );
        }

        $this->periods->update($id, [
            'status'    => PeriodStatus::Open->value,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return $this->periods->find($id);
    }

    /**
     * Apakah sebuah tanggal transaksi boleh menerima posting.
     *
     * Dipakai Phase 3 sebelum menyimpan transaksi apa pun.
     */
    public function assertDateIsPostable(string $date): void
    {
        $period = $this->periods->findForDate($date);

        if ($period === null) {
            throw new BusinessRuleException(sprintf(
                'Belum ada periode akuntansi yang memuat tanggal %s. Buat periode untuk tahun %s lebih dulu.',
                $date,
                substr($date, 0, 4)
            ));
        }

        if (! $period->status()->acceptsPostings()) {
            throw new BusinessRuleException(sprintf(
                'Periode %s sudah ditutup, sehingga tidak dapat menerima transaksi bertanggal %s. '
                . 'Gunakan jurnal koreksi di periode terbuka.',
                $period->displayName(),
                $date
            ));
        }
    }

    /**
     * @return list<AccountingPeriod>
     */
    private function openPeriodsBefore(AccountingPeriod $period): array
    {
        return $this->periods
            ->where('status', PeriodStatus::Open->value)
            ->groupStart()
            ->where('year <', $period->year)
            ->orGroupStart()
            ->where('year', $period->year)
            ->where('month <', $period->month)
            ->groupEnd()
            ->groupEnd()
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->findAll();
    }

    /**
     * @return list<AccountingPeriod>
     */
    private function closedPeriodsAfter(AccountingPeriod $period): array
    {
        return $this->periods
            ->where('status', PeriodStatus::Closed->value)
            ->groupStart()
            ->where('year >', $period->year)
            ->orGroupStart()
            ->where('year', $period->year)
            ->where('month >', $period->month)
            ->groupEnd()
            ->groupEnd()
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->findAll();
    }

    private function requirePeriod(int $id): AccountingPeriod
    {
        $period = $this->periods->find($id);

        if ($period === null) {
            throw new BusinessRuleException('Periode akuntansi tidak ditemukan.');
        }

        return $period;
    }
}
