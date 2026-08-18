<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php $t = $snapshot['totals']; ?>

<?= component('page_header', [
    'title'       => 'Unrealized Gain/Loss',
    'subtitle'    => 'Selisih market value terhadap book value per ' . fmt_date($snapshot['as_of']) . '.',
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Unrealized G/L']],
]) ?>

<div class="mb-4 no-print">
    <?= component('card', [
        'body' => '<form method="get" class="flex items-end gap-2">'
            . component('form/input', ['name' => 'as_of', 'label' => 'Per Tanggal', 'type' => 'date', 'value' => $asOf, 'class' => 'w-48'])
            . '<button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button>'
            . '<button type="button" class="btn btn-sm btn-ghost" onclick="window.print()">Cetak</button>'
            . '</form>',
    ]) ?>
</div>

<div class="alert alert-info mb-4">
    <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
    <div class="text-sm">
        <p>Angka di halaman ini tidak masuk laba rugi.</p>
        <p class="text-xs opacity-80 mt-0.5">
            Unrealized gain/loss tidak pernah dijurnal dan tidak mempengaruhi laba periode
            berjalan (§13). Ia hanya berubah menjadi realized saat saham benar-benar dijual.
        </p>
    </div>
</div>

<?= component('unpriced_notice', ['count' => $t['unpriced_count'], 'bookValue' => fmt_rupiah($t['unpriced_book_value']->toFloat())]) ?>

<div class="grid gap-3 sm:grid-cols-4 mb-4">
    <?= component('stat', ['label' => 'Book Value', 'value' => fmt_rupiah($t['book_value']->toFloat())]) ?>
    <?= component('stat', ['label' => 'Market Value', 'value' => fmt_rupiah($t['market_value']->toFloat())]) ?>
    <?= component('stat', [
        'label'      => 'Unrealized G/L',
        'value'      => fmt_signed($t['unrealized']->toFloat()),
        'valueClass' => amount_class($t['unrealized']->toFloat()),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Return',
        'value'      => $t['unrealized_pct'] !== null ? fmt_percent($t['unrealized_pct'], 2, true) : '-',
        'valueClass' => $t['unrealized_pct'] !== null ? amount_class($t['unrealized_pct']) : '',
    ]) ?>
</div>

<?php
$rows = '';

foreach ($snapshot['positions'] as $p) {
    $rows .= '<tr class="hover">'
        . '<td class="font-mono font-semibold">' . esc($p['ticker']) . '</td>'
        . '<td class="font-mono text-xs">' . esc($p['securities_code']) . '</td>'
        . '<td class="num">' . esc(fmt_qty($p['quantity'])) . '</td>'
        . '<td class="num">' . esc(fmt_avg_cost($p['average_cost']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($p['book_value']->toFloat())) . '</td>'
        . '<td class="num">' . ($p['has_price'] ? esc(fmt_price($p['market_price']->toFloat())) : '<span class="text-warning text-xs">belum ada</span>') . '</td>'
        . '<td class="num">' . ($p['has_price'] ? esc(fmt_money($p['market_value']->toFloat())) : '-') . '</td>'
        . '<td class="num ' . ($p['has_price'] ? amount_class($p['unrealized']->toFloat()) : '') . '">'
            . ($p['has_price'] ? esc(fmt_signed($p['unrealized']->toFloat())) : '-') . '</td>'
        . '<td class="num ' . ($p['return_pct'] !== null ? amount_class($p['return_pct']) : '') . '">'
            . ($p['return_pct'] !== null ? esc(fmt_percent($p['return_pct'], 2, true)) : '-') . '</td>'
        . '</tr>';
}
?>

<?= component('card', [
    'flush' => true,
    'body'  => $snapshot['positions'] === []
        ? component('empty_state', ['title' => 'Tidak ada posisi pada tanggal ini', 'icon' => 'chart'])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Ticker</th><th>Sekuritas</th><th class="num">Lembar</th>'
            . '<th class="num">Avg Cost</th><th class="num">Book Value</th><th class="num">Harga</th>'
            . '<th class="num">Market Value</th><th class="num">Unrealized</th><th class="num">Return</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
