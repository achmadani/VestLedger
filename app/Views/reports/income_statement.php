<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$rows = static function (array $items): string {
    $html = '';

    foreach ($items as $item) {
        $html .= '<tr class="hover">'
            . '<td class="font-mono text-xs whitespace-nowrap">' . esc($item['code']) . '</td>'
            . '<td>' . esc($item['name']) . '</td>'
            . '<td class="num">' . esc(fmt_money($item['amount']->toFloat())) . '</td>'
            . '</tr>';
    }

    return $html !== '' ? $html : '<tr><td colspan="3" class="text-center text-base-content/40 text-sm py-4">Tidak ada</td></tr>';
};
?>

<?php
$scopeLabel = $accountId > 0 ? ($accounts[$accountId] ?? 'Sekuritas terpilih') : null;
?>

<?= component('page_header', [
    'title'       => 'Laba Rugi',
    'subtitle'    => fmt_date($report['from']) . ' — ' . fmt_date($report['to'])
        . ($scopeLabel !== null ? ' · ' . $scopeLabel : ''),
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Laba Rugi']],
    'actions'     => '<a href="' . site_url('reports/profit-by-securities') . '?from=' . urlencode($from)
        . '&to=' . urlencode($to) . '" class="btn btn-sm btn-ghost">Bandingkan per sekuritas</a>',
]) ?>

<?= component('report_range', [
    'action' => site_url('reports/income-statement'),
    'from'   => $from,
    'to'     => $to,
    'extra'  => component('form/select', [
        'name' => 'securities_account_id', 'label' => 'Sekuritas', 'options' => $accounts,
        'value' => (string) ($accountId ?: ''), 'placeholder' => 'Seluruh sekuritas',
    ]),
]) ?>

<?php if ($scopeLabel !== null): ?>
    <div class="alert alert-warning mb-4 text-sm">
        <span>
            Laporan ini dibatasi pada <strong><?= esc($scopeLabel) ?></strong>, memakai dimensi
            rekening pada setiap baris jurnal. Ini <strong>bukan</strong> laporan keuangan
            tersendiri &mdash; entitas pelaporannya tetap satu. Angkanya berguna untuk
            membandingkan kinerja antar sekuritas, bukan untuk disajikan sebagai laba rugi
            yang berdiri sendiri.
        </span>
    </div>
<?php endif; ?>

<div class="alert alert-info mb-4">
    <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
    <div class="text-sm">
        <p>Unrealized gain/loss tidak muncul di sini.</p>
        <p class="text-xs opacity-80 mt-0.5">
            Kenaikan harga pasar belum terealisasi dan tidak pernah dijurnal, sehingga tidak
            masuk laba rugi periode berjalan (§13). Lihat
            <a href="<?= site_url('reports/unrealized') ?>" class="link">Laporan Unrealized</a>.
        </p>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <?= component('card', [
        'title' => 'Pendapatan',
        'flush' => true,
        'body'  => '<div class="overflow-x-auto"><table class="table table-sm">'
            . '<thead><tr><th>Kode</th><th>Akun</th><th class="num">Jumlah</th></tr></thead>'
            . '<tbody>' . $rows($report['revenue'])
            . '<tr class="font-semibold border-t-2 border-base-300"><td colspan="2" class="text-right">Total Pendapatan</td>'
            . '<td class="num">' . esc(fmt_money($report['total_revenue']->toFloat())) . '</td></tr>'
            . '</tbody></table></div>',
    ]) ?>

    <?= component('card', [
        'title' => 'Beban',
        'flush' => true,
        'body'  => '<div class="overflow-x-auto"><table class="table table-sm">'
            . '<thead><tr><th>Kode</th><th>Akun</th><th class="num">Jumlah</th></tr></thead>'
            . '<tbody>' . $rows($report['expenses'])
            . '<tr class="font-semibold border-t-2 border-base-300"><td colspan="2" class="text-right">Total Beban</td>'
            . '<td class="num">' . esc(fmt_money($report['total_expense']->toFloat())) . '</td></tr>'
            . '</tbody></table></div>',
    ]) ?>
</div>

<div class="mt-4">
    <?= component('stat', [
        'label'      => 'Laba/Rugi Bersih',
        'value'      => fmt_signed($report['net_profit']->toFloat()),
        'sub'        => 'Pendapatan − beban, tanpa unrealized',
        'valueClass' => amount_class($report['net_profit']->toFloat()),
        'tone'       => $report['net_profit']->isNegative() ? 'error' : 'success',
    ]) ?>
</div>
<?= $this->endSection() ?>
