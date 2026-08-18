<?php
/**
 * Header halaman: judul, deskripsi, breadcrumb, dan slot aksi.
 *
 * @var string      $title
 * @var string|null $subtitle
 * @var array       $breadcrumbs  [['label' => 'Portofolio', 'url' => '/portfolio'], ...]
 * @var string|null $actions      HTML tombol aksi di sisi kanan
 */
$title       = $title ?? '';
$subtitle    = $subtitle ?? null;
$breadcrumbs = $breadcrumbs ?? [];
$actions     = $actions ?? null;
?>
<div class="mb-6">
    <?php if ($breadcrumbs !== []): ?>
        <div class="breadcrumbs text-sm mb-1 py-0">
            <ul>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <li>
                        <?php if (! empty($crumb['url'])): ?>
                            <a href="<?= esc($crumb['url'], 'attr') ?>" class="link link-hover"><?= esc($crumb['label']) ?></a>
                        <?php else: ?>
                            <span class="text-base-content/60"><?= esc($crumb['label']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight"><?= esc($title) ?></h1>
            <?php if ($subtitle !== null): ?>
                <p class="text-sm text-base-content/60 mt-1"><?= esc($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actions !== null): ?>
            <div class="flex flex-wrap items-center gap-2 no-print"><?= $actions ?></div>
        <?php endif; ?>
    </div>
</div>
