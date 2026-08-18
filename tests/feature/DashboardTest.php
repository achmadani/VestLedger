<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class DashboardTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @param string|null $group null = cabut seluruh group, sehingga user
     *                           benar-benar tidak memiliki permission apa pun.
     *                           (Shield otomatis menaruh user baru di default group.)
     */
    private function makeUser(string $username, ?string $group): User
    {
        $users = new UserModel();

        $user = new User([
            'username' => $username,
            'email'    => $username . '@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);

        $users->save($user);
        $user = $users->findById($users->getInsertID());

        foreach ($user->getGroups() as $existing) {
            $user->removeGroup($existing);
        }

        if ($group !== null) {
            $user->addGroup($group);
        }

        return $user;
    }

    /**
     * Karena registrasi mandiri dimatikan, akun dibuat lewat CLI. User yang dibuat
     * tanpa opsi -g tidak masuk group mana pun dan karenanya tidak memiliki
     * permission apa pun — default yang aman untuk aplikasi data keuangan.
     */
    public function testNewlyCreatedUserHasNoPermissionsUntilGrantedAGroup(): void
    {
        $users = new UserModel();
        $user  = new User([
            'username' => 'default_group_user',
            'email'    => 'default_group_user@vestledger.test',
            'password' => 'kata-sandi-uji-yang-panjang',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());

        $this->assertSame([], $user->getGroups());
        $this->assertFalse($user->can('portfolio.view'));
        $this->assertFalse($user->can('transaction.create'));
        $this->assertFalse($user->can('period.manage'));
    }

    public function testOwnerCanOpenDashboard(): void
    {
        $result = $this->actingAs($this->makeUser('owner_user', 'owner'))->get('dashboard');

        $result->assertOK();
        $result->assertSee('Dashboard');
        $result->assertSee('Posisi Global');
    }

    /**
     * Viewer read-only tetap boleh melihat portofolio (matrix Config\AuthGroups).
     */
    public function testViewerCanOpenDashboard(): void
    {
        $result = $this->actingAs($this->makeUser('viewer_user', 'viewer'))->get('dashboard');

        $result->assertOK();
    }

    /**
     * User tanpa group sama sekali tidak memiliki permission apa pun,
     * sehingga filter otorisasi harus menolaknya.
     */
    public function testUserWithoutAnyGroupIsDenied(): void
    {
        $user = $this->makeUser('nogroup_user', null);

        $this->assertSame([], $user->getGroups());
        $this->assertFalse($user->can('portfolio.view'));

        $result = $this->actingAs($user)->get('dashboard');

        // Catatan: TestResponse::isOK() menganggap 3xx sebagai OK, jadi yang diuji
        // adalah adanya redirect penolakan dan tidak terkirimnya isi dashboard.
        $result->assertRedirect();
        $result->assertDontSee('Posisi Global');
    }

    /**
     * Menu yang belum dibangun tidak boleh menjadi link aktif (mencegah 404).
     */
    public function testUnbuiltMenuItemsAreNotRenderedAsLinks(): void
    {
        $result = $this->actingAs($this->makeUser('owner_nav', 'owner'))->get('dashboard');

        $result->assertOK();
        $result->assertSee('Neraca');
        $result->assertDontSee('/reports/balance-sheet');
    }

    /**
     * Sidebar hanya menampilkan menu yang diizinkan permission user (§36).
     */
    public function testSidebarHidesMenuGroupsTheUserCannotAccess(): void
    {
        $result = $this->actingAs($this->makeUser('viewer_nav', 'viewer'))->get('dashboard');

        $result->assertOK();
        $result->assertSee('Portofolio');
        // Viewer tidak punya user.manage maupun audit.view -> grup "Sistem" hilang.
        $result->assertDontSee('Audit Trail');
        $result->assertDontSee('Pengguna');
    }

    /**
     * Aset hasil build harus dirujuk oleh layout, karena tanpa CSS ini
     * seluruh design system DaisyUI tidak berlaku.
     */
    public function testLayoutReferencesBuiltAssets(): void
    {
        $result = $this->actingAs($this->makeUser('owner_asset', 'owner'))->get('dashboard');

        $result->assertOK();
        $result->assertSee('assets/css/app.css');
        $result->assertSee('assets/js/alpine.min.js');
        $result->assertSee('data-theme');
    }
}
