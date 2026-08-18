<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$canManage = auth()->user()?->can('masterdata.manage') ?? false;

$actions = $canManage
    ? '<a href="' . site_url('master/securities/new') . '" class="btn btn-primary btn-sm">Tambah Sekuritas</a>'
    : null;
?>

<?= component('page_header', [
    'title'       => 'Sekuritas',
    'subtitle'    => 'Perusahaan sekuritas beserta rekening efek/RDN yang dipakai bertransaksi.',
    'breadcrumbs' => [['label' => 'Master Data'], ['label' => 'Sekuritas']],
    'actions'     => $actions,
]) ?>

<?php
$rows = '';

foreach ($securities as $security) {
    $count  = $accountCounts[$security->id] ?? 0;
    $status = $security->is_active
        ? '<span class="badge badge-success badge-sm">Aktif</span>'
        : '<span class="badge badge-ghost badge-sm">Nonaktif</span>';

    $rows .= '<tr class="hover">'
        . '<td class="font-mono font-medium">' . esc($security->code) . '</td>'
        . '<td>' . esc($security->name) . '</td>'
        . '<td class="num">' . $count . '</td>'
        . '<td>' . $status . '</td>'
        . '<td class="text-right"><a href="' . site_url('master/securities/' . $security->id) . '" class="btn btn-ghost btn-xs">Detail</a></td>'
        . '</tr>';
}

$body = $securities === []
    ? component('empty_state', [
        'title'       => 'Belum ada sekuritas',
        'description' => 'Tambahkan sekuritas terlebih dahulu; setiap transaksi harus terhubung ke salah satu rekeningnya.',
        'icon'        => 'database',
        'actions'     => $actions,
    ])
    : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr><th>Kode</th><th>Nama</th><th class="num">Rekening</th><th>Status</th><th></th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
?>

<?= component('card', ['flush' => true, 'body' => $body]) ?>
<?= $this->endSection() ?>
