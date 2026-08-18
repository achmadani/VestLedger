<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Pengelolaan akun pengguna (§36).
 *
 * @internal
 */
final class UserManagementTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        \Config\Services::reset(true);
    }

    private function makeUser(string $group): User
    {
        $users = new UserModel();
        $user  = new User([
            // Shield hanya mengizinkan huruf, angka, dan titik pada username.
            'username' => $group . bin2hex(random_bytes(4)),
            'email'    => bin2hex(random_bytes(6)) . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup($group);

        return $user;
    }

    private function postAs(User $user, string $path, array $data = []): \CodeIgniter\Test\TestResponse
    {
        return $this->actingAs($user)->withBodyFormat('html')->post($path, $data + [csrf_token() => csrf_hash()]);
    }

    public function testOwnerCanOpenUserManagement(): void
    {
        $result = $this->actingAs($this->makeUser('owner'))->get('system/users');

        $result->assertOK();
        $result->assertSee('Tambah Pengguna');
        $result->assertSee('Accountant');
    }

    public function testCreatingUserAssignsTheChosenGroup(): void
    {
        $owner    = $this->makeUser('owner');
        $username = 'baru' . bin2hex(random_bytes(4));

        $this->postAs($owner, 'system/users', [
            'username' => $username,
            'email'    => bin2hex(random_bytes(6)) . '@vestledger.test',
            'password'         => 'kata-sandi-yang-cukup-panjang',
            'password_confirm' => 'kata-sandi-yang-cukup-panjang',
            'group'            => 'accountant',
        ])->assertRedirect();

        $created = (new UserModel())->where('username', $username)->first();

        $this->assertNotNull($created);
        $this->assertSame(['accountant'], $created->getGroups());
    }

    /**
     * Kata sandi tidak boleh tersimpan sebagai teks biasa di mana pun,
     * termasuk di jejak audit.
     */
    public function testPasswordIsNeverStoredInPlainTextOrAudited(): void
    {
        $owner    = $this->makeUser('owner');
        $password = 'rahasia-sekali-yang-panjang';
        $username = 'hash' . bin2hex(random_bytes(4));

        $this->postAs($owner, 'system/users', [
            'username' => $username,
            'email'    => bin2hex(random_bytes(6)) . '@vestledger.test',
            'password'         => $password,
            'password_confirm' => $password,
            'group'            => 'viewer',
        ]);

        $identity = $this->db->table('auth_identities')
            ->where('type', 'email_password')
            ->orderBy('id', 'desc')->get()->getRowArray();

        $this->assertStringNotContainsString($password, (string) $identity['secret2']);
        $this->assertStringStartsWith('$2y$', (string) $identity['secret2'], 'Kata sandi harus di-hash bcrypt.');

        $logs = $this->db->table('audit_logs')->orderBy('id', 'desc')->limit(3)->get()->getResultArray();

        foreach ($logs as $log) {
            $this->assertStringNotContainsString($password, json_encode($log, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testWeakPasswordIsRejected(): void
    {
        $owner = $this->makeUser('owner');

        $this->postAs($owner, 'system/users', [
            'username' => $weak = 'lemah' . bin2hex(random_bytes(4)),
            'email'    => bin2hex(random_bytes(6)) . '@vestledger.test',
            'password' => '123',
            'group'    => 'viewer',
        ])->assertRedirect();

        $this->assertNull(
            (new UserModel())->where('username', $weak)->first(),
            'Kata sandi lemah seharusnya ditolak sehingga pengguna tidak terbuat.'
        );
    }

    public function testGroupCanBeChanged(): void
    {
        $owner  = $this->makeUser('owner');
        $target = $this->makeUser('viewer');

        $this->postAs($owner, 'system/users/' . $target->id . '/group', ['group' => 'accountant'])
            ->assertRedirect();

        $this->assertSame(['accountant'], (new UserModel())->findById($target->id)->getGroups());
    }

    public function testUnknownGroupIsRejected(): void
    {
        $owner  = $this->makeUser('owner');
        $target = $this->makeUser('viewer');

        $this->postAs($owner, 'system/users/' . $target->id . '/group', ['group' => 'superadmin']);

        $this->assertSame(['viewer'], (new UserModel())->findById($target->id)->getGroups());
    }

    public function testDeactivatedUserCannotBeUsedToAct(): void
    {
        $owner  = $this->makeUser('owner');
        $target = $this->makeUser('viewer');

        $this->postAs($owner, 'system/users/' . $target->id . '/deactivate')->assertRedirect();

        $this->assertTrue((new UserModel())->findById($target->id)->isBanned());
    }

    /**
     * Satu klik tidak boleh mengunci seluruh akses pengelolaan aplikasi.
     */
    public function testTheLastActiveOwnerCannotBeDemoted(): void
    {
        // Bersihkan owner lain yang tersisa dari test sebelumnya.
        foreach ((new UserModel())->findAll() as $existing) {
            foreach ($existing->getGroups() as $g) {
                $existing->removeGroup($g);
            }
        }

        $owner = $this->makeUser('owner');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/satu-satunya owner/');

        service('userAccounts')->changeGroup($owner->id, 'viewer');
    }

    public function testUserCannotDeactivateThemselves(): void
    {
        $owner = $this->makeUser('owner');
        $other = $this->makeUser('owner');

        $this->postAs($owner, 'system/users/' . $owner->id . '/deactivate');

        $this->assertFalse(
            (new UserModel())->findById($owner->id)->isBanned(),
            'Akun sendiri tidak boleh dinonaktifkan.'
        );
    }

    /**
     * §36: hanya owner yang boleh mengelola pengguna.
     */
    public function testAccountantCannotManageUsers(): void
    {
        $accountant = $this->makeUser('accountant');
        $target     = $this->makeUser('viewer');

        $this->actingAs($accountant)->get('system/users')->assertRedirect();

        $this->postAs($accountant, 'system/users/' . $target->id . '/group', ['group' => 'owner'])
            ->assertRedirect();

        $this->assertSame(['viewer'], (new UserModel())->findById($target->id)->getGroups());
    }
}
