<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var App\Entities\Stock|null $stock */
$isEdit = $stock !== null;
$action = $isEdit ? site_url('master/stocks/' . $stock->id) : site_url('master/stocks');
?>

<?= component('page_header', [
    'title'       => $isEdit ? 'Ubah Saham' : 'Tambah Saham',
    'breadcrumbs' => [
        ['label' => 'Master Data'],
        ['label' => 'Saham', 'url' => site_url('master/stocks')],
        ['label' => $isEdit ? $stock->ticker : 'Baru'],
    ],
]) ?>

<form method="post" action="<?= esc($action, 'attr') ?>" class="max-w-2xl">
    <?= csrf_field() ?>

    <?= component('card', [
        'body' => component('form/input', [
            'name'     => 'ticker',
            'label'    => 'Ticker',
            'value'    => old('ticker', $isEdit ? $stock->ticker : ''),
            'required' => true,
            'help'     => 'Kode saham 2–10 karakter, otomatis disimpan huruf besar. Contoh: BBCA.',
            'attrs'    => ['maxlength' => 10, 'autofocus' => 'autofocus', 'class' => 'input input-bordered w-full uppercase'],
        ])
        . component('form/input', [
            'name'     => 'company_name',
            'label'    => 'Nama Perusahaan',
            'value'    => old('company_name', $isEdit ? $stock->company_name : ''),
            'required' => true,
            'class'    => 'mt-3',
        ])
        . component('form/input', [
            'name'  => 'sector',
            'label' => 'Sektor',
            'value' => old('sector', $isEdit ? $stock->sector : ''),
            'help'  => 'Opsional. Dipakai untuk mengelompokkan portofolio pada laporan.',
            'class' => 'mt-3',
            'attrs' => ['list' => 'sector-list'],
        ])
        . '<datalist id="sector-list">'
        . implode('', array_map(static fn (string $s): string => '<option value="' . esc($s, 'attr') . '"></option>', $sectors))
        . '</datalist>'
        . component('form/textarea', [
            'name'  => 'notes',
            'label' => 'Catatan',
            'value' => old('notes', $isEdit ? $stock->notes : ''),
            'class' => 'mt-3',
        ])
        . '<div class="mt-3">' . component('form/checkbox', [
            'name'    => 'is_active',
            'label'   => 'Aktif',
            'checked' => (bool) old('is_active', $isEdit ? $stock->is_active : true),
            'help'    => 'Saham nonaktif tidak muncul di form transaksi baru.',
        ]) . '</div>',
    ]) ?>

    <div class="flex items-center gap-2 mt-4">
        <button type="submit" class="btn btn-primary btn-sm"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan' ?></button>
        <a href="<?= site_url('master/stocks') ?>" class="btn btn-ghost btn-sm">Batal</a>
    </div>
</form>
<?= $this->endSection() ?>
