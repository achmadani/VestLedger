<?php

declare(strict_types=1);

namespace App\Controllers\Master;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use App\Models\StockModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Master saham (§4.2).
 *
 * Pencarian, filter, dan paginasi dikerjakan di sisi database (§32) — daftar
 * saham berpotensi panjang dan tidak boleh dikirim seluruhnya ke browser.
 */
class Stocks extends BaseController
{
    use HandlesBusinessRules;

    private StockModel $stocks;

    public function __construct()
    {
        $this->stocks = new StockModel();
    }

    public function index(): string
    {
        $search = trim((string) $this->request->getGet('q'));
        $sector = trim((string) $this->request->getGet('sector'));
        $status = trim((string) $this->request->getGet('status'));

        $builder = $this->stocks;

        if ($search !== '') {
            $builder = $builder->groupStart()
                ->like('ticker', $search)
                ->orLike('company_name', $search)
                ->groupEnd();
        }

        if ($sector !== '') {
            $builder = $builder->where('sector', $sector);
        }

        if ($status === 'active') {
            $builder = $builder->where('is_active', 1);
        } elseif ($status === 'inactive') {
            $builder = $builder->where('is_active', 0);
        }

        $perPage = config(\Config\Pager::class)->perPage;

        return view('master/stocks/index', [
            'pageTitle' => 'Saham',
            'stocks'    => $builder->orderBy('ticker', 'asc')->paginate($perPage),
            'pager'     => $this->stocks->pager,
            'sectors'   => (new StockModel())->sectors(),
            'filters'   => ['q' => $search, 'sector' => $sector, 'status' => $status],
        ]);
    }

    public function new(): string
    {
        return view('master/stocks/form', [
            'pageTitle' => 'Tambah Saham',
            'stock'     => null,
            'sectors'   => $this->stocks->sectors(),
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            $stock = service('stockService')->create($this->payload());
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/stocks/new');
        }

        return redirect()->to('/master/stocks')
            ->with('success', 'Saham ' . $stock->displayName() . ' berhasil ditambahkan.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        $stock = $this->stocks->find($id);

        if ($stock === null) {
            return redirect()->to('/master/stocks')->with('error', 'Saham tidak ditemukan.');
        }

        return view('master/stocks/form', [
            'pageTitle' => 'Ubah ' . $stock->displayName(),
            'stock'     => $stock,
            'sectors'   => $this->stocks->sectors(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            service('stockService')->update($id, $this->payload());
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/stocks/' . $id . '/edit');
        }

        return redirect()->to('/master/stocks')->with('success', 'Saham berhasil diperbarui.');
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            service('stockService')->delete($id);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/stocks');
        }

        return redirect()->to('/master/stocks')->with('success', 'Saham berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'ticker'       => (string) $this->request->getPost('ticker'),
            'company_name' => (string) $this->request->getPost('company_name'),
            'sector'       => $this->request->getPost('sector') ?: null,
            'notes'        => $this->request->getPost('notes') ?: null,
            'is_active'    => $this->request->getPost('is_active') !== null ? 1 : 0,
        ];
    }
}
