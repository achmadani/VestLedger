<?php

declare(strict_types=1);

namespace App\Controllers\Transactions;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\Repositories\TransactionHistoryRepository;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Daftar seluruh transaksi dan pembatalannya (§22, §26, §32).
 */
class Index extends BaseController
{
    use HandlesBusinessRules;

    public function index(): string
    {
        $filters = [
            'from'                  => trim((string) $this->request->getGet('from')),
            'to'                    => trim((string) $this->request->getGet('to')),
            'kind'                  => trim((string) $this->request->getGet('kind')),
            'securities_account_id' => (int) $this->request->getGet('securities_account_id'),
            'stock_id'              => (int) $this->request->getGet('stock_id'),
            'status'                => trim((string) $this->request->getGet('status')),
            'q'                     => trim((string) $this->request->getGet('q')),
        ];

        $perPage = config(\Config\Pager::class)->perPage;
        $page    = max(1, (int) ($this->request->getGet('page') ?: 1));

        $result = (new TransactionHistoryRepository())->paginate($filters, $perPage, $page);

        // Pager dibangun manual karena daftarnya berasal dari UNION tiga tabel,
        // bukan dari satu model.
        $pager = service('pager');
        $pager->makeLinks($page, $perPage, $result['total']);

        return view('transactions/index', [
            'pageTitle' => 'Semua Transaksi',
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'perPage'   => $perPage,
            'pager'     => $pager,
            'filters'   => $filters,
            'accounts'  => (new SecuritiesAccountModel())->options(),
            'stocks'    => (new StockModel())->options(),
        ]);
    }

    /**
     * Pembatalan transaksi (§26). Tidak pernah menghapus — selalu jurnal pembalik.
     */
    public function reverse(string $kind, int $id): RedirectResponse
    {
        $reason = trim((string) $this->request->getPost('reason')) ?: null;

        try {
            match ($kind) {
                'cash'     => service('reversals')->reverseCash($id, null, $reason),
                'stock'    => service('reversals')->reverseStock($id, null, $reason),
                'dividend' => service('reversals')->reverseDividend($id, null, $reason),
                default    => throw new BusinessRuleException('Jenis transaksi tidak dikenali.'),
            };
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/transactions');
        }

        return redirect()->to('/transactions')
            ->with('success', 'Transaksi dibatalkan lewat jurnal pembalik. Data aslinya tetap tersimpan.');
    }
}
