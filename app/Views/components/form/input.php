<?php
/**
 * Input teks/tanggal/email/password generik.
 *
 * @var string      $name
 * @var string      $label
 * @var string      $type
 * @var string|null $value
 * @var string|null $placeholder
 * @var string|null $help
 * @var string|null $error
 * @var bool        $required
 * @var bool        $readonly
 * @var array       $attrs  atribut tambahan, mis. ['x-model' => 'ticker']
 * @var string      $class
 */
$name        = $name ?? '';
$label       = $label ?? '';
$type        = $type ?? 'text';
$value       = $value ?? old($name);
$placeholder = $placeholder ?? '';
$help        = $help ?? null;
$error       = $error ?? (function_exists('validation_show_error') ? null : null);
$required    = $required ?? false;
$readonly    = $readonly ?? false;
$attrs       = $attrs ?? [];
$class       = $class ?? '';
$id          = $attrs['id'] ?? ('f-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));

$extra = '';

foreach ($attrs as $key => $val) {
    if ($key === 'id') {
        continue;
    }
    $extra .= ' ' . $key . '="' . esc((string) $val, 'attr') . '"';
}

$describedBy = $error !== null ? $id . '-error' : ($help !== null ? $id . '-help' : null);

$control = '<input type="' . esc($type, 'attr') . '"'
    . ' id="' . esc($id, 'attr') . '"'
    . ' name="' . esc($name, 'attr') . '"'
    . ' value="' . esc((string) ($value ?? ''), 'attr') . '"'
    . ($placeholder !== '' ? ' placeholder="' . esc($placeholder, 'attr') . '"' : '')
    . ' class="input input-bordered w-full ' . ($error !== null ? 'input-error' : '') . '"'
    . ($required ? ' required' : '')
    . ($readonly ? ' readonly' : '')
    . ($error !== null ? ' aria-invalid="true"' : '')
    . ($describedBy !== null ? ' aria-describedby="' . esc($describedBy, 'attr') . '"' : '')
    . $extra
    . '>';

echo component('form/field', compact('id', 'label', 'control', 'help', 'error', 'required', 'class'));
