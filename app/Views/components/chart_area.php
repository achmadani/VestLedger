<?php
/**
 * Grafik area bertumpuk, digambar sebagai SVG di sisi server.
 *
 * Tidak memakai library chart sama sekali (§31, §34): grafik ini statis,
 * datanya sudah dihitung server, dan menambah dependency JavaScript hanya untuk
 * menggambar dua deret angka jelas tidak sepadan.
 *
 * Warna diambil dari token DaisyUI, sehingga grafik ikut berubah saat tema
 * diganti tanpa satu baris pun perubahan kode.
 *
 * @var list<array{label:string, cash:\App\ValueObjects\Money, portfolio:\App\ValueObjects\Money, total:\App\ValueObjects\Money}> $series
 * @var string $title
 */
$series = $series ?? [];

if ($series === []) {
    return;
}

$width   = 720;
$height  = 220;
$padLeft = 8;
$padBot  = 22;
$padTop  = 12;

$max = 0.0;

foreach ($series as $point) {
    $max = max($max, $point['total']->toFloat());
}

// Skala nol berarti belum ada aktivitas; garis datar di dasar lebih jujur
// daripada grafik acak.
$max = $max > 0 ? $max : 1.0;

$plotWidth  = $width - $padLeft * 2;
$plotHeight = $height - $padBot - $padTop;
$step       = count($series) > 1 ? $plotWidth / (count($series) - 1) : 0;

$x = static fn (int $i): float => $padLeft + $i * $step;
$y = static fn (float $value): float => $padTop + $plotHeight - ($value / $max) * $plotHeight;

$cashPoints  = [];
$totalPoints = [];

foreach ($series as $i => $point) {
    $cashPoints[]  = sprintf('%.1f,%.1f', $x($i), $y($point['cash']->toFloat()));
    $totalPoints[] = sprintf('%.1f,%.1f', $x($i), $y($point['total']->toFloat()));
}

$baseline = sprintf('%.1f,%.1f %.1f,%.1f', $x(count($series) - 1), $y(0), $x(0), $y(0));
?>
<div class="w-full overflow-x-auto">
    <svg viewBox="0 0 <?= $width ?> <?= $height ?>" class="w-full h-auto min-w-[520px]"
         role="img" aria-label="<?= esc($title ?? 'Grafik aset', 'attr') ?>">
        <?php // Garis bantu horizontal ?>
        <?php for ($i = 0; $i <= 4; $i++): ?>
            <line x1="<?= $padLeft ?>" x2="<?= $width - $padLeft ?>"
                  y1="<?= sprintf('%.1f', $padTop + $plotHeight * $i / 4) ?>"
                  y2="<?= sprintf('%.1f', $padTop + $plotHeight * $i / 4) ?>"
                  stroke="currentColor" stroke-opacity="0.12" stroke-width="1"/>
        <?php endfor; ?>

        <?php // Total aset (kas + portofolio) ?>
        <polygon points="<?= implode(' ', $totalPoints) ?> <?= $baseline ?>"
                 fill="var(--color-primary, currentColor)" fill-opacity="0.18"/>
        <polyline points="<?= implode(' ', $totalPoints) ?>" fill="none"
                  stroke="var(--color-primary, currentColor)" stroke-width="2"
                  stroke-linejoin="round" stroke-linecap="round"/>

        <?php // Kas saja ?>
        <polygon points="<?= implode(' ', $cashPoints) ?> <?= $baseline ?>"
                 fill="var(--color-success, currentColor)" fill-opacity="0.22"/>
        <polyline points="<?= implode(' ', $cashPoints) ?>" fill="none"
                  stroke="var(--color-success, currentColor)" stroke-width="1.5"
                  stroke-dasharray="4 3"/>

        <?php foreach ($series as $i => $point): ?>
            <text x="<?= sprintf('%.1f', $x($i)) ?>" y="<?= $height - 6 ?>"
                  text-anchor="middle" font-size="10" fill="currentColor" fill-opacity="0.55">
                <?= esc($point['label']) ?>
            </text>
        <?php endforeach; ?>
    </svg>
</div>

<div class="flex flex-wrap items-center gap-4 mt-2 text-xs text-base-content/60">
    <span class="flex items-center gap-1.5">
        <span class="inline-block w-3 h-2 rounded-sm" style="background: var(--color-primary)"></span>
        Total aset (kas + portofolio)
    </span>
    <span class="flex items-center gap-1.5">
        <span class="inline-block w-3 h-2 rounded-sm" style="background: var(--color-success)"></span>
        Kas
    </span>
    <span class="ml-auto">Tertinggi: <?= esc(fmt_rupiah($max)) ?></span>
</div>
