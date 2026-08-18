<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var array $report */
$section = static function (string $title, array $rows, string $total, ?string $extraRow = null): string {
    $body = '';

    foreach ($rows as $row) {
        $body .= '<tr class="hover">'
            . '<td class="font-mono text-xs whitespace-nowrap">' . esc($row['code']) . '</td>'
            . '<td>' . esc($row['name']) . '</td>'
            . '<td class="num">' . esc(fmt_money($row['amount']->toFloat())) . '</td>'
            . '</tr>';
    }

    $body .= $extraRow ?? '';

    $body .= '<tr class="font-semibold border-t-2 border-base-300">'
        . '<td colspan="2" class="text-right">Total ' . esc($title) . '</td>'
        . '<td class="num">' . esc($total) . '</td>'
        . '</tr>';

    return component('card', [
        'title' => $title,
        'flush' => true,
        'body'  => '<div class="overflow-x-auto"><table class="table table-sm">'
            . '<thead><tr><th>Kode</th><th>Akun</th><th class="num">Jumlah</th></tr></thead>'
            . '<tbody>' . $body . '</tbody></table></div>',
    ]);
};

// Laba/rugi berjalan disajikan sebagai baris ekuitas tersendiri karena akun
// nominal belum ditutup ke laba ditahan.
$profitRow = '<tr class="hover">'
    . '<td class="font-mono text-xs">—</td>'
    . '<td>Laba/Rugi Berjalan <span class="text-xs text-base-content/50">(s.d. tanggal laporan)</span></td>'
    . '<td class="num ' . amount_class($report['profit_or_loss']->toFloat()) . '">'
        . esc(fmt_signed($report['profit_or_loss']->toFloat())) . '</td>'
    . '</tr>';
?>

<?= component('page_header', [
    'title'       => 'Neraca',
    'subtitle'    => 'Posisi keuangan per ' . fmt_date($report['as_of']) . '.',
    'breadcrumbs' => [['label' => 'Laporan'], ['label' => 'Neraca']],
]) ?>

<div class="mb-4 no-print">
    <?= component('card', [
        'body' => '<form method="get" class="flex items-end gap-2">'
            . component('form/input', ['name' => 'as_of', 'label' => 'Per Tanggal', 'type' => 'date', 'value' => $asOf, 'class' => 'w-48'])
            . '<button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button>'
            . '<button type="button" class="btn btn-sm btn-ghost" onclick="window.print()">Cetak</button>'
            . '</form>',
    ]) ?>
</div>

<?php if ($report['balanced']): ?>
    <div class="alert alert-success mb-4">
        <?= component('icon', ['name' => 'check', 'class' => 'w-5 h-5 shrink-0']) ?>
        <span class="text-sm">
            Neraca balance: aset <?= esc(fmt_rupiah($report['total_assets']->toFloat())) ?>
            sama dengan kewajiban + ekuitas.
        </span>
    </div>
<?php else: ?>
    <div class="alert alert-error mb-4">
        <?= component('icon', ['name' => 'error', 'class' => 'w-5 h-5 shrink-0']) ?>
        <div class="text-sm">
            <p class="font-medium">Neraca TIDAK balance.</p>
            <p class="text-xs opacity-90">
                Aset <?= esc(fmt_rupiah($report['total_assets']->toFloat())) ?>
                vs kewajiban + ekuitas <?= esc(fmt_rupiah($report['total_liabilities_equity']->toFloat())) ?>.
                Ini seharusnya tidak mungkin — periksa integritas buku besar.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="grid gap-4 lg:grid-cols-2">
    <div><?= $section('Aset', $report['assets'], fmt_money($report['total_assets']->toFloat())) ?></div>
    <div class="space-y-4">
        <?php if ($report['liabilities'] !== []): ?>
            <?= $section('Kewajiban', $report['liabilities'], fmt_money($report['total_liabilities']->toFloat())) ?>
        <?php endif; ?>
        <?= $section('Ekuitas', $report['equity'], fmt_money($report['total_equity']->toFloat()), $profitRow) ?>
    </div>
</div>
<?= $this->endSection() ?>
