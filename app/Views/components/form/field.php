<?php
/**
 * Pembungkus satu field form: label, kontrol, pesan bantuan, dan pesan error.
 *
 * Semua komponen form lain memakai wrapper ini agar tampilan & aksesibilitas
 * (label-for, aria-describedby, aria-invalid) konsisten di seluruh aplikasi.
 *
 * @var string      $id
 * @var string      $label
 * @var string      $control HTML kontrol input
 * @var string|null $help
 * @var string|null $error
 * @var bool        $required
 * @var string      $class
 */
$id       = $id ?? '';
$label    = $label ?? '';
$control  = $control ?? '';
$help     = $help ?? null;
$error    = $error ?? null;
$required = $required ?? false;
$class    = $class ?? '';
?>
<div class="form-control w-full <?= esc($class, 'attr') ?>">
    <?php if ($label !== ''): ?>
        <label class="label pb-1" for="<?= esc($id, 'attr') ?>">
            <span class="label-text font-medium">
                <?= esc($label) ?>
                <?php if ($required): ?><span class="text-error" aria-hidden="true">*</span><?php endif; ?>
            </span>
        </label>
    <?php endif; ?>

    <?= $control ?>

    <?php if ($error !== null): ?>
        <p class="text-xs text-error mt-1" id="<?= esc($id, 'attr') ?>-error"><?= esc($error) ?></p>
    <?php elseif ($help !== null): ?>
        <p class="text-xs text-base-content/60 mt-1" id="<?= esc($id, 'attr') ?>-help"><?= esc($help) ?></p>
    <?php endif; ?>
</div>
