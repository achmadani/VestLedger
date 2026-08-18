<?php
/**
 * Sidebar navigasi.
 *
 * Struktur menu dibaca dari Config\Navigation, dan setiap item disaring dengan
 * permission Shield yang sama dengan filter route — sehingga menu tidak pernah
 * menampilkan halaman yang memang tidak boleh diakses user (§36).
 */
$nav         = config(\Config\Navigation::class);
$currentPath = '/' . trim(uri_string(), '/');
$user        = auth()->user();
?>
<aside class="min-h-full w-64 bg-base-200 flex flex-col">
    <div class="px-4 py-4 border-b border-base-300">
        <a href="<?= site_url('dashboard') ?>" class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary text-primary-content font-bold text-sm">VL</span>
            <span>
                <span class="block font-semibold leading-tight">VestLedger</span>
                <span class="block text-[11px] text-base-content/60 leading-tight">Portfolio &amp; Accounting</span>
            </span>
        </a>
    </div>

    <nav class="flex-1 overflow-y-auto px-2 py-3" aria-label="Navigasi utama">
        <?php foreach ($nav->menu() as $group): ?>
            <?php
            // Sembunyikan seluruh grup bila user tidak punya izin atas satu pun itemnya.
            $visible = array_filter(
                $group['items'],
                static fn (array $item): bool => $item['permission'] === null
                    || ($user !== null && $user->can($item['permission']))
            );

            if ($visible === []) {
                continue;
            }
            ?>
            <div class="mb-3">
                <p class="px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-base-content/40">
                    <?= esc($group['label']) ?>
                </p>
                <ul class="menu menu-sm p-0 gap-0.5">
                    <?php foreach ($visible as $item): ?>
                        <li>
                            <?php if ($item['enabled']): ?>
                                <a href="<?= site_url(ltrim($item['route'], '/')) ?>"
                                   class="<?= $currentPath === $item['route'] ? 'active font-medium' : '' ?>"
                                   <?= $currentPath === $item['route'] ? 'aria-current="page"' : '' ?>>
                                    <?= esc($item['label']) ?>
                                </a>
                            <?php else: ?>
                                <span class="opacity-40 cursor-not-allowed flex items-center justify-between gap-2"
                                      title="Tersedia pada <?= esc($item['phase'] ?? 'phase berikutnya', 'attr') ?>">
                                    <span><?= esc($item['label']) ?></span>
                                    <span class="badge badge-ghost badge-xs shrink-0"><?= esc($item['phase'] ?? '—') ?></span>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="px-4 py-3 border-t border-base-300 text-[11px] text-base-content/50">
        <?= esc(config(\Config\App::class)->appTimezone) ?> &middot; <?= esc(date('d M Y')) ?>
    </div>
</aside>
