<?php
/**
 * Pemilih tema DaisyUI (§30).
 *
 * Tema disimpan di localStorage dan diterapkan sebagai atribut data-theme pada
 * elemen <html>. Tidak ada request ke server dan tidak ada business logic yang
 * bergantung pada tema — mengganti tema murni urusan presentation layer (§40.13).
 */
$themes  = investment_config()->themes;
$default = investment_config()->defaultTheme;
?>
<div class="dropdown dropdown-end"
     x-data='{
        current: <?= json_encode($default) ?>,
        init() {
            try { this.current = localStorage.getItem("vestledger-theme") || <?= json_encode($default) ?>; } catch (e) {}
            this.apply(this.current);
        },
        apply(theme) {
            this.current = theme;
            document.documentElement.setAttribute("data-theme", theme);
            try { localStorage.setItem("vestledger-theme", theme); } catch (e) {}
        }
     }'>
    <button type="button" tabindex="0" class="btn btn-ghost btn-sm gap-1" aria-label="Ganti tema tampilan">
        <?= component('icon', ['name' => 'palette', 'class' => 'w-4 h-4']) ?>
        <span class="hidden sm:inline text-xs" x-text="current"></span>
    </button>
    <ul tabindex="0" class="dropdown-content z-50 menu p-2 shadow-lg bg-base-200 rounded-box w-44 mt-1">
        <?php foreach ($themes as $key => $label): ?>
            <li>
                <button type="button" @click="apply('<?= esc($key, 'js') ?>')"
                        :class="current === '<?= esc($key, 'js') ?>' ? 'active' : ''"
                        class="text-sm justify-between">
                    <span><?= esc($label) ?></span>
                    <span x-show="current === '<?= esc($key, 'js') ?>'" x-cloak aria-hidden="true">&#10003;</span>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
