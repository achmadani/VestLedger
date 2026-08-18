<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Entities\Security;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\SecurityModel;

/**
 * Business logic master sekuritas dan rekening/RDN-nya (§4.1, §5).
 */
class SecurityService
{
    public function __construct(
        private SecurityModel $securities,
        private SecuritiesAccountModel $accounts,
    ) {
    }

    /**
     * Membuat sekuritas beserta satu rekening awal.
     *
     * Rekening dibuat sekaligus karena transaksi selalu merujuk REKENING, bukan
     * broker. Tanpa ini, sekuritas baru tidak akan pernah bisa dipakai
     * bertransaksi dan pengguna harus menebak langkah kedua yang tersembunyi.
     *
     * @param array{code:string,name:string,notes?:?string,is_active?:int} $data
     * @param array{label?:string,account_number?:?string,bank_name?:?string,opened_at?:?string} $accountData
     */
    public function create(array $data, array $accountData = []): Security
    {
        $data = $this->normalise($data);

        // Transaksi dikendalikan manual, BUKAN transStart()/transComplete().
        // transComplete() hanya melakukan rollback bila terjadi error DATABASE;
        // kegagalan VALIDASI model tidak terdeteksi olehnya dan justru ikut
        // ter-commit — meninggalkan sekuritas tanpa rekening (§8, §40.7).
        $db = db_connect();
        $db->transBegin();

        try {
            $id = $this->securities->insert($data, true);

            if ($id === false) {
                throw new BusinessRuleException(
                    'Sekuritas gagal disimpan.',
                    $this->flattenErrors($this->securities->errors())
                );
            }

            $accountData['securities_id'] = $id;
            $accountData['label'] = trim((string) ($accountData['label'] ?? '')) ?: 'RDN Utama';

            if ($this->accounts->insert($accountData) === false) {
                throw new BusinessRuleException(
                    'Sekuritas gagal disimpan karena rekening awalnya tidak valid.',
                    $this->flattenErrors($this->accounts->errors())
                );
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return $this->securities->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Security
    {
        $security = $this->securities->find($id);

        if ($security === null) {
            throw new BusinessRuleException('Sekuritas tidak ditemukan.');
        }

        if ($this->securities->update($id, $this->normalise($data)) === false) {
            throw new BusinessRuleException(
                'Perubahan gagal disimpan.',
                $this->flattenErrors($this->securities->errors())
            );
        }

        return $this->securities->find($id);
    }

    /**
     * Menonaktifkan sekuritas beserta seluruh rekeningnya.
     *
     * Sekuritas nonaktif tidak muncul di form transaksi baru, tetapi seluruh
     * histori transaksinya tetap utuh dan tetap tampil di laporan — inilah
     * alasan sistem memakai nonaktif, bukan hapus.
     */
    public function deactivate(int $id): void
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $this->securities->update($id, ['is_active' => 0]);
            $this->accounts->where('securities_id', $id)->set(['is_active' => 0])->update();

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    public function activate(int $id): void
    {
        $this->securities->update($id, ['is_active' => 1]);
    }

    /**
     * Menghapus sekuritas.
     *
     * Hanya diizinkan bila belum punya rekening sama sekali — artinya belum
     * mungkin ada transaksi yang menempel padanya. Selain itu, gunakan nonaktif.
     */
    public function delete(int $id): void
    {
        $accountCount = $this->accounts->countForSecurities($id);

        if ($accountCount > 0) {
            throw new BusinessRuleException(
                'Sekuritas ini tidak dapat dihapus karena masih memiliki ' . $accountCount . ' rekening. '
                . 'Nonaktifkan sekuritas agar histori transaksinya tetap utuh.'
            );
        }

        $this->securities->delete($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addAccount(int $securitiesId, array $data): void
    {
        $data['securities_id'] = $securitiesId;

        if ($this->accounts->insert($data) === false) {
            throw new BusinessRuleException(
                'Rekening gagal disimpan.',
                $this->flattenErrors($this->accounts->errors())
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateAccount(int $accountId, array $data): void
    {
        unset($data['securities_id']); // rekening tidak boleh berpindah sekuritas

        if ($this->accounts->update($accountId, $data) === false) {
            throw new BusinessRuleException(
                'Rekening gagal diperbarui.',
                $this->flattenErrors($this->accounts->errors())
            );
        }
    }

    /**
     * Merapikan input sebelum divalidasi model.
     *
     * Validasi berjalan lebih dulu daripada callback beforeInsert, sehingga
     * spasi tepi harus dibuang di sini agar tidak memicu pesan error yang
     * membingungkan.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }

        return $data;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<string>
     */
    private function flattenErrors(array $errors): array
    {
        return array_values($errors);
    }
}
