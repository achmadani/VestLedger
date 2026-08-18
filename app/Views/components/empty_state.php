<?php
/**
 * Placeholder ketika belum ada data.
 *
 * @var string      $title
 * @var string|null $description
 * @var string|null $icon
 * @var string|null $actions HTML
 */
$title       = $title ?? 'Belum ada data';
$description = $description ?? null;
$icon        = $icon ?? 'info';
$actions     = $actions ?? null;
?>
<div class="flex flex-col items-center justify-center text-center py-12 px-4">
    <div class="text-base-content/20 mb-3">
        <?= component('icon', ['name' => $icon, 'class' => 'w-12 h-12']) ?>
    </div>
    <p class="font-medium"><?= esc($title) ?></p>
    <?php if ($description !== null): ?>
        <p class="text-sm text-base-content/60 mt-1 max-w-md"><?= esc($description) ?></p>
    <?php endif; ?>
    <?php if ($actions !== null): ?>
        <div class="mt-4"><?= $actions ?></div>
    <?php endif; ?>
</div>
