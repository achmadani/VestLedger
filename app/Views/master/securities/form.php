<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var App\Entities\Security|null $security */
$isEdit = $security !== null;
$action = $isEdit ? site_url('master/securities/' . $security->id) : site_url('master/securities');
?>

<?= component('page_header', [
    'title'       => $isEdit ? 'Ubah Sekuritas' : 'Tambah Sekuritas',
    'breadcrumbs' => [
        ['label' => 'Master Data'],
        ['label' => 'Sekuritas', 'url' => site_url('master/securities')],
        ['label' => $isEdit ? $security->code : 'Baru'],
    ],
]) ?>

<form method="post" action="<?= esc($action, 'attr') ?>" class="max-w-2xl">
    <?= csrf_field() ?>

    <?= component('card', [
        'title' => 'Identitas Sekuritas',
        'body'  => component('form/input', [
            'name'     => 'code',
            'label'    => 'Kode',
            'value'    => old('code', $isEdit ? $security->code : ''),
            'required' => true,
            'help'     => 'Kode singkat tanpa spasi, otomatis disimpan huruf besar. Contoh: AJAIB.',
            'attrs'    => ['maxlength' => 20, 'autofocus' => 'autofocus'],
        ])
        . component('form/input', [
            'name'     => 'name',
            'label'    => 'Nama Sekuritas',
            'value'    => old('name', $isEdit ? $security->name : ''),
            'required' => true,
            'class'    => 'mt-3',
        ])
        . '<div class="grid gap-3 sm:grid-cols-2 mt-3">'
        . component('form/input', [
            'name'  => 'buy_fee_percent',
            'label' => 'Fee Beli (%)',
            'type'  => 'number',
            'value' => old('buy_fee_percent', $isEdit ? rtrim(rtrim((string) $security->buy_fee_percent, '0'), '.') : '0.15'),
            'help'  => 'Tarif all-in, sudah termasuk levy bursa.',
            'attrs' => ['step' => '0.00001', 'min' => '0', 'class' => 'input input-bordered w-full num'],
        ])
        . component('form/input', [
            'name'  => 'sell_fee_percent',
            'label' => 'Fee Jual (%)',
            'type'  => 'number',
            'value' => old('sell_fee_percent', $isEdit ? rtrim(rtrim((string) $security->sell_fee_percent, '0'), '.') : '0.25'),
            'help'  => 'Tarif all-in, sudah termasuk PPh final dan levy.',
            'attrs' => ['step' => '0.00001', 'min' => '0', 'class' => 'input input-bordered w-full num'],
        ])
        . '</div>'
        . '<p class="text-xs text-base-content/60 mt-2">Sistem memecah tarif all-in ini menjadi fee broker, '
        . 'PPh final ' . fmt_number(config(\Config\Investment::class)->sellTaxPercent, 2) . '% (hanya sisi jual), '
        . 'dan levy bursa ' . fmt_number(config(\Config\Investment::class)->exchangeLevyPercent, 3) . '%, '
        . 'karena ketiganya masuk akun yang berbeda. Jumlahnya tetap persis sama dengan tarif di atas.</p>'
        . component('form/textarea', [
            'name'  => 'notes',
            'label' => 'Catatan',
            'value' => old('notes', $isEdit ? $security->notes : ''),
            'class' => 'mt-3',
        ])
        . '<div class="mt-3">' . component('form/checkbox', [
            'name'    => 'is_active',
            'label'   => 'Aktif',
            'checked' => (bool) old('is_active', $isEdit ? $security->is_active : true),
            'help'    => 'Sekuritas nonaktif tidak muncul di form transaksi baru, tetapi historinya tetap utuh.',
        ]) . '</div>',
    ]) ?>

    <?php if (! $isEdit): ?>
        <div class="mt-4">
            <?= component('card', [
                'title'    => 'Rekening Awal',
                'subtitle' => 'Transaksi selalu merujuk rekening, bukan broker — karena itu satu rekening dibuat sekaligus di sini.',
                'body'     => component('form/input', [
                    'name'     => 'account_label',
                    'label'    => 'Nama Rekening',
                    'value'    => old('account_label', 'RDN Utama'),
                    'required' => true,
                ])
                . component('form/input', [
                    'name'  => 'account_number',
                    'label' => 'Nomor Rekening / RDN',
                    'value' => old('account_number'),
                    'help'  => 'Opsional. Ditampilkan tersamar di daftar dan hanya terlihat penuh di halaman detail.',
                    'class' => 'mt-3',
                ])
                . component('form/input', [
                    'name'  => 'bank_name',
                    'label' => 'Bank RDN',
                    'value' => old('bank_name'),
                    'class' => 'mt-3',
                ]),
            ]) ?>
        </div>
    <?php endif; ?>

    <div class="flex items-center gap-2 mt-4">
        <button type="submit" class="btn btn-primary btn-sm"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan' ?></button>
        <a href="<?= site_url($isEdit ? 'master/securities/' . $security->id : 'master/securities') ?>" class="btn btn-ghost btn-sm">Batal</a>
    </div>
</form>
<?= $this->endSection() ?>
