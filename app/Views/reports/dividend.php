<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Laporan Dividen',
    'subtitle'    => fmt_date($from) . ' — ' . fmt_date($to),
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Dividen']],
]) ?>

<?= component('report_range', ['action' => site_url('reports/dividend'), 'from' => $from, 'to' => $to]) ?>

<div class="grid gap-3 sm:grid-cols-3 mb-4">
    <?= component('stat', ['label' => 'Dividen Bruto', 'value' => fmt_rupiah($report['total_gross']->toFloat()), 'sub' => 'Tercatat sebagai pendapatan']) ?>
    <?= component('stat', ['label' => 'Pajak Dividen', 'value' => fmt_rupiah($report['total_tax']->toFloat()), 'sub' => 'Dibebankan ke akun 5200']) ?>
    <?= component('stat', ['label' => 'Diterima (netto)', 'value' => fmt_rupiah($report['total_net']->toFloat()), 'tone' => 'primary']) ?>
</div>

<?php
$rows = '';

foreach ($report['rows'] as $row) {
    $rows .= '<tr class="hover">'
        . '<td class="whitespace-nowrap">' . esc(fmt_date($row['transaction_date'])) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['transaction_number']) . '</td>'
        . '<td class="font-mono font-semibold">' . esc($row['ticker']) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['securities_code']) . '</td>'
        . '<td class="num">' . esc(fmt_qty($row['quantity_eligible'])) . '</td>'
        . '<td class="num">' . esc(fmt_price((float) $row['dividend_per_share'])) . '</td>'
        . '<td class="num">' . esc(fmt_money((float) $row['gross_dividend'])) . '</td>'
        . '<td class="num">' . esc(fmt_money((float) $row['tax'])) . '</td>'
        . '<td class="num font-medium">' . esc(fmt_money((float) $row['net_dividend'])) . '</td>'
        . '</tr>';
}
?>

<?= component('card', [
    'flush' => true,
    'body'  => $report['rows'] === []
        ? component('empty_state', ['title' => 'Belum ada dividen pada rentang ini'])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Tanggal</th><th>Nomor</th><th>Ticker</th><th>Sekuritas</th>'
            . '<th class="num">Lembar</th><th class="num">Per Lembar</th>'
            . '<th class="num">Bruto</th><th class="num">Pajak</th><th class="num">Netto</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
