<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var App\Entities\JournalEntry $entry */
$totalDebit  = 0.0;
$totalCredit = 0.0;

foreach ($lines as $line) {
    $totalDebit  += (float) $line['debit'];
    $totalCredit += (float) $line['credit'];
}

$balanced = abs($totalDebit - $totalCredit) < 0.005;
?>

<?= component('page_header', [
    'title'       => 'Jurnal ' . $entry->entry_number,
    'subtitle'    => $entry->description,
    'breadcrumbs' => [
        ['label' => 'Akuntansi'],
        ['label' => 'Jurnal', 'url' => site_url('accounting/journal')],
        ['label' => $entry->entry_number],
    ],
]) ?>

<div class="grid gap-3 sm:grid-cols-4 mb-4">
    <?= component('stat', ['label' => 'Tanggal', 'value' => fmt_date($entry->entry_date->format('Y-m-d'))]) ?>
    <?= component('stat', ['label' => 'Jenis', 'value' => $entry->type()->label()]) ?>
    <?= component('stat', ['label' => 'Sumber', 'value' => $entry->sourceType()->label()]) ?>
    <?= component('stat', [
        'label'      => 'Status',
        'value'      => $entry->status()->label(),
        'valueClass' => $entry->isReversed() ? 'text-error' : 'text-success',
    ]) ?>
</div>

<?php
$rows = '';

foreach ($lines as $line) {
    $dimensions = [];

    if (! empty($line['securities_code'])) {
        $dimensions[] = $line['securities_code'];
    }

    if (! empty($line['ticker'])) {
        $dimensions[] = $line['ticker'];
    }

    $rows .= '<tr class="hover">'
        . '<td class="num text-xs">' . (int) $line['line_no'] . '</td>'
        . '<td class="font-mono text-xs whitespace-nowrap">' . esc($line['account_code']) . '</td>'
        . '<td>' . esc($line['account_name']) . '</td>'
        . '<td class="text-xs">' . ($dimensions === []
            ? '<span class="text-base-content/30">-</span>'
            : '<span class="badge badge-ghost badge-xs">' . esc(implode(' · ', $dimensions)) . '</span>') . '</td>'
        . '<td class="num">' . ((float) $line['debit'] > 0 ? esc(fmt_money($line['debit'])) : '') . '</td>'
        . '<td class="num">' . ((float) $line['credit'] > 0 ? esc(fmt_money($line['credit'])) : '') . '</td>'
        . '</tr>';
}

$rows .= '<tr class="font-semibold border-t-2 border-base-300">'
    . '<td colspan="4" class="text-right">Total</td>'
    . '<td class="num">' . esc(fmt_money($totalDebit)) . '</td>'
    . '<td class="num">' . esc(fmt_money($totalCredit)) . '</td>'
    . '</tr>';
?>

<?= component('card', [
    'title'    => 'Baris Jurnal',
    'subtitle' => $balanced ? 'Debit sama dengan kredit.' : 'PERINGATAN: jurnal ini tidak balance.',
    'flush'    => true,
    'body'     => '<div class="overflow-x-auto"><table class="table table-sm">'
        . '<thead><tr><th class="num">#</th><th>Kode</th><th>Akun</th><th>Dimensi</th>'
        . '<th class="num">Debit</th><th class="num">Kredit</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>',
]) ?>

<div class="mt-4">
    <a href="<?= site_url('accounting/journal') ?>" class="btn btn-ghost btn-sm">Kembali ke daftar jurnal</a>
</div>
<?= $this->endSection() ?>
