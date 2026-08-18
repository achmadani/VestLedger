<?php
/**
 * Modal DaisyUI yang dikendalikan Alpine (§2).
 *
 * Dibuka dari mana saja dengan:  $dispatch('open-modal', 'nama-modal')
 *
 * @var string      $id
 * @var string      $title
 * @var string      $body    HTML
 * @var string|null $footer  HTML
 */
$id     = $id ?? 'modal';
$title  = $title ?? '';
$body   = $body ?? '';
$footer = $footer ?? null;
?>
<div x-data="{ open: false }"
     x-on:open-modal.window="if ($event.detail === '<?= esc($id, 'js') ?>') open = true"
     x-on:close-modal.window="open = false"
     x-on:keydown.escape.window="open = false">
    <div class="modal" :class="{ 'modal-open': open }" x-cloak role="dialog" aria-modal="true">
        <div class="modal-box" @click.outside="open = false">
            <h3 class="text-lg font-semibold mb-2"><?= esc($title) ?></h3>
            <div class="text-sm"><?= $body ?></div>
            <div class="modal-action">
                <?php if ($footer !== null): ?>
                    <?= $footer ?>
                <?php else: ?>
                    <button type="button" class="btn btn-sm" @click="open = false">Tutup</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-backdrop bg-black/40" @click="open = false"></div>
    </div>
</div>
