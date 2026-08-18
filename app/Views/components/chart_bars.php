<?php
/**
 * Bar horizontal untuk komposisi portofolio.
 *
 * Dibuat dari elemen HTML biasa, bukan canvas maupun library: bar horizontal
 * adalah salah satu bentuk grafik yang justru lebih baik sebagai HTML — ia
 * dapat dibaca screen reader, ikut menyesuaikan lebar layar, dan terbawa saat
 * halaman dicetak.
 *
 * @var list<array{label:string, sublabel:?string, value:float, formatted:string}> $items
 */
$items = $items ?? [];

if ($items === []) {
    return;
}

$max = 0.0;

foreach ($items as $item) {
    $max = max($max, abs($item['value']));
}

$max = $max > 0 ? $max : 1.0;
?>
<div class="space-y-2">
    <?php foreach ($items as $item): ?>
        <?php $pct = min(100, abs($item['value']) / $max * 100); ?>
        <div>
            <div class="flex items-baseline justify-between gap-2 text-xs mb-0.5">
                <span class="font-mono font-semibold"><?= esc($item['label']) ?></span>
                <span class="num text-base-content/70"><?= esc($item['formatted']) ?></span>
            </div>
            <div class="h-2 rounded-full bg-base-300 overflow-hidden">
                <div class="h-full rounded-full bg-primary" style="width: <?= sprintf('%.1f', $pct) ?>%"></div>
            </div>
            <?php if (! empty($item['sublabel'])): ?>
                <p class="text-[11px] text-base-content/50 mt-0.5"><?= esc($item['sublabel']) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
