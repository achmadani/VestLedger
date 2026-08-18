<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$t = $snapshot['totals'];

$typeLabels = [
    'buy' => 'Beli', 'sell' => 'Jual', 'dividend' => 'Dividen', 'top_up' => 'Top Up',
    'withdrawal' => 'Withdrawal', 'transfer' => 'Transfer', 'admin_fee' => 'Biaya Adm.',
];
?>

<?= component('page_header', [
    'title'    => 'Dashboard',
    'subtitle' => 'Ringkasan posisi portofolio per ' . fmt_date($snapshot['as_of']) . '.',
]) ?>

<?= component('negative_cash_notice', ['accounts' => $t['negative_cash']]) ?>

<?= component('unpriced_notice', [
    'count'     => $t['unpriced_count'],
    'bookValue' => fmt_rupiah($t['unpriced_book_value']->toFloat()),
]) ?>

<h2 class="text-sm font-semibold uppercase tracking-wide text-base-content/50 mb-3">Posisi Global</h2>

<div class="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-6">
    <?= component('stat', ['label' => 'Total Kas', 'value' => fmt_rupiah($t['cash']->toFloat()), 'icon' => 'database', 'tone' => 'primary']) ?>
    <?= component('stat', ['label' => 'Book Value', 'value' => fmt_rupiah($t['book_value']->toFloat()), 'icon' => 'book']) ?>
    <?= component('stat', ['label' => 'Market Value', 'value' => fmt_rupiah($t['market_value']->toFloat()), 'icon' => 'chart']) ?>
    <?= component('stat', [
        'label' => 'Total Net Worth',
        'value' => fmt_rupiah($t['net_worth']->toFloat()),
        'sub'   => 'Kas + market value portofolio',
        'icon'  => 'dashboard',
        'tone'  => 'primary',
    ]) ?>
</div>

<div class="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 mb-6">
    <?= component('stat', [
        'label'      => 'Unrealized Gain/Loss',
        'value'      => fmt_signed($t['unrealized']->toFloat()),
        'sub'        => 'Belum masuk laba rugi periode berjalan',
        'valueClass' => amount_class($t['unrealized']->toFloat()),
    ]) ?>
    <?= component('stat', [
        'label'      => 'Realized Gain/Loss',
        'value'      => fmt_signed($t['realized_net']->toFloat()),
        'sub'        => 'Dari transaksi jual yang sudah terjadi',
        'valueClass' => amount_class($t['realized_net']->toFloat()),
    ]) ?>
    <?= component('stat', ['label' => 'Dividend Income', 'value' => fmt_rupiah($t['dividend_income']->toFloat())]) ?>
    <?= component('stat', ['label' => 'Broker Fee', 'value' => fmt_rupiah($t['broker_fee']->toFloat()), 'sub' => 'Fee jual + biaya administrasi']) ?>
    <?= component('stat', ['label' => 'Biaya Lain & Pajak', 'value' => fmt_rupiah($t['admin_expense']->add($t['tax_levy'])->toFloat())]) ?>
    <?= component('stat', [
        'label'      => 'Laba/Rugi Bersih',
        'value'      => fmt_signed($t['net_profit']->toFloat()),
        'sub'        => 'Realized + dividen − seluruh beban',
        'valueClass' => amount_class($t['net_profit']->toFloat()),
    ]) ?>
</div>

<?php
// Komposisi portofolio menurut nilai, memakai market value bila ada.
$composition = [];

foreach ($holdings as $h) {
    $value = $h['has_price'] ? $h['market_value'] : $h['book_value'];

    $composition[] = [
        'label'     => $h['ticker'],
        'sublabel'  => $h['has_price'] ? null : 'dinilai pada book value — harga belum diinput',
        'value'     => $value->toFloat(),
        'formatted' => fmt_rupiah($value->toFloat()),
    ];
}
?>

<div class="grid gap-4 grid-cols-1 lg:grid-cols-3 mb-4">
    <div class="lg:col-span-2">
        <?= component('card', [
            'title'    => 'Perkembangan Aset ' . $year,
            'subtitle' => 'Saldo akhir tiap bulan menurut NILAI BUKU, bukan market value',
            'body'     => component('chart_area', ['series' => $series, 'title' => 'Perkembangan aset ' . $year])
                . '<p class="text-[11px] text-base-content/50 mt-2">'
                . 'Nilai buku dipakai karena market value tiap akhir bulan memerlukan harga historis '
                . 'yang belum tentu diinput; memakai harga terbaru akan membuat grafik masa lalu '
                . 'berubah setiap kali harga hari ini diperbarui.</p>',
        ]) ?>
    </div>

    <div>
        <?= component('card', [
            'title'    => 'Komposisi Portofolio',
            'subtitle' => 'Lima posisi terbesar menurut nilai',
            'body'     => $composition === []
                ? component('empty_state', ['title' => 'Belum ada posisi', 'icon' => 'chart'])
                : component('chart_bars', ['items' => $composition]),
        ]) ?>
    </div>
</div>

<div class="grid gap-4 grid-cols-1 lg:grid-cols-2">
    <?php
    $secRows = '';

    foreach ($snapshot['by_securities'] as $s) {
        $secRows .= '<tr class="hover">'
            . '<td class="font-mono text-xs font-semibold">' . esc($s['securities_code']) . '</td>'
            . '<td class="num">' . esc(fmt_money($s['cash']->toFloat())) . '</td>'
            . '<td class="num">' . esc(fmt_money($s['market_value']->toFloat())) . '</td>'
            . '<td class="num ' . amount_class($s['unrealized']->toFloat()) . '">'
                . esc(fmt_signed($s['unrealized']->toFloat())) . '</td>'
            . '<td class="num font-medium">' . esc(fmt_money($s['net_worth']->toFloat())) . '</td>'
            . '</tr>';
    }
    ?>

    <?= component('card', [
        'title'    => 'Portofolio per Sekuritas',
        'subtitle' => 'Kas, market value, dan net worth tiap rekening',
        'flush'    => true,
        'actions'  => '<a href="' . site_url('portfolio/securities') . '" class="btn btn-ghost btn-xs">Detail</a>',
        'body'     => $snapshot['by_securities'] === []
            ? component('empty_state', ['title' => 'Belum ada sekuritas terdaftar', 'icon' => 'database'])
            : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
                . '<thead><tr><th>Kode</th><th class="num">Kas</th><th class="num">Market</th>'
                . '<th class="num">Unrealized</th><th class="num">Net Worth</th></tr></thead>'
                . '<tbody>' . $secRows . '</tbody></table></div>',
    ]) ?>

    <?php
    $txRows = '';

    foreach ($recent as $row) {
        $txRows .= '<tr class="hover' . ($row['status'] === 'reversed' ? ' opacity-50' : '') . '">'
            . '<td class="whitespace-nowrap text-xs">' . esc(fmt_date($row['transaction_date'])) . '</td>'
            . '<td class="text-xs">' . esc($typeLabels[$row['type_label']] ?? $row['type_label']) . '</td>'
            . '<td class="font-mono text-xs">' . esc($row['ticker'] ?? $row['securities_code']) . '</td>'
            . '<td class="num text-xs">' . esc(fmt_money(abs((float) $row['amount']))) . '</td>'
            . '</tr>';
    }
    ?>

    <?= component('card', [
        'title'    => 'Transaksi Terakhir',
        'subtitle' => '10 transaksi terbaru dari seluruh sekuritas',
        'flush'    => true,
        'actions'  => '<a href="' . site_url('transactions') . '" class="btn btn-ghost btn-xs">Semua</a>',
        'body'     => $recent === []
            ? component('empty_state', ['title' => 'Belum ada transaksi', 'icon' => 'transaction'])
            : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
                . '<thead><tr><th>Tanggal</th><th>Jenis</th><th>Kode</th><th class="num">Nilai</th></tr></thead>'
                . '<tbody>' . $txRows . '</tbody></table></div>',
    ]) ?>
</div>

<?php
$holdingRows = '';

foreach ($holdings as $h) {
    $value = $h['has_price'] ? $h['market_value'] : $h['book_value'];

    $holdingRows .= '<tr class="hover">'
        . '<td class="font-mono font-semibold">' . esc($h['ticker']) . '</td>'
        . '<td class="text-sm">' . esc($h['company_name']) . '</td>'
        . '<td class="num">' . esc(fmt_qty($h['quantity'])) . '</td>'
        . '<td class="num">' . esc(fmt_money($value->toFloat()))
            . ($h['has_price'] ? '' : ' <span class="text-warning text-xs">(book)</span>') . '</td>'
        . '<td class="num ' . ($h['has_price'] ? amount_class($h['unrealized']->toFloat()) : '') . '">'
            . ($h['has_price'] ? esc(fmt_signed($h['unrealized']->toFloat())) : '-') . '</td>'
        . '<td class="num ' . ($h['return_pct'] !== null ? amount_class($h['return_pct']) : '') . '">'
            . ($h['return_pct'] !== null ? esc(fmt_percent($h['return_pct'], 2, true)) : '-') . '</td>'
        . '</tr>';
}
?>

<div class="mt-4">
    <?= component('card', [
        'title'    => 'Top Holdings',
        'subtitle' => 'Lima posisi terbesar menurut nilai',
        'flush'    => true,
        'actions'  => '<a href="' . site_url('portfolio/tickers') . '" class="btn btn-ghost btn-xs">Per Saham</a>',
        'body'     => $holdings === []
            ? component('empty_state', ['title' => 'Belum ada posisi saham', 'icon' => 'chart'])
            : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
                . '<thead><tr><th>Ticker</th><th>Perusahaan</th><th class="num">Lembar</th>'
                . '<th class="num">Nilai</th><th class="num">Unrealized</th><th class="num">Return</th></tr></thead>'
                . '<tbody>' . $holdingRows . '</tbody></table></div>',
    ]) ?>
</div>
<?= $this->endSection() ?>
