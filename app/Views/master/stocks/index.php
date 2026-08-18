<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var list<App\Entities\Stock> $stocks */
$canManage = auth()->user()?->can('masterdata.manage') ?? false;

$actions = $canManage
    ? '<a href="' . site_url('master/stocks/new') . '" class="btn btn-primary btn-sm">Tambah Saham</a>'
    : null;

$sectorOptions = [];

foreach ($sectors as $sector) {
    $sectorOptions[$sector] = $sector;
}
?>

<?= component('page_header', [
    'title'       => 'Saham',
    'subtitle'    => 'Emiten yang dapat ditransaksikan.',
    'breadcrumbs' => [['label' => 'Master Data'], ['label' => 'Saham']],
    'actions'     => $actions,
]) ?>

<?php
// Filter dikirim sebagai GET biasa: hasilnya dapat di-bookmark dan tetap
// dikerjakan di sisi database, bukan dengan menyaring array di browser (§32).
$filterForm = '<form method="get" action="' . site_url('master/stocks') . '" class="grid gap-3 sm:grid-cols-4 items-end">'
    . component('form/input', [
        'name'        => 'q',
        'label'       => 'Cari',
        'value'       => $filters['q'],
        'placeholder' => 'Ticker atau nama perusahaan',
    ])
    . component('form/select', [
        'name'        => 'sector',
        'label'       => 'Sektor',
        'options'     => $sectorOptions,
        'value'       => $filters['sector'],
        'placeholder' => 'Semua sektor',
    ])
    . component('form/select', [
        'name'        => 'status',
        'label'       => 'Status',
        'options'     => ['active' => 'Aktif', 'inactive' => 'Nonaktif'],
        'value'       => $filters['status'],
        'placeholder' => 'Semua status',
    ])
    . '<div class="flex gap-2">'
    . '<button type="submit" class="btn btn-sm btn-neutral">Terapkan</button>'
    . '<a href="' . site_url('master/stocks') . '" class="btn btn-sm btn-ghost">Reset</a>'
    . '</div>'
    . '</form>';
?>

<div class="mb-4"><?= component('card', ['body' => $filterForm]) ?></div>

<?php
$rows = '';

foreach ($stocks as $stock) {
    $status = $stock->is_active
        ? '<span class="badge badge-success badge-sm">Aktif</span>'
        : '<span class="badge badge-ghost badge-sm">Nonaktif</span>';

    $rowActions = '';

    if ($canManage) {
        $rowActions = '<a href="' . site_url('master/stocks/' . $stock->id . '/edit') . '" class="btn btn-ghost btn-xs">Ubah</a>'
            . component('confirm_form', [
                'action'  => site_url('master/stocks/' . $stock->id . '/delete'),
                'label'   => 'Hapus',
                'message' => 'Hapus ' . $stock->ticker . '? Untuk saham yang sudah pernah ditransaksikan, gunakan nonaktif.',
                'class'   => 'btn btn-ghost btn-xs text-error',
            ]);
    }

    $rows .= '<tr class="hover">'
        . '<td class="font-mono font-semibold">' . esc($stock->ticker) . '</td>'
        . '<td>' . esc($stock->company_name) . '</td>'
        . '<td>' . esc($stock->sector ?? '-') . '</td>'
        . '<td>' . $status . '</td>'
        . '<td class="text-right whitespace-nowrap">' . $rowActions . '</td>'
        . '</tr>';
}

$body = $stocks === []
    ? component('empty_state', [
        'title'       => 'Tidak ada saham yang cocok',
        'description' => 'Ubah kata kunci atau filter, atau tambahkan saham baru.',
        'actions'     => $actions,
    ])
    : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr><th>Ticker</th><th>Perusahaan</th><th>Sektor</th><th>Status</th><th></th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
?>

<?= component('card', ['flush' => true, 'body' => $body]) ?>

<?php if ($pager !== null && $pager->getPageCount() > 1): ?>
    <div class="mt-4"><?= $pager->links() ?></div>
<?php endif; ?>
<?= $this->endSection() ?>
