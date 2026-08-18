<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Realized Gain/Loss',
    'subtitle'    => 'Rincian per transaksi jual, ' . fmt_date($from) . ' — ' . fmt_date($to),
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Realized G/L']],
]) ?>

<?= component('report_range', [
    'action' => site_url('reports/realized'),
    'from'   => $from,
    'to'     => $to,
    'extra'  => component('form/select', [
        'name' => 'stock_id', 'label' => 'Saham', 'options' => $stocks,
        'value' => (string) ($filters['stock_id'] ?: ''), 'placeholder' => 'Semua',
    ]),
]) ?>

<div class="grid gap-3 sm:grid-cols-4 mb-4">
    <?= component('stat', ['label' => 'Total Penjualan (bruto)', 'value' => fmt_rupiah($report['total_gross']->toFloat())]) ?>
    <?= component('stat', ['label' => 'Book Value Dilepas', 'value' => fmt_rupiah($report['total_book_sold']->toFloat())]) ?>
    <?= component('stat', ['label' => 'Total Biaya', 'value' => fmt_rupiah($report['total_charges']->toFloat())]) ?>
    <?= component('stat', [
        'label'      => 'Realized G/L (netto)',
        'value'      => fmt_signed($report['total_gain_net']->toFloat()),
        'sub'        => 'Setelah fee dan pajak (§11 Step 3)',
        'valueClass' => amount_class($report['total_gain_net']->toFloat()),
    ]) ?>
</div>

<?php
$rows = '';

foreach ($report['rows'] as $row) {
    $rows .= '<tr class="hover">'
        . '<td class="whitespace-nowrap">' . esc(fmt_date($row['transaction_date'])) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['transaction_number']) . '</td>'
        . '<td class="font-mono font-semibold">' . esc($row['ticker']) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['securities_code']) . '</td>'
        . '<td class="num">' . esc(fmt_qty($row['quantity'])) . '</td>'
        . '<td class="num">' . esc(fmt_price((float) $row['price'])) . '</td>'
        . '<td class="num">' . esc(fmt_money($row['gross_money']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($row['book_money']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($row['charges_money']->toFloat())) . '</td>'
        . '<td class="num ' . amount_class($row['gain_gross']->toFloat()) . '">' . esc(fmt_signed($row['gain_gross']->toFloat())) . '</td>'
        . '<td class="num font-medium ' . amount_class($row['gain_net']->toFloat()) . '">' . esc(fmt_signed($row['gain_net']->toFloat())) . '</td>'
        . '</tr>';
}
?>

<?= component('card', [
    'subtitle' => 'Kolom "Realized (jurnal)" adalah gross − book value, yaitu yang masuk akun 4000/4001. '
        . 'Kolom "Realized (netto)" sudah dikurangi fee dan pajak.',
    'flush'    => true,
    'body'     => $report['rows'] === []
        ? component('empty_state', ['title' => 'Belum ada penjualan pada rentang ini', 'icon' => 'transaction'])
        : '<div class="overflow-x-auto"><table class="table table-xs table-zebra">'
            . '<thead><tr><th>Tanggal</th><th>Nomor</th><th>Ticker</th><th>Sekuritas</th>'
            . '<th class="num">Lembar</th><th class="num">Harga</th><th class="num">Bruto</th>'
            . '<th class="num">Book Value</th><th class="num">Biaya</th>'
            . '<th class="num">Realized (jurnal)</th><th class="num">Realized (netto)</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
