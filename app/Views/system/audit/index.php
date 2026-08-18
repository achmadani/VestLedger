<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Audit Trail',
    'subtitle'    => 'Catatan siapa melakukan apa dan kapan. Tabel ini hanya pernah ditambah, tidak pernah diubah.',
    'breadcrumbs' => [['label' => 'Sistem'], ['label' => 'Audit Trail']],
]) ?>

<?php
$filterForm = '<form method="get" action="' . site_url('system/audit') . '" class="grid gap-3 sm:grid-cols-3 items-end">'
    . component('form/select', [
        'name' => 'action', 'label' => 'Aksi',
        'options' => ['created' => 'Dibuat', 'reversed' => 'Dibatalkan', 'updated' => 'Diubah'],
        'value' => $filters['action'], 'placeholder' => 'Semua',
    ])
    . component('form/select', [
        'name' => 'entity_type', 'label' => 'Entitas',
        'options' => [
            'cash_transaction'     => 'Transaksi Kas',
            'stock_transaction'    => 'Transaksi Saham',
            'dividend_transaction' => 'Dividen',
        ],
        'value' => $filters['entity_type'], 'placeholder' => 'Semua',
    ])
    . '<div class="flex gap-2">'
    . '<button type="submit" class="btn btn-sm btn-neutral">Terapkan</button>'
    . '<a href="' . site_url('system/audit') . '" class="btn btn-sm btn-ghost">Reset</a>'
    . '</div></form>';

$rows = '';

foreach ($logs as $log) {
    $actionBadge = match ($log['action']) {
        'created'  => '<span class="badge badge-success badge-sm">Dibuat</span>',
        'reversed' => '<span class="badge badge-error badge-sm">Dibatalkan</span>',
        default    => '<span class="badge badge-ghost badge-sm">' . esc($log['action']) . '</span>',
    };

    $rows .= '<tr class="hover">'
        . '<td class="whitespace-nowrap text-xs">' . esc(fmt_date($log['created_at'], 'd M Y H:i')) . '</td>'
        . '<td class="text-xs">' . esc($log['username'] ?? 'sistem') . '</td>'
        . '<td>' . $actionBadge . '</td>'
        . '<td class="text-xs font-mono">' . esc($log['entity_type']) . ' #' . esc((string) $log['entity_id']) . '</td>'
        . '<td class="text-sm">' . esc($log['summary'] ?? '') . '</td>'
        . '</tr>';
}
?>

<div class="mb-4"><?= component('card', ['body' => $filterForm]) ?></div>

<?= component('card', [
    'flush' => true,
    'body'  => $logs === []
        ? component('empty_state', ['title' => 'Belum ada catatan audit', 'icon' => 'shield'])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Entitas</th><th>Ringkasan</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>

<?php if ($pager !== null && $pager->getPageCount() > 1): ?>
    <div class="mt-4"><?= $pager->links() ?></div>
<?php endif; ?>
<?= $this->endSection() ?>
