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
    protected $allowedFields  = [
        'ticker', 'company_name', 'sector', 'sub_sector', 'industry', 'sub_industry',
        'sub_industry_code', 'index_membership', 'listing_date', 'listing_board',
        'shares_outstanding', 'market_cap', 'profile_updated_at', 'notes', 'is_active',
    ];
    protected $validationRules = [
        // CodeIgniter mensyaratkan placeholder {id} memiliki aturannya sendiri.
        // Tanpa baris ini, is_unique[...,id,{id}] melempar LogicException.
        'id' => 'permit_empty|is_natural_no_zero',
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

    /**
     * Pencarian saham untuk kotak ketik-cari pada form transaksi.
     *
     * Dengan hampir seribu emiten, dropdown biasa tidak lagi dapat dipakai —
     * dan mengirim seluruh daftar ke browser berarti memuat ratusan kilobyte
     * pada setiap pembukaan form (§34). Pencarian dilakukan di database dan
     * hasilnya dibatasi.
     *
     * Kecocokan pada TICKER didahulukan: pengguna mengetik kode, bukan nama.
     *
     * @return list<array{id:int, ticker:string, company_name:string, sector:?string}>
     */
    public function search(string $query, int $limit = 15): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $like = $this->db->escapeLikeString($query);

        return $this->db->query(
            "SELECT id, ticker, company_name, sector
             FROM stocks
             WHERE is_active = 1 AND deleted_at IS NULL
               AND (ticker LIKE ? ESCAPE '!' OR company_name LIKE ? ESCAPE '!')
             ORDER BY
                CASE WHEN ticker = ? THEN 0
                     WHEN ticker LIKE ? ESCAPE '!' THEN 1
                     ELSE 2 END,
                ticker ASC
             LIMIT ?",
            [$like . '%', '%' . $like . '%', strtoupper($query), $like . '%', $limit]
        )->getResultArray();
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
