<?php

declare(strict_types=1);

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Pengelolaan akun pengguna (§36).
 */
class Users extends BaseController
{
    use HandlesBusinessRules;

    public function index(): string
    {
        $users = (new UserModel())->orderBy('username', 'asc')->findAll();

        return view('system/users/index', [
            'pageTitle' => 'Pengguna',
            'users'     => $users,
            'groups'    => service('userAccounts')->availableGroups(),
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            $user = service('userAccounts')->create([
                'username' => (string) $this->request->getPost('username'),
                'email'    => (string) $this->request->getPost('email'),
                'password'         => (string) $this->request->getPost('password'),
                'password_confirm' => (string) $this->request->getPost('password_confirm'),
                'group'            => (string) $this->request->getPost('group'),
            ]);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/system/users');
        }

        return redirect()->to('/system/users')
            ->with('success', 'Pengguna ' . $user->username . ' berhasil dibuat.');
    }

    public function changeGroup(int $id): RedirectResponse
    {
        try {
            service('userAccounts')->changeGroup($id, (string) $this->request->getPost('group'));
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/system/users');
        }

        return redirect()->to('/system/users')->with('success', 'Group pengguna diperbarui.');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->setActive($id, true);
    }

    public function deactivate(int $id): RedirectResponse
    {
        return $this->setActive($id, false);
    }

    private function setActive(int $id, bool $active): RedirectResponse
    {
        try {
            service('userAccounts')->setActive($id, $active);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/system/users');
        }

        return redirect()->to('/system/users')
            ->with('success', 'Pengguna ' . ($active ? 'diaktifkan' : 'dinonaktifkan') . '.');
    }
}
