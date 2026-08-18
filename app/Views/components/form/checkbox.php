<?php
/**
 * Checkbox tunggal.
 *
 * Catatan: checkbox yang tidak dicentang TIDAK dikirim browser. Controller
 * karena itu memeriksa keberadaan field (`!== null`), bukan nilainya.
 *
 * @var string      $name
 * @var string      $label
 * @var bool        $checked
 * @var string|null $help
 */
$name    = $name ?? '';
$label   = $label ?? '';
$checked = $checked ?? false;
$help    = $help ?? null;
$id      = 'f-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
?>
<div class="form-control">
    <label class="label cursor-pointer justify-start gap-3 py-1" for="<?= esc($id, 'attr') ?>">
        <input type="checkbox" id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>" value="1"
               class="checkbox checkbox-sm" <?= $checked ? 'checked' : '' ?>>
        <span class="label-text"><?= esc($label) ?></span>
    </label>
    <?php if ($help !== null): ?>
        <p class="text-xs text-base-content/60 ml-8 -mt-1"><?= esc($help) ?></p>
    <?php endif; ?>
</div>
