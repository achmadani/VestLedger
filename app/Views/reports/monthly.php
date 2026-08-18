<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$c = $report['current'];

$months = [];

for ($m = 1; $m <= 12; $m++) {
    $months[$m] = service('periodicReports')->monthLabel($year, $m);
}

$yearOptions = [];

foreach ($years as $y) {
    $yearOptions[$y] = (string) $y;
}

/** Baris perbandingan: nilai bulan ini, bulan lalu, selisih, dan persentase. */
$compareRow = static function (string $label, string $field) use ($report): string {
    $row = $report['comparison'][$field];

    $pct = $row['change_pct'] === null
        ? '<span class="text-base-content/30">—</span>'
        : '<span class="' . amount_class($row['change_pct']) . '">' . esc(fmt_percent($row['change_pct'], 1, true)) . '</span>';

    return '<tr class="hover">'
        . '<td>' . esc($label) . '</td>'
        . '<td class="num">' . esc(fmt_money($row['current']->toFloat())) . '</td>'
        . '<td class="num text-base-content/60">' . esc(fmt_money($row['previous']->toFloat())) . '</td>'
        . '<td class="num ' . amount_class($row['change']->toFloat()) . '">' . esc(fmt_signed($row['change']->toFloat())) . '</td>'
        . '<td class="num">' . $pct . '</td>'
        . '</tr>';
};
?>

<?= component('page_header', [
    'title'       => 'Laporan Bulanan',
    'subtitle'    => $report['label'],
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Bulanan']],
]) ?>

<div class="mb-4 no-print">
    <?= component('card', [
        'body' => '<form method="get" class="grid gap-3 sm:grid-cols-3 items-end">'
            . component('form/select', ['name' => 'year', 'label' => 'Tahun', 'options' => $yearOptions, 'value' => (string) $year, 'placeholder' => null])
            . component('form/select', ['name' => 'month', 'label' => 'Bulan', 'options' => $months, 'value' => (string) $month, 'placeholder' => null])
            . '<div class="flex gap-2">'
            . '<button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button>'
            . '<button type="button" class="btn btn-sm btn-ghost" onclick="window.print()">Cetak</button>'
            . '</div></form>',
    ]) ?>
</div>

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-4">
    <?= component('stat', ['label' => 'Kas Awal', 'value' => fmt_rupiah($c['beginning_cash']->toFloat())]) ?>
    <?= component('stat', ['label' => 'Kas Akhir', 'value' => fmt_rupiah($c['ending_cash']->toFloat()), 'tone' => 'primary']) ?>
    <?= component('stat', [
        'label'      => 'Laba/Rugi Bulan Ini',
        'value'      => fmt_signed($c['net_profit']->toFloat()),
        'valueClass' => amount_class($c['net_profit']->toFloat()),
    ]) ?>
    <?= component('stat', ['label' => 'Net Worth Akhir', 'value' => fmt_rupiah($c['net_worth']->toFloat()), 'tone' => 'primary']) ?>
</div>

<?php
$flowRows = '';

foreach ([
    ['Top Up', $c['top_up']],
    ['Withdrawal', $c['withdrawal']],
    ['Pembelian Saham (kas keluar)', $c['buy']],
    ['Penjualan Saham (kas masuk)', $c['sell']],
    ['Dividen Diterima (netto)', $c['dividend_net']],
    ['Broker Fee', $c['broker_fee']],
    ['Beban Administrasi', $c['admin_expense']],
    ['Pajak & Levy', $c['tax_levy']],
] as [$label, $value]) {
    $flowRows .= '<tr class="hover"><td>' . esc($label) . '</td>'
        . '<td class="num">' . esc(fmt_money($value->toFloat())) . '</td></tr>';
}

$flowRows .= '<tr class="font-semibold border-t-2 border-base-300">'
    . '<td>Realized Gain/Loss</td>'
    . '<td class="num ' . amount_class($c['realized_net']->toFloat()) . '">'
        . esc(fmt_signed($c['realized_net']->toFloat())) . '</td></tr>';
?>

<div class="grid gap-4 lg:grid-cols-2">
    <?= component('card', [
        'title'    => 'Aktivitas ' . $report['label'],
        'subtitle' => $c['buy_count'] . ' pembelian · ' . $c['sell_count'] . ' penjualan',
        'flush'    => true,
        'body'     => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Pos</th><th class="num">Jumlah</th></tr></thead>'
            . '<tbody>' . $flowRows . '</tbody></table></div>',
    ]) ?>

    <?= component('card', [
        'title'    => 'Posisi Akhir Bulan',
        'flush'    => true,
        'body'     => '<div class="overflow-x-auto"><table class="table table-sm table-zebra"><tbody>'
            . '<tr class="hover"><td>Kas</td><td class="num">' . esc(fmt_money($c['ending_cash']->toFloat())) . '</td></tr>'
            . '<tr class="hover"><td>Book Value Portofolio</td><td class="num">' . esc(fmt_money($c['ending_book_value']->toFloat())) . '</td></tr>'
            . '<tr class="hover"><td>Market Value</td><td class="num">' . esc(fmt_money($c['ending_market_value']->toFloat())) . '</td></tr>'
            . ($c['unpriced_book_value']->isZero() ? '' :
                '<tr class="hover"><td class="text-warning text-xs">Posisi belum berharga (dinilai pada book value)</td>'
                . '<td class="num text-warning">' . esc(fmt_money($c['unpriced_book_value']->toFloat())) . '</td></tr>')
            . '<tr class="hover"><td>Unrealized Gain/Loss</td><td class="num ' . amount_class($c['unrealized']->toFloat()) . '">'
                . esc(fmt_signed($c['unrealized']->toFloat())) . '</td></tr>'
            . '<tr class="font-semibold border-t-2 border-base-300"><td>Net Worth</td>'
                . '<td class="num">' . esc(fmt_money($c['net_worth']->toFloat())) . '</td></tr>'
            . '</tbody></table></div>',
    ]) ?>
</div>

<div class="mt-4">
    <?= component('card', [
        'title'    => 'Perbandingan dengan ' . $report['prev_label'],
        'flush'    => true,
        'body'     => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Pos</th><th class="num">' . esc($report['label']) . '</th>'
            . '<th class="num">' . esc($report['prev_label']) . '</th>'
            . '<th class="num">Selisih</th><th class="num">%</th></tr></thead><tbody>'
            . $compareRow('Top Up', 'top_up')
            . $compareRow('Withdrawal', 'withdrawal')
            . $compareRow('Pembelian', 'buy')
            . $compareRow('Penjualan', 'sell')
            . $compareRow('Dividen', 'dividend_net')
            . $compareRow('Total Biaya', 'total_fees')
            . $compareRow('Realized G/L', 'realized_net')
            . $compareRow('Laba/Rugi Bersih', 'net_profit')
            . $compareRow('Kas Akhir', 'ending_cash')
            . $compareRow('Net Worth', 'net_worth')
            . '</tbody></table></div>',
    ]) ?>
</div>
<?= $this->endSection() ?>
