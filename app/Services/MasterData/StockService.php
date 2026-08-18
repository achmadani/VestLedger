<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Entities\Stock;
use App\Exceptions\BusinessRuleException;
use App\Models\StockModel;

/**
 * Business logic master saham (§4.2).
 */
class StockService
{
    public function __construct(private StockModel $stocks)
    {
    }

    /**
     * @param array{ticker:string,company_name:string,sector?:?string,notes?:?string,is_active?:int} $data
     */
    public function create(array $data): Stock
    {
        $data = $this->normalise($data);

        $id = $this->stocks->insert($data, true);

        if ($id === false) {
            throw new BusinessRuleException(
                'Saham gagal disimpan.',
                array_values($this->stocks->errors())
            );
        }

        return $this->stocks->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Stock
    {
        if ($this->stocks->find($id) === null) {
            throw new BusinessRuleException('Saham tidak ditemukan.');
        }

        if ($this->stocks->update($id, $this->normalise($data)) === false) {
            throw new BusinessRuleException(
                'Perubahan gagal disimpan.',
                array_values($this->stocks->errors())
            );
        }

        return $this->stocks->find($id);
    }

    /**
     * Merapikan input sebelum divalidasi.
     *
     * Validasi model berjalan LEBIH DULU daripada callback beforeInsert, sehingga
     * "  bbca " akan ditolak aturan alpha_numeric bila tidak dirapikan di sini.
     * Pengguna tidak seharusnya melihat pesan error hanya karena spasi tersalin
     * ikut saat menempel teks.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        if (isset($data['ticker'])) {
            $data['ticker'] = strtoupper(trim((string) $data['ticker']));
        }

        if (isset($data['company_name'])) {
            $data['company_name'] = trim((string) $data['company_name']);
        }

        if (isset($data['sector'])) {
            $sector           = trim((string) $data['sector']);
            $data['sector'] = $sector === '' ? null : $sector;
        }

        return $data;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->stocks->update($id, ['is_active' => $active ? 1 : 0]);
    }

    /**
     * Menghapus saham.
     *
     * Pada Phase 5 pemeriksaan diperluas ke posisi portofolio dan transaksi;
     * saat ini belum ada tabel tersebut, sehingga penghapusan masih bebas.
     * Untuk saham yang sudah pernah ditransaksikan, gunakan nonaktif.
     */
    public function delete(int $id): void
    {
        if ($this->stocks->find($id) === null) {
            throw new BusinessRuleException('Saham tidak ditemukan.');
        }

        $this->stocks->delete($id);
    }
}
