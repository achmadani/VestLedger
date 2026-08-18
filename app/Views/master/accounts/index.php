<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
use App\Enums\AccountType;

/** @var array<string, list<App\Entities\Account>> $grouped */
/** @var list<string> $problems */
$canManage = auth()->user()?->can('masterdata.manage') ?? false;

$actions = $canManage
    ? '<a href="' . site_url('master/accounts/new') . '" class="btn btn-primary btn-sm">Tambah Akun</a>'
    : null;
?>

<?= component('page_header', [
    'title'       => 'Chart of Accounts',
    'subtitle'    => 'Struktur akun yang dipakai seluruh jurnal dan laporan keuangan.',
    'breadcrumbs' => [['label' => 'Master Data'], ['label' => 'Chart of Accounts']],
    'actions'     => $actions,
]) ?>

<?php if ($problems !== []): ?>
    <div class="alert alert-warning mb-4">
        <?= component('icon', ['name' => 'warning', 'class' => 'w-5 h-5 shrink-0']) ?>
        <div class="text-sm">
            <p class="font-medium">Ada masalah pada akun inti.</p>
            <ul class="list-disc list-inside text-xs opacity-90 mt-1">
                <?php foreach ($problems as $problem): ?>
                    <li><?= esc($problem) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs opacity-80 mt-1">
                Jalankan <code>php spark db:seed ChartOfAccountsSeeder</code> untuk memulihkannya.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="space-y-4">
<?php foreach (AccountType::cases() as $type): ?>
    <?php
    $accounts = $grouped[$type->value] ?? [];

    if ($accounts === []) {
        continue;
    }

    $rows = '';

    foreach ($accounts as $account) {
        $badges = '';

        if ($account->is_system) {
            $badges .= '<span class="badge badge-primary badge-xs ml-2" title="Akun inti yang dirujuk mesin jurnal">inti</span>';
        }

        if ($account->isContra()) {
            $badges .= '<span class="badge badge-secondary badge-xs ml-1" title="Saldo normalnya berlawanan dengan tipe akunnya">kontra</span>';
        }

        if (! $account->is_postable) {
            $badges .= '<span class="badge badge-ghost badge-xs ml-1" title="Akun header, tidak menerima baris jurnal">header</span>';
        }

        if (! $account->is_active) {
            $badges .= '<span class="badge badge-ghost badge-xs ml-1">nonaktif</span>';
        }

        $rowActions = '';

        if ($canManage) {
            $rowActions = '<a href="' . site_url('master/accounts/' . $account->id . '/edit') . '" class="btn btn-ghost btn-xs">Ubah</a>';

            if (! $account->is_system) {
                $rowActions .= component('confirm_form', [
                    'action'  => site_url('master/accounts/' . $account->id . '/delete'),
                    'label'   => 'Hapus',
                    'message' => 'Hapus akun ' . $account->code . ' — ' . $account->name . '?',
                    'class'   => 'btn btn-ghost btn-xs text-error',
                ]);
            }
        }

        $rows .= '<tr class="hover">'
            . '<td class="font-mono font-medium whitespace-nowrap">' . esc($account->code) . '</td>'
            . '<td>' . esc($account->name) . $badges . '</td>'
            . '<td>' . esc($account->normalBalance()->label()) . '</td>'
            . '<td class="text-xs text-base-content/60">' . esc($account->description ?? '') . '</td>'
            . '<td class="text-right whitespace-nowrap">' . $rowActions . '</td>'
            . '</tr>';
    }
    ?>

    <?= component('card', [
        'title'    => $type->label(),
        'subtitle' => 'Saldo normal ' . $type->normalBalance()->label() . ' · ' . ($type->isReal() ? 'muncul di Neraca' : 'muncul di Laba Rugi'),
        'flush'    => true,
        'body'     => '<div class="overflow-x-auto"><table class="table table-sm">'
            . '<thead><tr><th>Kode</th><th>Nama</th><th>Saldo Normal</th><th>Keterangan</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
    ]) ?>
<?php endforeach; ?>
</div>
<?= $this->endSection() ?>
