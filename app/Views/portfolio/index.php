<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var array $snapshot */
$t = $snapshot['totals'];
?>

<?= component('page_header', [
    'title'       => 'Portofolio Global',
    'subtitle'    => 'Gabungan seluruh sekuritas per ' . fmt_date($snapshot['as_of']) . '.',
    'breadcrumbs' => [['label' => 'Portofolio'], ['label' => 'Global']],
    'actions'     => '<form method="get" class="flex items-end gap-2">'
        . '<input type="date" name="as_of" value="' . esc($asOf, 'attr') . '" class="input input-bordered input-sm">'
        . '<button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button></form>',
]) ?>

<?= component('negative_cash_notice', ['accounts' => $t['negative_cash']]) ?>

<?= component('unpriced_notice', ['count' => $t['unpriced_count'], 'bookValue' => fmt_rupiah($t['unpriced_book_value']->toFloat())]) ?>

<div class="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-6">
    <?= component('stat', ['label' => 'Total Kas', 'value' => fmt_rupiah($t['cash']->toFloat()), 'icon' => 'database', 'tone' => 'primary']) ?>
    <?= component('stat', ['label' => 'Book Value', 'value' => fmt_rupiah($t['book_value']->toFloat()), 'icon' => 'book']) ?>
    <?= component('stat', [
        'label' => 'Market Value',
        'value' => fmt_rupiah($t['market_value']->toFloat()),
        'sub'   => $t['unpriced_count'] > 0 ? 'Belum termasuk ' . $t['unpriced_count'] . ' posisi tanpa harga' : null,
        'icon'  => 'chart',
    ]) ?>
    <?= component('stat', [
        'label' => 'Total Net Worth',
        'value' => fmt_rupiah($t['net_worth']->toFloat()),
        'sub'   => 'Kas + market value',
        'icon'  => 'dashboard',
        'tone'  => 'primary',
    ]) ?>
</div>

<div class="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 mb-6">
    <?= component('stat', [
        'label'      => 'Unrealized Gain/Loss',
        'value'      => fmt_signed($t['unrealized']->toFloat()),
        'sub'        => ($t['unrealized_pct'] !== null ? fmt_percent($t['unrealized_pct'], 2, true) . ' · ' : '')
            . 'tidak masuk laba periode berjalan',
        'valueClass' => amount_class($t['unrealized']->toFloat()),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Realized Gain/Loss',
        'value'      => fmt_signed($t['realized_net']->toFloat()),
        'sub'        => 'Sudah masuk laba periode berjalan',
        'valueClass' => amount_class($t['realized_net']->toFloat()),
    ]) ?>
    <?= component('stat', ['label' => 'Dividend Income', 'value' => fmt_rupiah($t['dividend_income']->toFloat())]) ?>
    <?= component('stat', ['label' => 'Broker Fee', 'value' => fmt_rupiah($t['broker_fee']->toFloat()), 'sub' => 'Fee jual; fee beli masuk book cost']) ?>
    <?= component('stat', ['label' => 'Beban Adm. & Pajak', 'value' => fmt_rupiah($t['admin_expense']->add($t['tax_levy'])->toFloat())]) ?>
    <?= component('stat', [
        'label'      => 'Laba/Rugi Bersih',
        'value'      => fmt_signed($t['net_profit']->toFloat()),
        'sub'        => 'Realized + dividen − seluruh beban',
        'valueClass' => amount_class($t['net_profit']->toFloat()),
        'tone'       => $t['net_profit']->isNegative() ? 'error' : 'success',
    ]) ?>
</div>

<?php
$rows = '';

foreach ($snapshot['positions'] as $p) {
    $rows .= '<tr class="hover">'
        . '<td class="font-mono font-semibold">' . esc($p['ticker']) . '</td>'
        . '<td class="font-mono text-xs">' . esc($p['securities_code']) . '</td>'
        . '<td class="num">' . esc(fmt_qty($p['quantity'])) . '</td>'
        . '<td class="num">' . esc(fmt_lot($p['quantity'])) . '</td>'
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
    'title'    => 'Seluruh Posisi',
    'subtitle' => count($snapshot['positions']) . ' posisi aktif',
    'flush'    => true,
    'body'     => $snapshot['positions'] === []
        ? component('empty_state', [
            'title'       => 'Belum ada posisi saham',
            'description' => 'Catat pembelian saham terlebih dahulu.',
            'icon'        => 'chart',
        ])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Ticker</th><th>Sekuritas</th><th class="num">Lembar</th><th class="num">Lot</th>'
            . '<th class="num">Avg Cost</th><th class="num">Book Value</th><th class="num">Harga</th>'
            . '<th class="num">Market Value</th><th class="num">Unrealized</th><th class="num">Return</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
