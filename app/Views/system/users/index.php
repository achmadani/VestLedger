<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$groupOptions = [];

foreach ($groups as $group) {
    $groupOptions[$group] = ucfirst($group);
}

$minLength = config('Auth')->minimumPasswordLength;
?>

<?= component('page_header', [
    'title'       => 'Pengguna',
    'subtitle'    => 'Akun yang dapat masuk ke aplikasi, beserta perannya.',
    'breadcrumbs' => [['label' => 'Sistem'], ['label' => 'Pengguna']],
]) ?>

<div class="alert alert-info mb-4">
    <?= component('icon', ['name' => 'lock', 'class' => 'w-5 h-5 shrink-0']) ?>
    <div class="text-sm">
        <p class="font-medium">Peran menentukan apa yang boleh dilakukan.</p>
        <ul class="text-xs opacity-90 mt-1 space-y-0.5 list-disc list-inside">
            <li><strong>Owner</strong> — akses penuh, termasuk tutup periode, pembatalan transaksi, dan saldo awal.</li>
            <li><strong>Accountant</strong> — input dan pembatalan transaksi, tanpa hak tutup/buka periode.</li>
            <li><strong>Viewer</strong> — hanya melihat portofolio dan laporan.</li>
        </ul>
    </div>
</div>

<?php
$rows = '';

foreach ($users as $user) {
    $userGroups = $user->getGroups();
    $current    = $userGroups[0] ?? '-';
    $banned     = $user->isBanned();
    $isSelf     = $user->id === auth()->id();

    $status = $banned
        ? '<span class="badge badge-error badge-sm">Nonaktif</span>'
        : '<span class="badge badge-success badge-sm">Aktif</span>';

    $groupForm = '<form method="post" action="' . site_url('system/users/' . $user->id . '/group') . '" class="flex items-center gap-1">'
        . csrf_field()
        . '<select name="group" class="select select-bordered select-xs">'
        . implode('', array_map(
            static fn (string $g): string => '<option value="' . esc($g, 'attr') . '"'
                . ($g === $current ? ' selected' : '') . '>' . esc(ucfirst($g)) . '</option>',
            $groups
        ))
        . '</select><button type="submit" class="btn btn-xs">Simpan</button></form>';

    $toggle = $banned
        ? component('confirm_form', [
            'action'  => site_url('system/users/' . $user->id . '/activate'),
            'label'   => 'Aktifkan',
            'message' => 'Aktifkan kembali ' . $user->username . '?',
            'class'   => 'btn btn-xs btn-success',
        ])
        : component('confirm_form', [
            'action'  => site_url('system/users/' . $user->id . '/deactivate'),
            'label'   => 'Nonaktifkan',
            'message' => 'Nonaktifkan ' . $user->username . '? Pengguna ini tidak akan bisa masuk lagi.',
            'class'   => 'btn btn-xs btn-warning',
        ]);

    $rows .= '<tr class="hover">'
        . '<td class="font-medium">' . esc($user->username)
            . ($isSelf ? ' <span class="badge badge-ghost badge-xs">Anda</span>' : '') . '</td>'
        . '<td class="text-sm">' . esc($user->email) . '</td>'
        . '<td>' . $groupForm . '</td>'
        . '<td>' . $status . '</td>'
        . '<td class="text-right">' . ($isSelf ? '<span class="text-xs text-base-content/40">—</span>' : $toggle) . '</td>'
        . '</tr>';
}
?>

<?= component('card', [
    'title' => 'Daftar Pengguna',
    'flush' => true,
    'body'  => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr><th>Username</th><th>Email</th><th>Peran</th><th>Status</th><th></th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>

<div class="mt-4 max-w-2xl">
    <form method="post" action="<?= site_url('system/users') ?>">
        <?= csrf_field() ?>
        <?= component('card', [
            'title'    => 'Tambah Pengguna',
            'subtitle' => 'Kata sandi di-hash oleh CodeIgniter Shield dan tidak pernah tersimpan sebagai teks biasa.',
            'body'     => '<div class="grid gap-3 sm:grid-cols-2">'
                . component('form/input', [
                    'name'     => 'username',
                    'label'    => 'Username',
                    'required' => true,
                    'help'     => 'Hanya huruf, angka, dan titik. Tanda hubung dan garis bawah tidak diperbolehkan.',
                ])
                . component('form/input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true])
                . component('form/input', [
                    'name'     => 'password',
                    'label'    => 'Kata Sandi',
                    'type'     => 'password',
                    'required' => true,
                    'help'     => 'Minimal ' . $minLength . ' karakter. Shield menolak kata sandi yang mudah ditebak.',
                    'attrs'    => ['autocomplete' => 'new-password'],
                ])
                . component('form/input', [
                    'name'     => 'password_confirm',
                    'label'    => 'Ulangi Kata Sandi',
                    'type'     => 'password',
                    'required' => true,
                    'help'     => 'Salah ketik di sini akan membuat pengguna baru tidak bisa masuk.',
                    'attrs'    => ['autocomplete' => 'new-password'],
                ])
                . component('form/select', [
                    'name'        => 'group',
                    'label'       => 'Peran',
                    'options'     => $groupOptions,
                    'value'       => 'viewer',
                    'required'    => true,
                    'placeholder' => null,
                ])
                . '</div>',
            'footer'   => '<button type="submit" class="btn btn-primary btn-sm">Tambah Pengguna</button>',
        ]) ?>
    </form>
</div>
<?= $this->endSection() ?>
