<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Buku Besar',
    'subtitle'    => 'Mutasi per akun, dapat dipersempit menurut sekuritas dan saham.',
    'breadcrumbs' => [['label' => 'Akuntansi'], ['label' => 'Buku Besar']],
]) ?>

<?php
$filterForm = '<form method="get" action="' . site_url('accounting/ledger') . '" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end">'
    . component('form/select', [
        'name' => 'account_id', 'label' => 'Akun', 'options' => $accountOptions,
        'value' => (string) ($filters['account_id'] ?: ''), 'placeholder' => '-- Pilih akun --', 'required' => true,
    ])
    . component('form/select', [
        'name' => 'securities_account_id', 'label' => 'Sekuritas', 'options' => $securitiesOptions,
        'value' => (string) ($filters['securities_account_id'] ?: ''), 'placeholder' => 'Semua',
    ])
    . component('form/select', [
        'name' => 'stock_id', 'label' => 'Saham', 'options' => $stockOptions,
        'value' => (string) ($filters['stock_id'] ?: ''), 'placeholder' => 'Semua',
    ])
    . component('form/input', ['name' => 'from', 'label' => 'Dari', 'type' => 'date', 'value' => $filters['from']])
    . component('form/input', ['name' => 'to', 'label' => 'Sampai', 'type' => 'date', 'value' => $filters['to']])
    . '<div class="flex gap-2 lg:col-span-5">'
    . '<button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button>'
    . '<a href="' . site_url('accounting/ledger') . '" class="btn btn-sm btn-ghost">Reset</a>'
    . '</div></form>';
?>

<div class="mb-4"><?= component('card', ['body' => $filterForm]) ?></div>

<?php if ($account === null): ?>
    <?= component('card', [
        'flush' => true,
        'body'  => component('empty_state', [
            'title'       => 'Pilih akun terlebih dahulu',
            'description' => 'Saldo berjalan hanya bermakna untuk satu akun. Menjumlahkan baris dari akun '
                . 'yang berbeda saldo normalnya tidak menghasilkan angka yang berarti.',
            'icon'        => 'book',
        ]),
    ]) ?>
<?php else: ?>
    <?php
    $rowsHtml = '';

    foreach ($rows as $line) {
        $dimensions = array_filter([$line['securities_code'] ?? null, $line['ticker'] ?? null]);

        $rowsHtml .= '<tr class="hover' . ($line['entry_status'] === 'reversed' ? ' opacity-60' : '') . '">'
            . '<td class="whitespace-nowrap">' . esc(fmt_date($line['entry_date'])) . '</td>'
            . '<td class="font-mono text-xs"><a href="' . site_url('accounting/journal/' . $line['journal_entry_id'])
                . '" class="link link-hover">' . esc($line['entry_number']) . '</a></td>'
            . '<td class="text-sm">' . esc($line['description']) . '</td>'
            . '<td class="text-xs">' . ($dimensions === []
                ? '<span class="text-base-content/30">-</span>'
                : '<span class="badge badge-ghost badge-xs">' . esc(implode(' · ', $dimensions)) . '</span>') . '</td>'
            . '<td class="num">' . ((float) $line['debit'] > 0 ? esc(fmt_money($line['debit'])) : '') . '</td>'
            . '<td class="num">' . ((float) $line['credit'] > 0 ? esc(fmt_money($line['credit'])) : '') . '</td>'
            . '<td class="num font-medium">' . esc(fmt_money($line['running'])) . '</td>'
            . '</tr>';
    }

    $lastRunning = $rows === [] ? '0' : end($rows)['running'];
    ?>

    <?= component('card', [
        'title'    => $account->displayName(),
        'subtitle' => 'Saldo normal ' . $account->normalBalance()->label()
            . ' · saldo akhir ' . fmt_rupiah($lastRunning),
        'flush'    => true,
        'body'     => $rows === []
            ? component('empty_state', ['title' => 'Tidak ada mutasi', 'description' => 'Akun ini belum pernah tersentuh jurnal pada rentang yang dipilih.'])
            : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
                . '<thead><tr><th>Tanggal</th><th>Jurnal</th><th>Keterangan</th><th>Dimensi</th>'
                . '<th class="num">Debit</th><th class="num">Kredit</th><th class="num">Saldo</th></tr></thead>'
                . '<tbody>' . $rowsHtml . '</tbody></table></div>',
    ]) ?>
<?php endif; ?>
<?= $this->endSection() ?>
