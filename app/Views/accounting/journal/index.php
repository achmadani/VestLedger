<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Jurnal',
    'subtitle'    => 'Seluruh jurnal yang dihasilkan sistem. Tidak ada jurnal yang dapat dibuat atau diubah manual.',
    'breadcrumbs' => [['label' => 'Akuntansi'], ['label' => 'Jurnal']],
]) ?>

<?php if (! $balanced): ?>
    <div class="alert alert-error mb-4">
        <?= component('icon', ['name' => 'error', 'class' => 'w-5 h-5 shrink-0']) ?>
        <div class="text-sm">
            <p class="font-medium">Buku besar tidak balance.</p>
            <p class="text-xs opacity-90 mt-0.5">
                Total debit tidak sama dengan total kredit. Ini seharusnya tidak mungkin terjadi —
                periksa integritas database sebelum memakai laporan apa pun.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-success mb-4">
        <?= component('icon', ['name' => 'check', 'class' => 'w-5 h-5 shrink-0']) ?>
        <span class="text-sm">Buku besar balance: total debit sama dengan total kredit.</span>
    </div>
<?php endif; ?>

<?php
$filterForm = '<form method="get" action="' . site_url('accounting/journal') . '" class="grid gap-3 sm:grid-cols-4 items-end">'
    . component('form/input', ['name' => 'from', 'label' => 'Dari', 'type' => 'date', 'value' => $filters['from']])
    . component('form/input', ['name' => 'to', 'label' => 'Sampai', 'type' => 'date', 'value' => $filters['to']])
    . '<div class="flex gap-2">'
    . '<button type="submit" class="btn btn-sm btn-neutral">Terapkan</button>'
    . '<a href="' . site_url('accounting/journal') . '" class="btn btn-sm btn-ghost">Reset</a>'
    . '</div></form>';

$rows = '';

foreach ($entries as $entry) {
    $badge = $entry->isReversal()
        ? '<span class="badge badge-warning badge-xs">pembalik</span>'
        : ($entry->isReversed() ? '<span class="badge badge-error badge-xs">dibalik</span>' : '');

    $rows .= '<tr class="hover">'
        . '<td class="font-mono text-xs whitespace-nowrap">'
            . '<a href="' . site_url('accounting/journal/' . $entry->id) . '" class="link link-hover">'
            . esc($entry->entry_number) . '</a> ' . $badge . '</td>'
        . '<td class="whitespace-nowrap">' . esc(fmt_date($entry->entry_date->format('Y-m-d'))) . '</td>'
        . '<td class="text-sm">' . esc($entry->description) . '</td>'
        . '<td class="num">' . esc(fmt_rupiah($entry->total_debit)) . '</td>'
        . '<td class="num">' . esc(fmt_rupiah($entry->total_credit)) . '</td>'
        . '</tr>';
}
?>

<div class="mb-4"><?= component('card', ['body' => $filterForm]) ?></div>

<?= component('card', [
    'flush' => true,
    'body'  => $entries === []
        ? component('empty_state', ['title' => 'Belum ada jurnal', 'description' => 'Jurnal terbentuk otomatis setiap kali transaksi dicatat.'])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Nomor</th><th>Tanggal</th><th>Keterangan</th><th class="num">Debit</th><th class="num">Kredit</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>

<?php if ($pager !== null && $pager->getPageCount() > 1): ?>
    <div class="mt-4"><?= $pager->links() ?></div>
<?php endif; ?>
<?= $this->endSection() ?>
