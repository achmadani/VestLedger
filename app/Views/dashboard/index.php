<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'    => 'Dashboard',
    'subtitle' => 'Ringkasan posisi portofolio dan kinerja investasi.',
]) ?>

<div class="alert alert-info mb-6">
    <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
    <div class="text-sm">
        <p class="font-medium">Phase 1 — Foundation selesai.</p>
        <p class="text-xs opacity-80 mt-0.5">
            Kerangka aplikasi, autentikasi, dan design system sudah aktif. Angka di bawah
            masih nol karena transaction engine dan portfolio engine dibangun pada Phase 3 dan Phase 5.
            Tidak ada angka contoh yang ditampilkan agar tidak terbaca sebagai data nyata.
        </p>
    </div>
</div>

<?php
// Phase 1 belum memiliki sumber data. Nilai nol di sini adalah state kosong yang
// jujur, dan akan digantikan hasil PortfolioService pada Phase 5.
$empty = 0;
?>

<h2 class="text-sm font-semibold uppercase tracking-wide text-base-content/50 mb-3">Posisi Global</h2>

<div class="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-6">
    <?= component('stat', ['label' => 'Total Kas', 'value' => fmt_rupiah($empty), 'icon' => 'database', 'tone' => 'primary']) ?>
    <?= component('stat', ['label' => 'Book Value', 'value' => fmt_rupiah($empty), 'icon' => 'book']) ?>
    <?= component('stat', ['label' => 'Market Value', 'value' => fmt_rupiah($empty), 'icon' => 'chart']) ?>
    <?= component('stat', [
        'label'      => 'Total Net Worth',
        'value'      => fmt_rupiah($empty),
        'sub'        => 'Kas + market value portofolio',
        'icon'       => 'dashboard',
        'tone'       => 'primary',
    ]) ?>
</div>

<div class="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 mb-6">
    <?= component('stat', [
        'label'      => 'Unrealized Gain/Loss',
        'value'      => fmt_signed($empty),
        'sub'        => 'Belum masuk laba rugi periode berjalan',
        'valueClass' => amount_class($empty),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Realized Gain/Loss',
        'value'      => fmt_signed($empty),
        'sub'        => 'Dari transaksi jual yang sudah terjadi',
        'valueClass' => amount_class($empty),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Dividend Income',
        'value'      => fmt_rupiah($empty),
        'sub'        => 'Akumulasi periode berjalan',
    ]) ?>
    <?= component('stat', [
        'label' => 'Broker Fee',
        'value' => fmt_rupiah($empty),
        'sub'   => 'Fee jual + biaya administrasi',
    ]) ?>
    <?= component('stat', [
        'label' => 'Biaya Lain & Pajak',
        'value' => fmt_rupiah($empty),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Laba/Rugi Bersih',
        'value'      => fmt_signed($empty),
        'sub'        => 'Realized + dividen − seluruh beban',
        'valueClass' => amount_class($empty),
    ]) ?>
</div>

<div class="grid gap-4 grid-cols-1 lg:grid-cols-2">
    <?= component('card', [
        'title'    => 'Portofolio per Sekuritas',
        'subtitle' => 'Kas, book value, dan market value tiap sekuritas',
        'flush'    => true,
        'body'     => component('empty_state', [
            'title'       => 'Belum ada sekuritas terdaftar',
            'description' => 'Master data sekuritas dibuat pada Phase 2.',
            'icon'        => 'database',
        ]),
    ]) ?>

    <?= component('card', [
        'title'    => 'Transaksi Terakhir',
        'subtitle' => '10 transaksi terbaru dari seluruh sekuritas',
        'flush'    => true,
        'body'     => component('empty_state', [
            'title'       => 'Belum ada transaksi',
            'description' => 'Modul transaksi dibuat pada Phase 3.',
            'icon'        => 'transaction',
        ]),
    ]) ?>
</div>

<?php
// Peta jalan pembangunan (§38) — ditampilkan agar progres antar phase terlihat jelas.
$phases = [
    ['Phase 1', 'Foundation — CI4, autentikasi, design system, tema', true],
    ['Phase 2', 'Master Data — sekuritas, saham, CoA, periode akuntansi', false],
    ['Phase 3', 'Transaction Engine — top up, withdrawal, transfer, beli, jual, dividen, fee', false],
    ['Phase 4', 'Accounting Engine — jurnal, buku besar, reversal, audit trail', false],
    ['Phase 5', 'Portfolio Engine — posisi, average cost, realized & unrealized G/L', false],
    ['Phase 6', 'Reporting — neraca, laba rugi, arus kas, trial balance, bulanan, tahunan', false],
    ['Phase 7', 'Dashboard & UI — chart, filter, penyempurnaan responsive', false],
    ['Phase 8', 'Opening Balance & Closing Period', false],
    ['Phase 9', 'Testing, Security Review & Deployment', false],
];

$rows = '';

foreach ($phases as [$phase, $desc, $done]) {
    $badge = $done
        ? '<span class="badge badge-success badge-sm">Selesai</span>'
        : '<span class="badge badge-ghost badge-sm">Menunggu</span>';

    $rows .= '<tr class="hover">'
        . '<td class="font-medium whitespace-nowrap">' . esc($phase) . '</td>'
        . '<td class="text-sm">' . esc($desc) . '</td>'
        . '<td class="text-right">' . $badge . '</td>'
        . '</tr>';
}
?>

<div class="mt-4">
    <?= component('card', [
        'title'    => 'Peta Jalan Pembangunan',
        'subtitle' => 'Setiap phase diselesaikan penuh sebelum lanjut ke phase berikutnya',
        'flush'    => true,
        'body'     => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Phase</th><th>Lingkup</th><th class="text-right">Status</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
    ]) ?>
</div>

<?= $this->endSection() ?>
