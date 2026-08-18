<?php
/**
 * Stat card untuk angka ringkasan dashboard (§20, §31).
 *
 * @var string      $label
 * @var string      $value      sudah diformat oleh pemanggil
 * @var string|null $sub        keterangan kecil di bawah nilai
 * @var string|null $icon
 * @var string|null $valueClass kelas warna, mis. hasil amount_class()
 * @var string|null $tone       'default'|'primary'|'success'|'error'
 */
$label      = $label ?? '';
$value      = $value ?? '-';
$sub        = $sub ?? null;
$icon       = $icon ?? null;
$valueClass = $valueClass ?? '';
$tone       = $tone ?? 'default';

$border = match ($tone) {
    'primary' => 'border-l-4 border-l-primary',
    'success' => 'border-l-4 border-l-success',
    'error'   => 'border-l-4 border-l-error',
    default   => '',
};
?>
<div class="card bg-base-100 shadow-sm border border-base-300 <?= $border ?>">
    <div class="card-body p-4 gap-1">
        <div class="flex items-start justify-between gap-2">
            <span class="text-xs font-medium uppercase tracking-wide text-base-content/60">
                <?= esc($label) ?>
            </span>
            <?php if ($icon !== null): ?>
                <span class="text-base-content/30">
                    <?= component('icon', ['name' => $icon, 'class' => 'w-4 h-4']) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="num text-xl font-semibold tabular-nums text-left <?= esc($valueClass, 'attr') ?>">
            <?= esc($value) ?>
        </div>
        <?php if ($sub !== null): ?>
            <div class="text-xs text-base-content/50"><?= esc($sub) ?></div>
        <?php endif; ?>
    </div>
</div>
