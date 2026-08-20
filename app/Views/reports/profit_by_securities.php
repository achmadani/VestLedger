<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$t = $report['totals'];

$cell = static fn (\App\ValueObjects\Money $m, bool $signed = false): string => '<td class="num'
    . ($signed ? ' ' . amount_class($m->toFloat()) : '') . '">'
    . esc($signed ? fmt_signed($m->toFloat()) : fmt_money($m->toFloat())) . '</td>';

$rowsHtml = '';

foreach ($report['rows'] as $row) {
    $unattributed = $row['securities_account_id'] === null;

    $label = $unattributed
        ? '<span class="text-warning">' . esc($row['label']) . '</span>'
        : '<span class="font-medium">' . esc($row['label']) . '</span>'
            . ($row['securities_name'] !== ''
                ? '<br><span class="text-xs text-base-content/60">' . esc($row['securities_name']) . '</span>'
                : '');

    $rowsHtml .= '<tr class="hover">'
        . '<td>' . $label . '</td>'
        . $cell($row['realized_net'], true)
        . $cell($row['dividend'])
        . $cell($row['broker_fee'])
        . $cell($row['tax_levy'])
        . $cell($row['admin_expense'])
        . $cell($row['net_profit'], true)
        . $cell($row['unrealized'], true)
        . '</tr>';
}

if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="8" class="text-center text-base-content/40 text-sm py-6">'
        . 'Belum ada aktivitas pada rentang ini.</td></tr>';
}
?>

<?= component('page_header', [
    'title'       => 'Laba Rugi per Sekuritas',
    'subtitle'    => 'Perbandingan hasil tiap rekening sekuritas, '
        . fmt_date($report['from']) . ' — ' . fmt_date($report['to']),
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Laba Rugi per Sekuritas']],
    'actions'     => '<a href="' . site_url('reports/income-statement') . '?from=' . urlencode($from)
        . '&to=' . urlencode($to) . '" class="btn btn-sm btn-ghost">Laba Rugi global</a>',
]) ?>

<?= component('report_range', [
    'action' => site_url('reports/profit-by-securities'),
    'from'   => $from,
    'to'     => $to,
]) ?>

<div class="grid gap-3 sm:grid-cols-3 mb-4">
    <?= component('stat', [
        'label'      => 'Laba/Rugi Bersih (seluruhnya)',
        'value'      => fmt_signed($t['net_profit']->toFloat()),
        'sub'        => 'Sama dengan Laba Rugi global periode ini',
        'valueClass' => amount_class($t['net_profit']->toFloat()),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Realized G/L (netto)',
        'value'      => fmt_signed($t['realized_net']->toFloat()),
        'sub'        => 'Selisih akun 4000 dan 4001',
        'valueClass' => amount_class($t['realized_net']->toFloat()),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Unrealized',
        'value'      => fmt_signed($t['unrealized']->toFloat()),
        'sub'        => 'Potret per ' . fmt_date($report['to']) . ', di luar laba rugi',
        'valueClass' => amount_class($t['unrealized']->toFloat()),
    ]) ?>
</div>

<?= component('card', [
    'title'    => 'Rincian per Sekuritas',
    'subtitle' => 'Kolom Laba/Rugi Bersih adalah hasil yang sudah terealisasi dan tercatat di buku besar. '
        . 'Unrealized berdiri terpisah — ia tidak pernah dijurnal.',
    'flush'    => true,
    'body'     => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr>'
        . '<th>Sekuritas</th>'
        . '<th class="num">Realized G/L</th>'
        . '<th class="num">Dividen</th>'
        . '<th class="num">Fee Broker</th>'
        . '<th class="num">Pajak &amp; Levy</th>'
        . '<th class="num">Biaya Adm.</th>'
        . '<th class="num">Laba/Rugi Bersih</th>'
        . '<th class="num">Unrealized</th>'
        . '</tr></thead>'
        . '<tbody>' . $rowsHtml . '</tbody>'
        . '<tfoot><tr class="font-semibold border-t-2 border-base-300">'
        . '<td>Total</td>'
        . $cell($t['realized_net'], true)
        . $cell($t['dividend'])
        . $cell($t['broker_fee'])
        . $cell($t['tax_levy'])
        . $cell($t['admin_expense'])
        . $cell($t['net_profit'], true)
        . $cell($t['unrealized'], true)
        . '</tr></tfoot>'
        . '</table></div>',
]) ?>

<?php if ($report['has_unattributed']): ?>
    <div class="alert alert-warning mt-4 text-sm">
        <span>
            Ada baris jurnal pendapatan/beban yang tidak membawa dimensi rekening sekuritas,
            dikumpulkan pada baris <strong>Tanpa sekuritas</strong>. Angka itu sengaja
            ditampilkan, bukan disembunyikan, supaya rincian ini tetap berjumlah persis sama
            dengan Laba Rugi global.
        </span>
    </div>
<?php endif; ?>

<div class="alert alert-info mt-4">
    <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
    <div class="text-sm">
        <p>Ini rincian, bukan laporan keuangan tersendiri.</p>
        <p class="text-xs opacity-80 mt-0.5">
            Entitas pelaporannya tetap satu &mdash; rekening di beberapa sekuritas adalah
            beberapa lokasi aset milik pemilik yang sama, bukan beberapa perusahaan.
            Kolom <strong>Unrealized</strong> dinilai pada <?= esc(fmt_date($report['to'])) ?>
            dan tidak dijumlahkan ke laba, karena kenaikan harga yang belum direalisasi
            tidak pernah dijurnal (&sect;13, &sect;14).
        </p>
    </div>
</div>
<?= $this->endSection() ?>
