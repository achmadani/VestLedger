<?php

declare(strict_types=1);

namespace App\Controllers\Master;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Enums\AccountType;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Chart of Accounts (§9).
 */
class Accounts extends BaseController
{
    use HandlesBusinessRules;

    private AccountModel $accounts;

    public function __construct()
    {
        $this->accounts = new AccountModel();
    }

    public function index(): string
    {
        return view('master/accounts/index', [
            'pageTitle' => 'Chart of Accounts',
            'grouped'   => $this->accounts->groupedByType(),
            'problems'  => service('chartOfAccounts')->verifySystemAccounts(),
        ]);
    }

    public function new(): string
    {
        return view('master/accounts/form', [
            'pageTitle'     => 'Tambah Akun',
            'account'       => null,
            'typeOptions'   => AccountType::options(),
            'parentOptions' => $this->accounts->parentOptions(),
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            $account = service('chartOfAccounts')->create($this->payload());
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/accounts/new');
        }

        return redirect()->to('/master/accounts')
            ->with('success', 'Akun ' . $account->displayName() . ' berhasil dibuat.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        $account = $this->accounts->find($id);

        if ($account === null) {
            return redirect()->to('/master/accounts')->with('error', 'Akun tidak ditemukan.');
        }

        return view('master/accounts/form', [
            'pageTitle'     => 'Ubah ' . $account->displayName(),
            'account'       => $account,
            'typeOptions'   => AccountType::options(),
            'parentOptions' => $this->accounts->parentOptions($id),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            service('chartOfAccounts')->update($id, $this->payload());
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/accounts/' . $id . '/edit');
        }

        return redirect()->to('/master/accounts')->with('success', 'Akun berhasil diperbarui.');
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            service('chartOfAccounts')->delete($id);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/master/accounts');
        }

        return redirect()->to('/master/accounts')->with('success', 'Akun berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'code'           => (string) $this->request->getPost('code'),
            'name'           => (string) $this->request->getPost('name'),
            'type'           => (string) $this->request->getPost('type'),
            'normal_balance' => (string) $this->request->getPost('normal_balance'),
            'parent_id'      => $this->request->getPost('parent_id') ?: null,
            'is_postable'    => $this->request->getPost('is_postable') !== null ? 1 : 0,
            'description'    => $this->request->getPost('description') ?: null,
            'is_active'      => $this->request->getPost('is_active') !== null ? 1 : 0,
        ];
    }
}
