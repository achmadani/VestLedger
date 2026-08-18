<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var list<App\Entities\AccountingPeriod> $periods */
/** @var list<int> $years */
$canManage = auth()->user()?->can('period.manage') ?? false;
?>

<?= component('page_header', [
    'title'       => 'Periode Akuntansi',
    'subtitle'    => 'Periode tertutup tidak menerima transaksi baru. Koreksi dilakukan lewat jurnal reversal di periode terbuka.',
    'breadcrumbs' => [['label' => 'Akuntansi'], ['label' => 'Periode']],
]) ?>

<div class="flex flex-wrap items-end gap-3 mb-4">
    <form method="get" action="<?= site_url('accounting/periods') ?>" class="flex items-end gap-2">
        <?php
        $yearOptions = [];

        foreach ($years as $y) {
            $yearOptions[$y] = (string) $y;
        }
        ?>
        <?= component('form/select', [
            'name'        => 'year',
            'label'       => 'Tahun',
            'options'     => $yearOptions,
            'value'       => (string) $year,
            'placeholder' => null,
            'class'       => 'w-32',
            'attrs'       => ['onchange' => 'this.form.submit()'],
        ]) ?>
        <noscript><button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button></noscript>
    </form>

    <?php if ($canManage): ?>
        <form method="post" action="<?= site_url('accounting/periods/generate') ?>" class="flex items-end gap-2">
            <?= csrf_field() ?>
            <?= component('form/input', [
                'name'  => 'year',
                'label' => 'Buat periode tahun',
                'type'  => 'number',
                'value' => (string) ((int) date('Y') + 1),
                'class' => 'w-40',
                'attrs' => ['min' => 2000, 'max' => 2199],
            ]) ?>
            <button type="submit" class="btn btn-sm btn-primary">Buat 12 Bulan</button>
        </form>
    <?php endif; ?>
</div>

<?php
$rows = '';

foreach ($periods as $period) {
    $status = '<span class="badge badge-sm ' . $period->status()->badgeClass() . '">'
        . esc($period->status()->label()) . '</span>';

    $closedInfo = $period->isOpen()
        ? '<span class="text-base-content/40">-</span>'
        : esc(fmt_date($period->closed_at?->format('Y-m-d H:i:s'), 'd M Y H:i'));

    $rowActions = '';

    if ($canManage) {
        $rowActions = $period->isOpen()
            ? component('confirm_form', [
                'action'  => site_url('accounting/periods/' . $period->id . '/close'),
                'label'   => 'Tutup',
                'message' => 'Tutup periode ' . $period->displayName() . '? Setelah ditutup, transaksi bertanggal dalam periode ini tidak lagi diterima.',
                'class'   => 'btn btn-xs btn-warning',
            ])
            : component('confirm_form', [
                'action'  => site_url('accounting/periods/' . $period->id . '/reopen'),
                'label'   => 'Buka Kembali',
                'message' => 'Buka kembali periode ' . $period->displayName() . '?',
                'class'   => 'btn btn-xs btn-ghost',
            ]);
    }

    $rows .= '<tr class="hover">'
        . '<td class="font-mono">' . esc($period->code) . '</td>'
        . '<td class="font-medium">' . esc($period->displayName()) . '</td>'
        . '<td class="text-xs">' . esc(fmt_date($period->start_date?->format('Y-m-d'))) . ' – '
            . esc(fmt_date($period->end_date?->format('Y-m-d'))) . '</td>'
        . '<td>' . $status . '</td>'
        . '<td class="text-xs">' . $closedInfo . '</td>'
        . '<td class="text-right">' . $rowActions . '</td>'
        . '</tr>';
}

$body = $periods === []
    ? component('empty_state', [
        'title'       => 'Belum ada periode untuk tahun ' . $year,
        'description' => 'Buat 12 periode bulanan agar transaksi dapat dicatat.',
        'icon'        => 'clock',
    ])
    : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr><th>Kode</th><th>Periode</th><th>Rentang</th><th>Status</th><th>Ditutup</th><th></th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
?>

<?= component('card', ['title' => 'Tahun ' . $year, 'flush' => true, 'body' => $body]) ?>

<div class="alert alert-info mt-4">
    <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
    <div class="text-sm">
        <p class="font-medium">Urutan buka/tutup dijaga sistem.</p>
        <ul class="list-disc list-inside text-xs opacity-90 mt-1 space-y-0.5">
            <li>Sebuah periode hanya dapat ditutup bila semua periode sebelumnya sudah tertutup.</li>
            <li>Hanya periode tertutup paling akhir yang dapat dibuka kembali, agar periode sesudahnya tidak kehilangan dasar saldo awalnya.</li>
        </ul>
    </div>
</div>
<?= $this->endSection() ?>
