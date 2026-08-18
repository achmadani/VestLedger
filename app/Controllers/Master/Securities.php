<?php

declare(strict_types=1);

namespace App\Controllers\Master;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\SecurityModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Master sekuritas dan rekening/RDN-nya (§4.1, §5).
 */
class Securities extends BaseController
{
    use HandlesBusinessRules;

    private SecurityModel $securities;
    private SecuritiesAccountModel $accounts;

    public function __construct()
    {
        $this->securities = new SecurityModel();
        $this->accounts   = new SecuritiesAccountModel();
    }

    public function index(): string
    {
        $securities = $this->securities->orderBy('is_active', 'desc')->orderBy('name', 'asc')->findAll();

        // Hitung rekening untuk semua sekuritas dalam SATU query, bukan per baris (§34).
        $counts = $this->accounts
            ->select('securities_id, COUNT(*) AS total')
            ->groupBy('securities_id')
            ->findAll();

        $accountCounts = [];

        foreach ($counts as $row) {
            $accountCounts[(int) $row->securities_id] = (int) $row->total;
        }

        return view('master/securities/index', [
            'pageTitle'     => 'Sekuritas',
            'securities'    => $securities,
            'accountCounts' => $accountCounts,
        ]);
    }

    public function new(): string
    {
        return view('master/securities/form', [
            'pageTitle' => 'Tambah Sekuritas',
            'security'  => null,
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            $security = service('securityService')->create(
                $this->payload(),
                [
                    'label'          => (string) ($this->request->getPost('account_label') ?: 'RDN Utama'),
                    'account_number' => $this->request->getPost('account_number') ?: null,
                    'bank_name'      => $this->request->getPost('bank_name') ?: null,
                ]
            );
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/securities/new');
        }

        return redirect()->to('/master/securities/' . $security->id)
            ->with('success', 'Sekuritas ' . $security->displayName() . ' berhasil dibuat beserta rekening awalnya.');
    }

    public function show(int $id): string|RedirectResponse
    {
        $security = $this->securities->find($id);

        if ($security === null) {
            return redirect()->to('/master/securities')->with('error', 'Sekuritas tidak ditemukan.');
        }

        return view('master/securities/show', [
            'pageTitle' => $security->displayName(),
            'security'  => $security,
            'accounts'  => $this->accounts->forSecurities($id),
        ]);
    }

    public function edit(int $id): string|RedirectResponse
    {
        $security = $this->securities->find($id);

        if ($security === null) {
            return redirect()->to('/master/securities')->with('error', 'Sekuritas tidak ditemukan.');
        }

        return view('master/securities/form', [
            'pageTitle' => 'Ubah ' . $security->displayName(),
            'security'  => $security,
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            service('securityService')->update($id, $this->payload());
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/securities/' . $id . '/edit');
        }

        return redirect()->to('/master/securities/' . $id)->with('success', 'Sekuritas berhasil diperbarui.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $config = config(\Config\Investment::class);

        return [
            'code'             => (string) $this->request->getPost('code'),
            'name'             => (string) $this->request->getPost('name'),
            'buy_fee_percent'  => $this->request->getPost('buy_fee_percent') ?: $config->defaultBuyFeePercent,
            'sell_fee_percent' => $this->request->getPost('sell_fee_percent') ?: $config->defaultSellFeePercent,
            'notes'            => $this->request->getPost('notes') ?: null,
            'is_active'        => $this->request->getPost('is_active') !== null ? 1 : 0,
        ];
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            service('securityService')->delete($id);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/securities');
        }

        return redirect()->to('/master/securities')->with('success', 'Sekuritas berhasil dihapus.');
    }

    public function deactivate(int $id): RedirectResponse
    {
        service('securityService')->deactivate($id);

        return redirect()->to('/master/securities/' . $id)
            ->with('success', 'Sekuritas dinonaktifkan. Histori transaksinya tetap utuh dan tetap tampil di laporan.');
    }

    public function activate(int $id): RedirectResponse
    {
        service('securityService')->activate($id);

        return redirect()->to('/master/securities/' . $id)->with('success', 'Sekuritas diaktifkan kembali.');
    }

    public function storeAccount(int $id): RedirectResponse
    {
        try {
            service('securityService')->addAccount($id, [
                'label'          => (string) $this->request->getPost('label'),
                'account_number' => $this->request->getPost('account_number') ?: null,
                'bank_name'      => $this->request->getPost('bank_name') ?: null,
                'opened_at'      => $this->request->getPost('opened_at') ?: null,
                'notes'          => $this->request->getPost('notes') ?: null,
                'is_active'      => 1,
            ]);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/securities/' . $id);
        }

        return redirect()->to('/master/securities/' . $id)->with('success', 'Rekening berhasil ditambahkan.');
    }

    public function updateAccount(int $id, int $accountId): RedirectResponse
    {
        try {
            service('securityService')->updateAccount($accountId, [
                'label'          => (string) $this->request->getPost('label'),
                'account_number' => $this->request->getPost('account_number') ?: null,
                'bank_name'      => $this->request->getPost('bank_name') ?: null,
                'opened_at'      => $this->request->getPost('opened_at') ?: null,
                'notes'          => $this->request->getPost('notes') ?: null,
                'is_active'      => $this->request->getPost('is_active') !== null ? 1 : 0,
            ]);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/securities/' . $id);
        }

        return redirect()->to('/master/securities/' . $id)->with('success', 'Rekening berhasil diperbarui.');
    }
}
