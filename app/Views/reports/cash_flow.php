<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Arus Kas',
    'subtitle'    => fmt_date($report['from']) . ' — ' . fmt_date($report['to']) . ' (metode langsung)',
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Arus Kas']],
]) ?>

<?= component('report_range', ['action' => site_url('reports/cash-flow'), 'from' => $from, 'to' => $to]) ?>

<div class="grid gap-3 sm:grid-cols-3 mb-4">
    <?= component('stat', ['label' => 'Kas Awal', 'value' => fmt_rupiah($report['beginning']->toFloat())]) ?>
    <?= component('stat', [
        'label'      => 'Perubahan Bersih',
        'value'      => fmt_signed($report['net_change']->toFloat()),
        'valueClass' => amount_class($report['net_change']->toFloat()),
    ]) ?>
    <?= component('stat', ['label' => 'Kas Akhir', 'value' => fmt_rupiah($report['ending']->toFloat()), 'tone' => 'primary']) ?>
</div>

<div class="space-y-4">
<?php foreach ($report['sections'] as $key => $section): ?>
    <?php
    $rows = '';

    foreach ($section['items'] as $item) {
        $rows .= '<tr class="hover">'
            . '<td class="whitespace-nowrap">' . esc(fmt_date($item['date'])) . '</td>'
            . '<td class="text-sm">' . esc($item['description']) . '</td>'
            . '<td class="num ' . amount_class($item['amount']->toFloat()) . '">'
                . esc(fmt_signed($item['amount']->toFloat())) . '</td>'
            . '</tr>';
    }

    $rows .= '<tr class="font-semibold border-t-2 border-base-300">'
        . '<td colspan="2" class="text-right">Total ' . esc($section['label']) . '</td>'
        . '<td class="num ' . amount_class($section['total']->toFloat()) . '">'
            . esc(fmt_signed($section['total']->toFloat())) . '</td>'
        . '</tr>';
    ?>

    <?= component('card', [
        'title'    => $section['label'],
        'subtitle' => match ($key) {
            'operating' => 'Dividen diterima, biaya administrasi, dan pajak',
            'investing' => 'Pembelian dan penjualan saham',
            default     => 'Setoran modal dan penarikan pemilik',
        },
        'flush'    => true,
        'body'     => $section['items'] === []
            ? component('empty_state', ['title' => 'Tidak ada arus kas pada aktivitas ini'])
            : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
                . '<thead><tr><th>Tanggal</th><th>Keterangan</th><th class="num">Arus Kas</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div>',
    ]) ?>
<?php endforeach; ?>
</div>

<div class="alert alert-info mt-4">
    <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
    <p class="text-sm">
        Transfer antar sekuritas tidak muncul di laporan ini: kedua sisinya adalah akun kas
        yang sama sehingga saling meniadakan — tidak ada uang yang benar-benar masuk atau keluar (§18).
    </p>
</div>
<?= $this->endSection() ?>
