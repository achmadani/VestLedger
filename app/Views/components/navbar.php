<?php
/**
 * Top bar: tombol menu (mobile), judul halaman, pemilih tema, menu user.
 *
 * @var string|null $pageTitle
 */
$user = auth()->user();
?>
<header class="navbar bg-base-100 border-b border-base-300 min-h-14 px-3 sticky top-0 z-30 no-print">
    <div class="flex-none lg:hidden">
        <label for="vl-drawer" class="btn btn-square btn-ghost btn-sm" aria-label="Buka menu navigasi">
            <?= component('icon', ['name' => 'menu', 'class' => 'w-5 h-5']) ?>
        </label>
    </div>

    <div class="flex-1 px-2">
        <span class="font-medium text-sm sm:text-base"><?= esc($pageTitle ?? '') ?></span>
    </div>

    <div class="flex-none flex items-center gap-1">
        <?= component('theme_switcher') ?>

        <?php if ($user !== null): ?>
            <div class="dropdown dropdown-end">
                <button type="button" tabindex="0" class="btn btn-ghost btn-sm gap-2" aria-label="Menu pengguna">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-neutral text-neutral-content text-[11px] font-semibold">
                        <?= esc(strtoupper(substr($user->username ?? $user->email ?? 'U', 0, 1))) ?>
                    </span>
                    <span class="hidden sm:inline text-xs"><?= esc($user->username ?? $user->email) ?></span>
                </button>
                <ul tabindex="0" class="dropdown-content z-50 menu p-2 shadow-lg bg-base-200 rounded-box w-52 mt-1">
                    <li class="menu-title text-[11px]">
                        <span><?= esc($user->email) ?></span>
                    </li>
                    <?php foreach ($user->getGroups() as $group): ?>
                        <li class="px-3 py-1">
                            <span class="badge badge-sm badge-outline"><?= esc($group) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        <form method="post" action="<?= url_to('logout') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="flex items-center gap-2 w-full text-sm">
                                <?= component('icon', ['name' => 'logout', 'class' => 'w-4 h-4']) ?>
                                Keluar
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</header>
