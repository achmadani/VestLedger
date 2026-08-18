<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\Security;
use CodeIgniter\Model;

class SecurityModel extends Model
{
    protected $table            = 'securities';
    protected $primaryKey       = 'id';
    protected $returnType       = Security::class;
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $allowedFields    = ['code', 'name', 'buy_fee_percent', 'sell_fee_percent', 'notes', 'is_active'];
    protected $validationRules  = [
        // CodeIgniter mensyaratkan placeholder {id} memiliki aturannya sendiri.
        // Tanpa baris ini, is_unique[...,id,{id}] melempar LogicException.
        'id' => 'permit_empty|is_natural_no_zero',
        'code'      => 'required|max_length[20]|alpha_numeric_punct|is_unique[securities.code,id,{id}]',
        'name'      => 'required|max_length[100]',
        'notes'     => 'permit_empty|max_length[2000]',
        'buy_fee_percent'  => 'permit_empty|decimal|greater_than_equal_to[0]|less_than[100]',
        'sell_fee_percent' => 'permit_empty|decimal|greater_than_equal_to[0]|less_than[100]',
        'is_active' => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages = [
        'code' => [
            'required'  => 'Kode sekuritas wajib diisi.',
            'is_unique' => 'Kode sekuritas ini sudah dipakai.',
        ],
        'name' => ['required' => 'Nama sekuritas wajib diisi.'],
    ];
    protected $beforeInsert = ['normalise'];
    protected $beforeUpdate = ['normalise'];

    /**
     * Kode sekuritas selalu huruf besar tanpa spasi tepi, sehingga "ajaib"
     * dan "AJAIB" tidak bisa masuk sebagai dua sekuritas berbeda.
     */
    protected function normalise(array $data): array
    {
        if (isset($data['data']['code'])) {
            $data['data']['code'] = strtoupper(trim($data['data']['code']));
        }

        if (isset($data['data']['name'])) {
            $data['data']['name'] = trim($data['data']['name']);
        }

        return $data;
    }

    /**
     * @return list<Security>
     */
    public function active(): array
    {
        return $this->where('is_active', 1)->orderBy('name', 'asc')->findAll();
    }

    /**
     * Daftar untuk dropdown: [id => "KODE — Nama"].
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->active() as $security) {
            $options[$security->id] = $security->displayName();
        }

        return $options;
    }

    public function findByCode(string $code): ?Security
    {
        return $this->where('code', strtoupper(trim($code)))->first();
    }
}
