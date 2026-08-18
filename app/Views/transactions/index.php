<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
use App\Enums\CashTransactionType;

$canCreate = auth()->user()?->can('transaction.create') ?? false;
$canVoid   = auth()->user()?->can('transaction.void') ?? false;

$typeLabels = [
    'buy'        => 'Beli',
    'sell'       => 'Jual',
    'dividend'   => 'Dividen',
    'top_up'     => 'Top Up',
    'withdrawal' => 'Withdrawal',
    'transfer'   => 'Transfer',
    'admin_fee'  => 'Biaya Adm.',
];

$actions = null;

if ($canCreate) {
    $actions = '<a href="' . site_url('transactions/buy') . '" class="btn btn-primary btn-sm">Beli</a>'
        . '<a href="' . site_url('transactions/sell') . '" class="btn btn-sm">Jual</a>'
        . '<a href="' . site_url('transactions/dividend') . '" class="btn btn-sm">Dividen</a>'
        . '<a href="' . site_url('transactions/top-up') . '" class="btn btn-sm">Top Up</a>';
}
?>

<?= component('page_header', [
    'title'       => 'Semua Transaksi',
    'subtitle'    => fmt_number($total) . ' transaksi tercatat. Setiap transaksi memiliki jurnal yang balance.',
    'breadcrumbs' => [['label' => 'Transaksi']],
    'actions'     => $actions,
]) ?>

<?php
$filterForm = '<form method="get" action="' . site_url('transactions') . '" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">'
    . component('form/input', ['name' => 'from', 'label' => 'Dari', 'type' => 'date', 'value' => $filters['from']])
    . component('form/input', ['name' => 'to', 'label' => 'Sampai', 'type' => 'date', 'value' => $filters['to']])
    . component('form/select', [
        'name' => 'kind', 'label' => 'Jenis',
        'options' => ['cash' => 'Kas', 'stock' => 'Saham', 'dividend' => 'Dividen'],
        'value' => $filters['kind'], 'placeholder' => 'Semua',
    ])
    . component('form/select', [
        'name' => 'securities_account_id', 'label' => 'Sekuritas',
        'options' => $accounts, 'value' => (string) ($filters['securities_account_id'] ?: ''), 'placeholder' => 'Semua',
    ])
    . component('form/select', [
        'name' => 'stock_id', 'label' => 'Saham',
        'options' => $stocks, 'value' => (string) ($filters['stock_id'] ?: ''), 'placeholder' => 'Semua',
    ])
    . '<div class="flex gap-2">'
    . '<button type="submit" class="btn btn-sm btn-neutral">Terapkan</button>'
    . '<a href="' . site_url('transactions') . '" class="btn btn-sm btn-ghost">Reset</a>'
    . '</div>'
    . '</form>';
?>

<div class="mb-4"><?= component('card', ['body' => $filterForm]) ?></div>

<?php
$rowsHtml = '';

foreach ($rows as $row) {
    $isReversed = $row['status'] === 'reversed';

    $status = $isReversed
        ? '<span class="badge badge-error badge-sm">Dibatalkan</span>'
        : '<span class="badge badge-success badge-sm">Posted</span>';

    $journal = $row['journal_entry_id']
        ? '<a href="' . site_url('accounting/journal/' . $row['journal_entry_id']) . '" class="link link-hover font-mono text-xs">lihat</a>'
        : '<span class="text-error text-xs">tidak ada</span>';

    $rowActions = '';

    if ($canVoid && ! $isReversed) {
        $rowActions = component('confirm_form', [
            'action'  => site_url('transactions/' . $row['kind'] . '/' . $row['id'] . '/reverse'),
            'label'   => 'Batalkan',
            'message' => 'Batalkan ' . $row['transaction_number'] . '? Sistem akan membuat jurnal pembalik; '
                . 'data aslinya tetap tersimpan.',
            'class'   => 'btn btn-ghost btn-xs text-error',
        ]);
    }

    $rowsHtml .= '<tr class="hover' . ($isReversed ? ' opacity-60' : '') . '">'
        . '<td class="font-mono text-xs whitespace-nowrap">' . esc($row['transaction_number']) . '</td>'
        . '<td class="whitespace-nowrap">' . esc(fmt_date($row['transaction_date'])) . '</td>'
        . '<td>' . esc($typeLabels[$row['type_label']] ?? $row['type_label']) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['securities_code']) . '</td>'
        . '<td class="font-mono text-xs">' . esc($row['ticker'] ?? '-') . '</td>'
        . '<td class="num">' . ($row['quantity'] !== null ? esc(fmt_qty($row['quantity'])) : '-') . '</td>'
        . '<td class="num">' . esc(fmt_rupiah(abs((float) $row['amount']))) . '</td>'
        . '<td>' . $status . '</td>'
        . '<td class="text-center">' . $journal . '</td>'
        . '<td class="text-right">' . $rowActions . '</td>'
        . '</tr>';
}

$body = $rows === []
    ? component('empty_state', [
        'title'       => 'Belum ada transaksi',
        'description' => 'Mulai dengan top up dana ke salah satu rekening sekuritas, lalu catat pembelian saham.',
        'icon'        => 'transaction',
        'actions'     => $actions,
    ])
    : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr><th>Nomor</th><th>Tanggal</th><th>Jenis</th><th>Sekuritas</th><th>Saham</th>'
        . '<th class="num">Lembar</th><th class="num">Nilai</th><th>Status</th><th class="text-center">Jurnal</th><th></th></tr></thead>'
        . '<tbody>' . $rowsHtml . '</tbody></table></div>';
?>

<?= component('card', ['flush' => true, 'body' => $body]) ?>

<?php if ($total > $perPage): ?>
    <div class="mt-4"><?= $pager->links() ?></div>
<?php endif; ?>
<?= $this->endSection() ?>
