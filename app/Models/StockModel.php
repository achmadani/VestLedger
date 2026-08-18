<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\Stock;
use CodeIgniter\Model;

class StockModel extends Model
{
    protected $table          = 'stocks';
    protected $primaryKey     = 'id';
    protected $returnType     = Stock::class;
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = ['ticker', 'company_name', 'sector', 'notes', 'is_active'];
    protected $validationRules = [
        'ticker'       => 'required|min_length[2]|max_length[10]|alpha_numeric|is_unique[stocks.ticker,id,{id}]',
        'company_name' => 'required|max_length[150]',
        'sector'       => 'permit_empty|max_length[80]',
        'notes'        => 'permit_empty|max_length[2000]',
        'is_active'    => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages = [
        'ticker' => [
            'required'      => 'Ticker wajib diisi.',
            'alpha_numeric' => 'Ticker hanya boleh berisi huruf dan angka.',
            'is_unique'     => 'Ticker ini sudah terdaftar.',
        ],
        'company_name' => ['required' => 'Nama perusahaan wajib diisi.'],
    ];
    protected $beforeInsert = ['normalise'];
    protected $beforeUpdate = ['normalise'];

    protected function normalise(array $data): array
    {
        if (isset($data['data']['ticker'])) {
            $data['data']['ticker'] = strtoupper(trim($data['data']['ticker']));
        }

        if (isset($data['data']['sector'])) {
            $sector                 = trim((string) $data['data']['sector']);
            $data['data']['sector'] = $sector === '' ? null : $sector;
        }

        return $data;
    }

    /**
     * @return list<Stock>
     */
    public function active(): array
    {
        return $this->where('is_active', 1)->orderBy('ticker', 'asc')->findAll();
    }

    /**
     * @return array<int, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->active() as $stock) {
            $options[$stock->id] = $stock->displayName();
        }

        return $options;
    }

    public function findByTicker(string $ticker): ?Stock
    {
        return $this->where('ticker', strtoupper(trim($ticker)))->first();
    }

    /**
     * Daftar sektor yang sudah dipakai, untuk autocomplete form.
     *
     * @return list<string>
     */
    public function sectors(): array
    {
        $rows = $this->select('sector')
            ->where('sector IS NOT NULL')
            ->distinct()
            ->orderBy('sector', 'asc')
            ->findColumn('sector');

        return array_values(array_filter($rows ?? []));
    }
}
