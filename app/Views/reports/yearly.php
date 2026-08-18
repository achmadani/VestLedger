<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$t = $report['total'];

$yearOptions = [];

foreach ($years as $y) {
    $yearOptions[$y] = (string) $y;
}
?>

<?= component('page_header', [
    'title'       => 'Laporan Tahunan',
    'subtitle'    => 'Tahun ' . $report['year'],
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Tahunan']],
]) ?>

<div class="mb-4 no-print">
    <?= component('card', [
        'body' => '<form method="get" class="flex items-end gap-2">'
            . component('form/select', ['name' => 'year', 'label' => 'Tahun', 'options' => $yearOptions, 'value' => (string) $year, 'placeholder' => null, 'class' => 'w-40'])
            . '<button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button>'
            . '<button type="button" class="btn btn-sm btn-ghost" onclick="window.print()">Cetak</button>'
            . '</form>',
    ]) ?>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-4">
    <?= component('stat', ['label' => 'Modal Disetor (tahun ini)', 'value' => fmt_rupiah($t['top_up']->toFloat())]) ?>
    <?= component('stat', ['label' => 'Withdrawal', 'value' => fmt_rupiah($t['withdrawal']->toFloat())]) ?>
    <?= component('stat', [
        'label'      => 'Laba/Rugi Tahunan',
        'value'      => fmt_signed($t['net_profit']->toFloat()),
        'valueClass' => amount_class($t['net_profit']->toFloat()),
        'tone'       => $t['net_profit']->isNegative() ? 'error' : 'success',
    ]) ?>
    <?= component('stat', ['label' => 'Net Worth Akhir Tahun', 'value' => fmt_rupiah($t['net_worth']->toFloat()), 'tone' => 'primary']) ?>
</div>

<?php
$summaryRows = '';

foreach ([
    ['Total Modal Disetor', $t['top_up']],
    ['Total Withdrawal', $t['withdrawal']],
    ['Total Pembelian', $t['buy']],
    ['Total Penjualan', $t['sell']],
    ['Total Dividen (bruto)', $t['dividend_gross']],
    ['Total Broker Fee', $t['broker_fee']],
    ['Total Beban Administrasi', $t['admin_expense']],
    ['Total Pajak & Levy', $t['tax_levy']],
    ['Total Seluruh Beban', $t['total_fees']],
] as [$label, $value]) {
    $summaryRows .= '<tr class="hover"><td>' . esc($label) . '</td>'
        . '<td class="num">' . esc(fmt_money($value->toFloat())) . '</td></tr>';
}

$summaryRows .= '<tr class="hover font-medium"><td>Total Realized Gain/Loss</td>'
    . '<td class="num ' . amount_class($t['realized_net']->toFloat()) . '">'
    . esc(fmt_signed($t['realized_net']->toFloat())) . '</td></tr>';

$summaryRows .= '<tr class="font-semibold border-t-2 border-base-300"><td>Laba/Rugi Bersih Tahunan</td>'
    . '<td class="num ' . amount_class($t['net_profit']->toFloat()) . '">'
    . esc(fmt_signed($t['net_profit']->toFloat())) . '</td></tr>';

$endingRows = '';

foreach ([
    ['Kas Akhir', $t['ending_cash']],
    ['Book Value Akhir', $t['ending_book_value']],
    ['Market Value Akhir', $t['ending_market_value']],
    ['Unrealized Gain/Loss Akhir', $t['unrealized']],
    ['Net Worth Akhir', $t['net_worth']],
] as [$label, $value]) {
    $endingRows .= '<tr class="hover"><td>' . esc($label) . '</td>'
        . '<td class="num">' . esc(fmt_money($value->toFloat())) . '</td></tr>';
}
?>

<div class="grid gap-4 lg:grid-cols-2 mb-4">
    <?= component('card', [
        'title' => 'Ringkasan Tahun ' . $report['year'],
        'flush' => true,
        'body'  => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<tbody>' . $summaryRows . '</tbody></table></div>',
    ]) ?>

    <?= component('card', [
        'title' => 'Posisi Akhir Tahun',
        'flush' => true,
        'body'  => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<tbody>' . $endingRows . '</tbody></table></div>',
    ]) ?>
</div>

<?php
// Rincian per bulan (§24).
$monthRows = '';

foreach ($report['months'] as $m) {
    $f = $m['figures'];

    $monthRows .= '<tr class="hover">'
        . '<td class="whitespace-nowrap">' . esc($m['label']) . '</td>'
        . '<td class="num">' . esc(fmt_money($f['top_up']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($f['withdrawal']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($f['buy']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($f['sell']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($f['dividend_net']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($f['total_fees']->toFloat())) . '</td>'
        . '<td class="num ' . amount_class($f['realized_net']->toFloat()) . '">' . esc(fmt_signed($f['realized_net']->toFloat())) . '</td>'
        . '<td class="num ' . amount_class($f['net_profit']->toFloat()) . '">' . esc(fmt_signed($f['net_profit']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($f['ending_cash']->toFloat())) . '</td>'
        . '<td class="num font-medium">' . esc(fmt_money($f['net_worth']->toFloat())) . '</td>'
        . '</tr>';
}
?>

<?= component('card', [
    'title'    => 'Rincian per Bulan',
    'subtitle' => 'Angka arus adalah nilai bulan bersangkutan; angka posisi adalah saldo akhir bulan',
    'flush'    => true,
    'body'     => '<div class="overflow-x-auto"><table class="table table-xs table-zebra">'
        . '<thead><tr><th>Bulan</th><th class="num">Top Up</th><th class="num">Withdrawal</th>'
        . '<th class="num">Beli</th><th class="num">Jual</th><th class="num">Dividen</th>'
        . '<th class="num">Biaya</th><th class="num">Realized</th><th class="num">Laba/Rugi</th>'
        . '<th class="num">Kas Akhir</th><th class="num">Net Worth</th></tr></thead>'
        . '<tbody>' . $monthRows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
