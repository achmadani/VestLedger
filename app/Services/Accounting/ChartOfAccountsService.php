<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Entities\Account;
use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountModel;

/**
 * Business logic Chart of Accounts (§9).
 *
 * Tugas utamanya adalah melindungi akun inti. Seluruh mesin jurnal merujuk akun
 * lewat App\Enums\AccountCode; bila salah satu akun inti dihapus, dinonaktifkan,
 * atau kodenya diubah, pembuatan jurnal akan gagal di tengah transaksi.
 */
class ChartOfAccountsService
{
    public function __construct(private AccountModel $accounts)
    {
    }

    /**
     * @param array{code:string,name:string,type:string,normal_balance?:string,parent_id?:?int,is_postable?:int,description?:?string,is_active?:int} $data
     */
    public function create(array $data): Account
    {
        $data = $this->applyDefaults($data);

        // Akun baru buatan pengguna tidak pernah berstatus akun inti.
        $data['is_system'] = 0;

        $this->guardParent($data['parent_id'] ?? null, null);

        $id = $this->accounts->insert($data, true);

        if ($id === false) {
            throw new BusinessRuleException(
                'Akun gagal disimpan.',
                array_values($this->accounts->errors())
            );
        }

        return $this->accounts->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Account
    {
        $account = $this->accounts->find($id);

        if ($account === null) {
            throw new BusinessRuleException('Akun tidak ditemukan.');
        }

        if ($account->is_system) {
            // Nama dan deskripsi boleh disesuaikan; identitas akuntansinya tidak.
            foreach (['code', 'type', 'normal_balance', 'is_postable'] as $locked) {
                if (isset($data[$locked]) && (string) $data[$locked] !== (string) $account->{$locked}) {
                    throw new BusinessRuleException(sprintf(
                        'Akun inti %s tidak boleh diubah %s-nya, karena dirujuk langsung oleh mesin jurnal.',
                        $account->displayName(),
                        $locked === 'code' ? 'kode' : ($locked === 'type' ? 'tipe' : 'sifatnya')
                    ));
                }
            }

            if (isset($data['is_active']) && (int) $data['is_active'] === 0) {
                throw new BusinessRuleException(sprintf(
                    'Akun inti %s tidak boleh dinonaktifkan. Setiap transaksi yang membutuhkannya akan gagal dijurnal.',
                    $account->displayName()
                ));
            }

            unset($data['code'], $data['type'], $data['normal_balance'], $data['is_postable'], $data['is_system']);
        }

        $this->guardParent($data['parent_id'] ?? null, $id);

        if ($this->accounts->update($id, $data) === false) {
            throw new BusinessRuleException(
                'Perubahan gagal disimpan.',
                array_values($this->accounts->errors())
            );
        }

        return $this->accounts->find($id);
    }

    /**
     * Menghapus akun.
     *
     * Ditolak untuk akun inti dan akun yang masih memiliki sub-akun. Pada
     * Phase 4 pemeriksaan diperluas: akun yang sudah pernah dipakai di baris
     * jurnal tidak boleh dihapus sama sekali (§40.8).
     */
    public function delete(int $id): void
    {
        $account = $this->accounts->find($id);

        if ($account === null) {
            throw new BusinessRuleException('Akun tidak ditemukan.');
        }

        if ($account->is_system) {
            throw new BusinessRuleException(sprintf(
                'Akun inti %s tidak dapat dihapus karena dirujuk langsung oleh mesin jurnal.',
                $account->displayName()
            ));
        }

        $children = $this->accounts->countChildren($id);

        if ($children > 0) {
            throw new BusinessRuleException(sprintf(
                'Akun %s masih memiliki %d sub-akun. Pindahkan atau hapus sub-akun tersebut lebih dulu.',
                $account->displayName(),
                $children
            ));
        }

        $this->accounts->delete($id);
    }

    /**
     * Membuat/menyegarkan seluruh akun inti sesuai App\Enums\AccountCode.
     *
     * Idempoten: akun yang sudah ada hanya ditandai ulang sebagai akun inti,
     * tanpa menimpa nama yang mungkin sudah disesuaikan pengguna.
     *
     * @return array{created:int, marked:int}
     */
    public function ensureSystemAccounts(): array
    {
        $created = 0;
        $marked  = 0;

        foreach (AccountCode::cases() as $code) {
            $existing = $this->accounts->findByCode($code->value);

            if ($existing === null) {
                $this->accounts->insert([
                    'code'           => $code->value,
                    'name'           => $code->label(),
                    'type'           => $code->type()->value,
                    'normal_balance' => $code->normalBalance()->value,
                    'is_postable'    => 1,
                    'is_system'      => 1,
                    'is_active'      => 1,
                    'description'    => $this->systemAccountNote($code),
                ]);
                $created++;

                continue;
            }

            if (! $existing->is_system) {
                $this->accounts->update($existing->id, ['is_system' => 1]);
                $marked++;
            }
        }

        return ['created' => $created, 'marked' => $marked];
    }

    /**
     * Memastikan akun inti lengkap dan aktif.
     *
     * Dipanggil sebagai pemeriksaan kesehatan sebelum aplikasi mulai membuat
     * jurnal (Phase 4), sehingga masalah konfigurasi CoA ketahuan lebih awal.
     *
     * @return list<string> daftar masalah; kosong berarti sehat
     */
    public function verifySystemAccounts(): array
    {
        $problems = [];

        foreach (AccountCode::cases() as $code) {
            $account = $this->accounts->findByCode($code->value);

            if ($account === null) {
                $problems[] = sprintf('Akun inti %s (%s) belum ada.', $code->value, $code->label());

                continue;
            }

            if (! $account->is_active) {
                $problems[] = sprintf('Akun inti %s (%s) berstatus nonaktif.', $code->value, $account->name);
            }

            if (! $account->is_postable) {
                $problems[] = sprintf('Akun inti %s (%s) tidak dapat menerima jurnal.', $code->value, $account->name);
            }

            if ($account->type() !== $code->type()) {
                $problems[] = sprintf(
                    'Akun inti %s bertipe %s, seharusnya %s.',
                    $code->value,
                    $account->type()->label(),
                    $code->type()->label()
                );
            }

            if ($account->normalBalance() !== $code->normalBalance()) {
                $problems[] = sprintf(
                    'Akun inti %s bersaldo normal %s, seharusnya %s.',
                    $code->value,
                    $account->normalBalance()->label(),
                    $code->normalBalance()->label()
                );
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function applyDefaults(array $data): array
    {
        // Saldo normal mengikuti tipe akun bila tidak ditentukan eksplisit.
        // Pengguna tetap dapat memilih sisi berlawanan untuk membuat akun kontra.
        if (empty($data['normal_balance']) && ! empty($data['type'])) {
            $data['normal_balance'] = AccountType::from($data['type'])->normalBalance()->value;
        }

        if (($data['parent_id'] ?? '') === '') {
            $data['parent_id'] = null;
        }

        return $data;
    }

    /**
     * Mencegah akun menjadi induk dirinya sendiri maupun membentuk siklus.
     */
    private function guardParent(?int $parentId, ?int $selfId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($selfId !== null && $parentId === $selfId) {
            throw new BusinessRuleException('Akun tidak boleh menjadi induk bagi dirinya sendiri.');
        }

        $parent = $this->accounts->find($parentId);

        if ($parent === null) {
            throw new BusinessRuleException('Akun induk yang dipilih tidak ditemukan.');
        }

        // Telusuri ke atas; bila bertemu diri sendiri berarti terbentuk siklus.
        $seen    = [];
        $current = $parent;

        while ($current !== null && $current->parent_id !== null) {
            if ($selfId !== null && $current->parent_id === $selfId) {
                throw new BusinessRuleException('Hubungan induk-anak ini membentuk lingkaran.');
            }

            if (isset($seen[$current->parent_id])) {
                break;
            }

            $seen[$current->parent_id] = true;
            $current                   = $this->accounts->find($current->parent_id);
        }
    }

    private function systemAccountNote(AccountCode $code): string
    {
        return match ($code) {
            AccountCode::Cash => 'Saldo kas/RDN. Dibedakan per sekuritas lewat dimensi pada baris jurnal, bukan lewat akun terpisah.',
            AccountCode::StockPortfolio => 'Book value saham, sudah termasuk seluruh biaya perolehan.',
            AccountCode::PaidInCapital => 'Akumulasi bruto seluruh top up. Bukan pendapatan.',
            AccountCode::RetainedEarnings => 'Akumulasi laba periode-periode sebelumnya.',
            AccountCode::OwnerWithdrawal => 'Akun kontra-ekuitas berisi akumulasi bruto withdrawal. Bukan beban.',
            AccountCode::RealizedGain => 'Selisih lebih harga jual bruto atas book value yang dilepas.',
            AccountCode::RealizedLoss => 'Selisih kurang harga jual bruto terhadap book value yang dilepas.',
            AccountCode::DividendIncome => 'Dividen bruto sebelum pajak.',
            AccountCode::BrokerFee => 'Fee transaksi jual dan biaya broker lain. Fee pembelian dikapitalisasi ke book cost.',
            AccountCode::AdministrativeExpense => 'Biaya administrasi rekening dan sejenisnya.',
            AccountCode::TaxAndLevy => 'Pajak dan levy sisi jual serta pajak dividen.',
        };
    }
}
