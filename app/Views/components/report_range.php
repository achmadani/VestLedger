<?php
/**
 * Filter rentang tanggal untuk laporan.
 *
 * @var string      $action
 * @var string      $from
 * @var string      $to
 * @var string|null $extra HTML field tambahan
 */
$action = $action ?? '';
$from   = $from ?? '';
$to     = $to ?? '';
$extra  = $extra ?? null;
?>
<div class="mb-4 no-print">
    <?= component('card', [
        'body' => '<form method="get" action="' . esc($action, 'attr') . '" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end">'
            . component('form/input', ['name' => 'from', 'label' => 'Dari', 'type' => 'date', 'value' => $from])
            . component('form/input', ['name' => 'to', 'label' => 'Sampai', 'type' => 'date', 'value' => $to])
            . ($extra ?? '')
            . '<div class="flex gap-2">'
            . '<button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button>'
            . '<button type="button" class="btn btn-sm btn-ghost" onclick="window.print()">Cetak</button>'
            . '</div></form>',
    ]) ?>
</div>
