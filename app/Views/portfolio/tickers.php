<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php $t = $snapshot['totals']; ?>

<?= component('page_header', [
    'title'       => 'Portofolio per Saham',
    'subtitle'    => 'Total kepemilikan tiap ticker lintas seluruh sekuritas per ' . fmt_date($snapshot['as_of']) . '.',
    'breadcrumbs' => [['label' => 'Portofolio'], ['label' => 'Per Saham']],
]) ?>

<?= component('unpriced_notice', ['count' => $t['unpriced_count'], 'bookValue' => fmt_rupiah($t['unpriced_book_value']->toFloat())]) ?>

<?php if ($snapshot['by_ticker'] === []): ?>
    <?= component('card', [
        'flush' => true,
        'body'  => component('empty_state', ['title' => 'Belum ada posisi saham', 'icon' => 'chart']),
    ]) ?>
<?php else: ?>
    <div class="space-y-4">
    <?php foreach ($snapshot['by_ticker'] as $ticker): ?>
        <?php
        // Rincian per sekuritas, persis seperti contoh §5.
        $breakdown = '';

        foreach ($ticker['breakdown'] as $b) {
            $breakdown .= '<tr class="hover">'
                . '<td class="font-mono text-xs">' . esc($b['securities_code']) . '</td>'
                . '<td class="text-xs text-base-content/60">' . esc($b['account_label']) . '</td>'
                . '<td class="num">' . esc(fmt_qty($b['quantity'])) . '</td>'
                . '<td class="num">' . esc(fmt_lot($b['quantity'])) . '</td>'
                . '<td class="num">' . esc(fmt_avg_cost($b['average_cost']->toFloat())) . '</td>'
                . '<td class="num">' . esc(fmt_money($b['book_value']->toFloat())) . '</td>'
                . '</tr>';
        }

        $breakdown .= '<tr class="font-semibold border-t-2 border-base-300">'
            . '<td colspan="2" class="text-right">Total</td>'
            . '<td class="num">' . esc(fmt_qty($ticker['quantity'])) . '</td>'
            . '<td class="num">' . esc(fmt_lot($ticker['quantity'])) . '</td>'
            . '<td class="num">' . esc(fmt_avg_cost($ticker['average_cost']->toFloat())) . '</td>'
            . '<td class="num">' . esc(fmt_money($ticker['book_value']->toFloat())) . '</td>'
            . '</tr>';

        $stats = '<div class="grid gap-3 grid-cols-2 lg:grid-cols-4 mb-3">'
            . component('stat', [
                'label' => 'Harga Pasar',
                'value' => $ticker['has_price'] ? fmt_price($ticker['market_price']->toFloat()) : 'belum ada',
                'sub'   => $ticker['has_price'] ? 'per ' . fmt_date($ticker['price_date']) : null,
            ])
            . component('stat', [
                'label' => 'Market Value',
                'value' => $ticker['has_price'] ? fmt_rupiah($ticker['market_value']->toFloat()) : '-',
            ])
            . component('stat', [
                'label'      => 'Unrealized',
                'value'      => $ticker['has_price'] ? fmt_signed($ticker['unrealized']->toFloat()) : '-',
                'valueClass' => $ticker['has_price'] ? amount_class($ticker['unrealized']->toFloat()) : '',
            ])
            . component('stat', [
                'label'      => 'Return',
                'value'      => $ticker['return_pct'] !== null ? fmt_percent($ticker['return_pct'], 2, true) : '-',
                'valueClass' => $ticker['return_pct'] !== null ? amount_class($ticker['return_pct']) : '',
            ])
            . '</div>';
        ?>

        <?= component('card', [
            'title'    => $ticker['ticker'] . ' — ' . $ticker['company_name'],
            'subtitle' => 'Average cost gabungan ' . fmt_avg_cost($ticker['average_cost']->toFloat())
                . '. Book cost tiap sekuritas tetap dicatat terpisah.',
            'body'     => $stats
                . '<div class="overflow-x-auto"><table class="table table-sm">'
                . '<thead><tr><th>Sekuritas</th><th>Rekening</th><th class="num">Lembar</th><th class="num">Lot</th>'
                . '<th class="num">Avg Cost</th><th class="num">Book Value</th></tr></thead>'
                . '<tbody>' . $breakdown . '</tbody></table></div>',
        ]) ?>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
