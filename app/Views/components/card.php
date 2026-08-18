<?php
/**
 * Card generik. Body diberikan sebagai HTML string oleh pemanggil.
 *
 * @var string|null $title
 * @var string|null $subtitle
 * @var string      $body     HTML
 * @var string|null $actions  HTML
 * @var string|null $footer   HTML
 * @var bool        $flush    true = body tanpa padding (untuk tabel penuh)
 */
$title    = $title ?? null;
$subtitle = $subtitle ?? null;
$body     = $body ?? '';
$actions  = $actions ?? null;
$footer   = $footer ?? null;
$flush    = $flush ?? false;
?>
<section class="card bg-base-100 shadow-sm border border-base-300 overflow-hidden">
    <?php if ($title !== null || $actions !== null): ?>
        <header class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-base-300">
            <div>
                <?php if ($title !== null): ?>
                    <h2 class="font-semibold"><?= esc($title) ?></h2>
                <?php endif; ?>
                <?php if ($subtitle !== null): ?>
                    <p class="text-xs text-base-content/60 mt-0.5"><?= esc($subtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($actions !== null): ?>
                <div class="flex items-center gap-2 no-print"><?= $actions ?></div>
            <?php endif; ?>
        </header>
    <?php endif; ?>

    <div class="<?= $flush ? '' : 'p-4' ?>"><?= $body ?></div>

    <?php if ($footer !== null): ?>
        <footer class="px-4 py-3 border-t border-base-300 bg-base-200/40"><?= $footer ?></footer>
    <?php endif; ?>
</section>
