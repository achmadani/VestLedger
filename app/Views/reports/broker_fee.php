<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Laporan Broker Fee',
    'subtitle'    => 'Seluruh biaya transaksi saham, ' . fmt_date($from) . ' — ' . fmt_date($to),
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Broker Fee']],
]) ?>

<?= component('report_range', ['action' => site_url('reports/broker-fee'), 'from' => $from, 'to' => $to]) ?>

<div class="alert alert-info mb-4">
    <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
    <div class="text-sm">
        <p>Biaya pembelian ikut ditampilkan meskipun bukan beban.</p>
        <p class="text-xs opacity-80 mt-0.5">
            Biaya sisi beli dikapitalisasi ke book cost, sehingga tidak muncul di Laba Rugi.
            Menyembunyikannya di sini akan membuat total biaya yang benar-benar dibayar
            tampak lebih kecil daripada kenyataan.
        </p>
    </div>
</div>

<div class="grid gap-3 sm:grid-cols-3 mb-4">
    <?= component('stat', [
        'label' => 'Biaya Beli (dikapitalisasi)',
        'value' => fmt_rupiah($report['capitalised']->toFloat()),
        'sub'   => 'Masuk book cost, bukan beban',
    ]) ?>
    <?= component('stat', [
        'label' => 'Biaya Jual (dibebankan)',
        'value' => fmt_rupiah($report['expensed']->toFloat()),
        'sub'   => 'Akun 5000 dan 5200',
    ]) ?>
    <?= component('stat', [
        'label' => 'Total Biaya Dibayar',
        'value' => fmt_rupiah($report['total']->toFloat()),
        'tone'  => 'primary',
    ]) ?>
</div>

<?php
$rows = '';

foreach ($report['rows'] as $row) {
    $badge = $row['type'] === 'buy'
        ? '<span class="badge badge-info badge-xs">beli</span>'
        : '<span class="badge badge-warning badge-xs">jual</span>';

    $rows .= '<tr class="hover">'
        . '<td class="whitespace-nowrap">' . esc(fmt_date($row['transaction_date'])) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['transaction_number']) . '</td>'
        . '<td>' . $badge . '</td>'
        . '<td class="font-mono font-semibold">' . esc($row['ticker']) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['securities_code']) . '</td>'
        . '<td class="num">' . esc(fmt_money((float) $row['broker_fee'])) . '</td>'
        . '<td class="num">' . esc(fmt_money((float) $row['tax'])) . '</td>'
        . '<td class="num">' . esc(fmt_money((float) $row['levy'])) . '</td>'
        . '<td class="num font-medium">' . esc(fmt_money((float) $row['total_charges'])) . '</td>'
        . '</tr>';
}
?>

<?= component('card', [
    'flush' => true,
    'body'  => $report['rows'] === []
        ? component('empty_state', ['title' => 'Belum ada biaya transaksi pada rentang ini'])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Tanggal</th><th>Nomor</th><th>Jenis</th><th>Ticker</th><th>Sekuritas</th>'
            . '<th class="num">Broker Fee</th><th class="num">Pajak</th><th class="num">Levy</th>'
            . '<th class="num">Total</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
