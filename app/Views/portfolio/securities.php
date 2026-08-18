<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php $t = $snapshot['totals']; ?>

<?= component('page_header', [
    'title'       => 'Portofolio per Sekuritas',
    'subtitle'    => 'Kas dan kepemilikan pada masing-masing rekening per ' . fmt_date($snapshot['as_of']) . '.',
    'breadcrumbs' => [['label' => 'Portofolio'], ['label' => 'Per Sekuritas']],
]) ?>

<?= component('unpriced_notice', ['count' => $t['unpriced_count'], 'bookValue' => fmt_rupiah($t['unpriced_book_value']->toFloat())]) ?>

<?php
$rows = '';

foreach ($snapshot['by_securities'] as $s) {
    $rows .= '<tr class="hover">'
        . '<td class="font-mono font-semibold whitespace-nowrap">' . esc($s['securities_code']) . '</td>'
        . '<td class="text-sm">' . esc($s['securities_name']) . '<br>'
            . '<span class="text-xs text-base-content/50">' . esc($s['account_label']) . '</span></td>'
        . '<td class="num">' . esc(fmt_money($s['cash']->toFloat())) . '</td>'
        . '<td class="num">' . esc($s['holdings']) . '</td>'
        . '<td class="num">' . esc(fmt_money($s['book_value']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($s['market_value']->toFloat()))
            . ($s['unpriced_book_value']->isZero() ? '' : '<br><span class="text-xs text-warning">+'
                . esc(fmt_money($s['unpriced_book_value']->toFloat())) . ' belum berharga</span>') . '</td>'
        . '<td class="num ' . amount_class($s['unrealized']->toFloat()) . '">' . esc(fmt_signed($s['unrealized']->toFloat())) . '</td>'
        . '<td class="num font-semibold">' . esc(fmt_money($s['net_worth']->toFloat())) . '</td>'
        . '</tr>';
}

$rows .= '<tr class="font-semibold border-t-2 border-base-300">'
    . '<td colspan="2" class="text-right">Total</td>'
    . '<td class="num">' . esc(fmt_money($t['cash']->toFloat())) . '</td>'
    . '<td class="num">' . esc($t['position_count']) . '</td>'
    . '<td class="num">' . esc(fmt_money($t['book_value']->toFloat())) . '</td>'
    . '<td class="num">' . esc(fmt_money($t['market_value']->toFloat())) . '</td>'
    . '<td class="num ' . amount_class($t['unrealized']->toFloat()) . '">' . esc(fmt_signed($t['unrealized']->toFloat())) . '</td>'
    . '<td class="num">' . esc(fmt_money($t['net_worth']->toFloat())) . '</td>'
    . '</tr>';
?>

<?= component('card', [
    'flush' => true,
    'body'  => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr><th>Kode</th><th>Sekuritas</th><th class="num">Kas</th><th class="num">Posisi</th>'
        . '<th class="num">Book Value</th><th class="num">Market Value</th>'
        . '<th class="num">Unrealized</th><th class="num">Net Worth</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
