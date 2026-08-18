<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\Account;
use App\Enums\AccountCode;
use App\Enums\AccountType;
use CodeIgniter\Model;

class AccountModel extends Model
{
    protected $table          = 'accounts';
    protected $primaryKey     = 'id';
    protected $returnType     = Account::class;
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'code', 'name', 'type', 'normal_balance', 'parent_id',
        'is_postable', 'is_system', 'description', 'is_active',
    ];
    protected $validationRules = [
        // CodeIgniter mensyaratkan placeholder {id} memiliki aturannya sendiri.
        // Tanpa baris ini, is_unique[...,id,{id}] melempar LogicException.
        'id' => 'permit_empty|is_natural_no_zero',
        'code'           => 'required|max_length[20]|alpha_numeric_punct|is_unique[accounts.code,id,{id}]',
        'name'           => 'required|max_length[120]',
        'type'           => 'required|in_list[asset,liability,equity,revenue,expense]',
        'normal_balance' => 'required|in_list[debit,credit]',
        'parent_id'      => 'permit_empty|is_natural_no_zero',
        'is_postable'    => 'permit_empty|in_list[0,1]',
        'description'    => 'permit_empty|max_length[2000]',
        'is_active'      => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages = [
        'code' => [
            'required'  => 'Kode akun wajib diisi.',
            'is_unique' => 'Kode akun ini sudah dipakai.',
        ],
        'name' => ['required' => 'Nama akun wajib diisi.'],
        'type' => ['required' => 'Tipe akun wajib dipilih.'],
    ];

    /**
     * Cache kode akun -> id, agar pencarian akun pada pembuatan jurnal (Phase 4)
     * tidak menembak database berulang kali untuk transaksi yang sama.
     *
     * @var array<string, int>|null
     */
    private ?array $codeIdMap = null;

    /**
     * @return list<Account>
     */
    public function ordered(): array
    {
        return $this->orderBy('code', 'asc')->findAll();
    }

    /**
     * Akun dikelompokkan menurut tipe, dalam urutan penyajian laporan
     * (Aset, Kewajiban, Ekuitas, Pendapatan, Beban).
     *
     * @return array<string, list<Account>>
     */
    public function groupedByType(): array
    {
        $grouped = [];

        foreach (AccountType::cases() as $type) {
            $grouped[$type->value] = [];
        }

        foreach ($this->ordered() as $account) {
            $grouped[$account->type][] = $account;
        }

        return $grouped;
    }

    public function findByCode(string $code): ?Account
    {
        return $this->where('code', trim($code))->first();
    }

    /**
     * ID akun untuk sebuah AccountCode.
     *
     * Dipakai oleh service akuntansi agar jurnal tidak pernah merujuk kode akun
     * sebagai string literal (§40.6).
     *
     * @throws \RuntimeException bila akun inti hilang dari database
     */
    public function idFor(AccountCode $code): int
    {
        if ($this->codeIdMap === null) {
            $this->codeIdMap = [];

            foreach ($this->where('is_system', 1)->findAll() as $account) {
                $this->codeIdMap[$account->code] = $account->id;
            }
        }

        if (! isset($this->codeIdMap[$code->value])) {
            throw new \RuntimeException(sprintf(
                'Akun inti %s (%s) tidak ditemukan. Jalankan `php spark db:seed ChartOfAccountsSeeder`.',
                $code->value,
                $code->label()
            ));
        }

        return $this->codeIdMap[$code->value];
    }

    /**
     * Dropdown akun yang boleh menerima baris jurnal.
     *
     * @return array<int, string>
     */
    public function postableOptions(): array
    {
        $options = [];

        $accounts = $this->where('is_active', 1)
            ->where('is_postable', 1)
            ->orderBy('code', 'asc')
            ->findAll();

        foreach ($accounts as $account) {
            $options[$account->id] = $account->displayName();
        }

        return $options;
    }

    /**
     * Kandidat parent: akun aktif mana pun kecuali dirinya sendiri.
     *
     * @return array<int, string>
     */
    public function parentOptions(?int $excludeId = null): array
    {
        $options = [];

        foreach ($this->where('is_active', 1)->orderBy('code', 'asc')->findAll() as $account) {
            if ($excludeId !== null && $account->id === $excludeId) {
                continue;
            }

            $options[$account->id] = $account->displayName();
        }

        return $options;
    }

    public function countChildren(int $id): int
    {
        return $this->where('parent_id', $id)->countAllResults();
    }
}
