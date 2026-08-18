<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\SecuritiesAccount;
use CodeIgniter\Model;

class SecuritiesAccountModel extends Model
{
    protected $table          = 'securities_accounts';
    protected $primaryKey     = 'id';
    protected $returnType     = SecuritiesAccount::class;
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'securities_id', 'label', 'account_number', 'bank_name',
        'opened_at', 'notes', 'is_active',
    ];
    protected $validationRules = [
        'securities_id'  => 'required|is_natural_no_zero|is_not_unique[securities.id]',
        'label'          => 'required|max_length[100]',
        'account_number' => 'permit_empty|max_length[60]',
        'bank_name'      => 'permit_empty|max_length[100]',
        'opened_at'      => 'permit_empty|valid_date[Y-m-d]',
        'notes'          => 'permit_empty|max_length[2000]',
        'is_active'      => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages = [
        'securities_id' => [
            'required'      => 'Sekuritas wajib dipilih.',
            'is_not_unique' => 'Sekuritas yang dipilih tidak ditemukan.',
        ],
        'label' => ['required' => 'Nama rekening wajib diisi.'],
    ];

    /**
     * Rekening beserta identitas sekuritasnya.
     *
     * Satu query dengan join — bukan N+1 (§34).
     *
     * @return list<SecuritiesAccount>
     */
    public function withSecurities(bool $activeOnly = false): array
    {
        $builder = $this
            ->select('securities_accounts.*, securities.code AS securities_code, securities.name AS securities_name')
            ->join('securities', 'securities.id = securities_accounts.securities_id')
            ->where('securities.deleted_at', null);

        if ($activeOnly) {
            $builder->where('securities_accounts.is_active', 1)
                ->where('securities.is_active', 1);
        }

        return $builder->orderBy('securities.name', 'asc')
            ->orderBy('securities_accounts.label', 'asc')
            ->findAll();
    }

    /**
     * Dropdown rekening aktif: [id => "AJAIB — RDN Utama"].
     *
     * Inilah daftar yang dipakai seluruh form transaksi pada Phase 3.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->withSecurities(true) as $account) {
            $options[$account->id] = $account->displayName();
        }

        return $options;
    }

    /**
     * @return list<SecuritiesAccount>
     */
    public function forSecurities(int $securitiesId): array
    {
        return $this->where('securities_id', $securitiesId)
            ->orderBy('label', 'asc')
            ->findAll();
    }

    public function countForSecurities(int $securitiesId): int
    {
        return $this->where('securities_id', $securitiesId)->countAllResults();
    }
}
