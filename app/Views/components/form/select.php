<?php
/**
 * Dropdown pilihan.
 *
 * @var string      $name
 * @var string      $label
 * @var array       $options  [value => label]
 * @var string|null $value
 * @var string|null $placeholder teks opsi kosong
 * @var string|null $help
 * @var string|null $error
 * @var bool        $required
 * @var array       $attrs
 * @var string      $class
 */
$name        = $name ?? '';
$label       = $label ?? '';
$options     = $options ?? [];
$value       = $value ?? old($name);
$placeholder = $placeholder ?? '-- Pilih --';
$help        = $help ?? null;
$error       = $error ?? null;
$required    = $required ?? false;
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

$control = '<select id="' . esc($id, 'attr') . '" name="' . esc($name, 'attr') . '"'
    . ' class="select select-bordered w-full ' . ($error !== null ? 'select-error' : '') . '"'
    . ($required ? ' required' : '')
    . ($error !== null ? ' aria-invalid="true"' : '')
    . ($describedBy !== null ? ' aria-describedby="' . esc($describedBy, 'attr') . '"' : '')
    . $extra . '>';

if ($placeholder !== null) {
    $control .= '<option value="">' . esc($placeholder) . '</option>';
}

foreach ($options as $optValue => $optLabel) {
    $selected = (string) $optValue === (string) ($value ?? '') ? ' selected' : '';
    $control .= '<option value="' . esc((string) $optValue, 'attr') . '"' . $selected . '>'
        . esc((string) $optLabel) . '</option>';
}

$control .= '</select>';

echo component('form/field', compact('id', 'label', 'control', 'help', 'error', 'required', 'class'));
