<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var App\Entities\Account|null $account */
$isEdit   = $account !== null;
$isSystem = $isEdit && $account->is_system;
$action   = $isEdit ? site_url('master/accounts/' . $account->id) : site_url('master/accounts');
?>

<?= component('page_header', [
    'title'       => $isEdit ? 'Ubah Akun' : 'Tambah Akun',
    'breadcrumbs' => [
        ['label' => 'Master Data'],
        ['label' => 'Chart of Accounts', 'url' => site_url('master/accounts')],
        ['label' => $isEdit ? $account->code : 'Baru'],
    ],
]) ?>

<?php if ($isSystem): ?>
    <div class="alert alert-info mb-4 max-w-2xl">
        <?= component('icon', ['name' => 'lock', 'class' => 'w-5 h-5 shrink-0']) ?>
        <div class="text-sm">
            <p class="font-medium">Ini akun inti.</p>
            <p class="text-xs opacity-80 mt-0.5">
                Kode, tipe, dan saldo normalnya dikunci karena dirujuk langsung oleh mesin jurnal.
                Nama dan keterangan tetap dapat disesuaikan.
            </p>
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= esc($action, 'attr') ?>" class="max-w-2xl">
    <?= csrf_field() ?>

    <?= component('card', [
        'body' => component('form/input', [
            'name'     => 'code',
            'label'    => 'Kode Akun',
            'value'    => old('code', $isEdit ? $account->code : ''),
            'required' => true,
            'readonly' => $isSystem,
            'help'     => 'Kode numerik mengikuti pengelompokan: 1xxx aset, 3xxx ekuitas, 4xxx pendapatan, 5xxx beban.',
            'attrs'    => ['maxlength' => 20],
        ])
        . component('form/input', [
            'name'     => 'name',
            'label'    => 'Nama Akun',
            'value'    => old('name', $isEdit ? $account->name : ''),
            'required' => true,
            'class'    => 'mt-3',
        ])
        . '<div class="grid gap-3 sm:grid-cols-2 mt-3">'
        . component('form/select', [
            'name'        => 'type',
            'label'       => 'Tipe',
            'options'     => $typeOptions,
            'value'       => old('type', $isEdit ? $account->type : ''),
            'required'    => true,
            'placeholder' => $isSystem ? null : '-- Pilih tipe --',
            'attrs'       => $isSystem ? ['disabled' => 'disabled'] : [],
        ])
        . component('form/select', [
            'name'        => 'normal_balance',
            'label'       => 'Saldo Normal',
            'options'     => ['debit' => 'Debit', 'credit' => 'Kredit'],
            'value'       => old('normal_balance', $isEdit ? $account->normal_balance : ''),
            'placeholder' => 'Ikuti tipe akun',
            'help'        => 'Isi berlawanan dengan tipe akun untuk membuat akun kontra.',
            'attrs'       => $isSystem ? ['disabled' => 'disabled'] : [],
        ])
        . '</div>'
        . component('form/select', [
            'name'        => 'parent_id',
            'label'       => 'Akun Induk',
            'options'     => $parentOptions,
            'value'       => old('parent_id', $isEdit ? $account->parent_id : ''),
            'placeholder' => 'Tanpa induk',
            'class'       => 'mt-3',
        ])
        . component('form/textarea', [
            'name'  => 'description',
            'label' => 'Keterangan',
            'value' => old('description', $isEdit ? $account->description : ''),
            'class' => 'mt-3',
        ])
        . '<div class="mt-3 space-y-1">'
        . component('form/checkbox', [
            'name'    => 'is_postable',
            'label'   => 'Dapat menerima jurnal',
            'checked' => (bool) old('is_postable', $isEdit ? $account->is_postable : true),
            'help'    => 'Matikan untuk akun header yang hanya berfungsi mengelompokkan sub-akun.',
        ])
        . component('form/checkbox', [
            'name'    => 'is_active',
            'label'   => 'Aktif',
            'checked' => (bool) old('is_active', $isEdit ? $account->is_active : true),
        ])
        . '</div>',
    ]) ?>

    <?php if ($isSystem): ?>
        <?php // Field yang di-disable tidak dikirim browser; nilainya dikirim ulang agar tidak hilang. ?>
        <input type="hidden" name="type" value="<?= esc($account->type, 'attr') ?>">
        <input type="hidden" name="normal_balance" value="<?= esc($account->normal_balance, 'attr') ?>">
    <?php endif; ?>

    <div class="flex items-center gap-2 mt-4">
        <button type="submit" class="btn btn-primary btn-sm"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan' ?></button>
        <a href="<?= site_url('master/accounts') ?>" class="btn btn-ghost btn-sm">Batal</a>
    </div>
</form>
<?= $this->endSection() ?>
