<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\Accounting\AuditLogger;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Validation\ValidationRules;
use Config\AuthGroups;

/**
 * Pengelolaan akun pengguna aplikasi (§36).
 *
 * Kata sandi tidak pernah disentuh service ini secara langsung: ia diserahkan
 * ke Shield, yang melakukan hashing dan validasi kekuatannya sendiri.
 */
class UserAccountService
{
    public function __construct(
        private UserModel $users,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return list<string>
     */
    public function availableGroups(): array
    {
        return array_keys((new AuthGroups())->groups);
    }

    /**
     * @param array{username:string, email:string, password:string, password_confirm?:string, group:string} $input
     */
    public function create(array $input): User
    {
        $group = trim((string) ($input['group'] ?? ''));

        if (! in_array($group, $this->availableGroups(), true)) {
            throw new BusinessRuleException('Group yang dipilih tidak dikenali.');
        }

        $this->validateCredentials($input);

        $user = new User([
            'username' => trim((string) ($input['username'] ?? '')),
            'email'    => trim((string) ($input['email'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
        ]);

        if ($this->users->save($user) === false) {
            throw new BusinessRuleException(
                'Pengguna gagal dibuat.',
                array_values($this->users->errors())
            );
        }

        $created = $this->users->findById($this->users->getInsertID());
        $created->addGroup($group);

        // Kata sandi TIDAK ikut dicatat dalam bentuk apa pun.
        $this->audit->record(
            'created',
            'user',
            $created->id,
            sprintf('Pengguna %s dibuat dengan group %s', $created->username, $group),
        );

        return $created;
    }

    /**
     * Menjalankan aturan validasi pendaftaran milik Shield.
     *
     * UserModel::save() TIDAK memeriksa kekuatan kata sandi — aturan itu berada
     * di ValidationRules::getRegistrationRules() dan hanya dipakai oleh
     * RegisterController bawaan Shield, yang di aplikasi ini dimatikan.
     *
     * Tanpa pemanggilan ini, akun dapat dibuat dengan kata sandi seperti "123".
     *
     * @param array<string, mixed> $input
     */
    private function validateCredentials(array $input): void
    {
        $data = [
            'username'         => trim((string) ($input['username'] ?? '')),
            'email'            => trim((string) ($input['email'] ?? '')),
            'password'         => (string) ($input['password'] ?? ''),
            'password_confirm' => (string) ($input['password_confirm'] ?? $input['password'] ?? ''),
        ];

        $validation = service('validation');
        $validation->reset();
        $validation->setRules((new ValidationRules())->getRegistrationRules());

        if (! $validation->run($data)) {
            throw new BusinessRuleException(
                'Pengguna gagal dibuat.',
                array_values($validation->getErrors())
            );
        }
    }

    public function changeGroup(int $userId, string $group): void
    {
        if (! in_array($group, $this->availableGroups(), true)) {
            throw new BusinessRuleException('Group yang dipilih tidak dikenali.');
        }

        $user = $this->requireUser($userId);

        $this->assertNotLastOwner($user, $group);

        $previous = $user->getGroups();

        foreach ($previous as $existing) {
            $user->removeGroup($existing);
        }

        $user->addGroup($group);

        $this->audit->record(
            'updated',
            'user',
            $user->id,
            sprintf('Group %s diubah menjadi %s', $user->username, $group),
            ['groups' => $previous],
            ['groups' => [$group]],
        );
    }

    public function setActive(int $userId, bool $active): void
    {
        $user = $this->requireUser($userId);

        if (! $active) {
            if ($user->id === auth()->id()) {
                throw new BusinessRuleException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
            }

            $this->assertNotLastOwner($user, null);
        }

        $active ? $user->unBan() : $user->ban('Dinonaktifkan oleh administrator');

        $this->audit->record(
            $active ? 'updated' : 'updated',
            'user',
            $user->id,
            sprintf('Pengguna %s %s', $user->username, $active ? 'diaktifkan' : 'dinonaktifkan'),
        );
    }

    /**
     * Aplikasi harus selalu menyisakan minimal satu owner yang aktif.
     *
     * Tanpa penjagaan ini, satu klik dapat mengunci seluruh akses pengelolaan —
     * termasuk kemampuan mengembalikannya.
     */
    private function assertNotLastOwner(User $user, ?string $newGroup): void
    {
        if (! in_array('owner', $user->getGroups(), true)) {
            return;
        }

        if ($newGroup === 'owner') {
            return;
        }

        $remaining = 0;

        foreach ($this->users->findAll() as $candidate) {
            if ($candidate->id === $user->id) {
                continue;
            }

            if (in_array('owner', $candidate->getGroups(), true) && ! $candidate->isBanned()) {
                $remaining++;
            }
        }

        if ($remaining === 0) {
            throw new BusinessRuleException(
                'Ini satu-satunya owner yang aktif. Mengubahnya akan membuat tidak ada lagi '
                . 'pengguna yang dapat mengelola aplikasi — termasuk mengembalikan hak ini.'
            );
        }
    }

    private function requireUser(int $id): User
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            throw new BusinessRuleException('Pengguna tidak ditemukan.');
        }

        return $user;
    }
}
