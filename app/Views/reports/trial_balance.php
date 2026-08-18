<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Neraca Saldo',
    'subtitle'    => fmt_date($from) . ' — ' . fmt_date($to),
    'breadcrumbs' => [['label' => 'Akuntansi'], ['label' => 'Neraca Saldo']],
]) ?>

<?= component('report_range', ['action' => site_url('accounting/trial-balance'), 'from' => $from, 'to' => $to]) ?>

<?php if ($report['balanced']): ?>
    <div class="alert alert-success mb-4">
        <?= component('icon', ['name' => 'check', 'class' => 'w-5 h-5 shrink-0']) ?>
        <span class="text-sm">Total debit sama dengan total kredit.</span>
    </div>
<?php else: ?>
    <div class="alert alert-error mb-4">
        <?= component('icon', ['name' => 'error', 'class' => 'w-5 h-5 shrink-0']) ?>
        <span class="text-sm">
            Total debit <?= esc(fmt_rupiah($report['total_debit']->toFloat())) ?>
            tidak sama dengan total kredit <?= esc(fmt_rupiah($report['total_credit']->toFloat())) ?>.
        </span>
    </div>
<?php endif; ?>

<?php
$rows = '';

foreach ($report['rows'] as $row) {
    // Saldo yang menyimpang dari sisi normalnya ditandai, karena hampir selalu
    // berarti ada kesalahan pencatatan.
    $abnormal = ($row['normal_balance']->value === 'debit' && ! $row['balance_credit']->isZero())
        || ($row['normal_balance']->value === 'credit' && ! $row['balance_debit']->isZero());

    $rows .= '<tr class="hover' . ($abnormal ? ' text-warning' : '') . '">'
        . '<td class="font-mono text-xs whitespace-nowrap">' . esc($row['code']) . '</td>'
        . '<td>' . esc($row['name'])
            . ($abnormal ? ' <span class="badge badge-warning badge-xs">saldo tidak normal</span>' : '') . '</td>'
        . '<td class="text-xs">' . esc($row['type']->label()) . '</td>'
        . '<td class="num">' . esc(fmt_money($row['total_debit']->toFloat())) . '</td>'
        . '<td class="num">' . esc(fmt_money($row['total_credit']->toFloat())) . '</td>'
        . '<td class="num font-medium">' . ($row['balance_debit']->isZero() ? '' : esc(fmt_money($row['balance_debit']->toFloat()))) . '</td>'
        . '<td class="num font-medium">' . ($row['balance_credit']->isZero() ? '' : esc(fmt_money($row['balance_credit']->toFloat()))) . '</td>'
        . '</tr>';
}

$rows .= '<tr class="font-semibold border-t-2 border-base-300">'
    . '<td colspan="5" class="text-right">Total Saldo</td>'
    . '<td class="num">' . esc(fmt_money($report['total_debit']->toFloat())) . '</td>'
    . '<td class="num">' . esc(fmt_money($report['total_credit']->toFloat())) . '</td>'
    . '</tr>';
?>

<?= component('card', [
    'flush' => true,
    'body'  => $report['rows'] === []
        ? component('empty_state', ['title' => 'Belum ada mutasi pada rentang ini', 'icon' => 'book'])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Kode</th><th>Akun</th><th>Tipe</th>'
            . '<th class="num">Mutasi Debit</th><th class="num">Mutasi Kredit</th>'
            . '<th class="num">Saldo Debit</th><th class="num">Saldo Kredit</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>
<?= $this->endSection() ?>
