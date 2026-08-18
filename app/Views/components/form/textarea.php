<?php
/**
 * Textarea untuk catatan/deskripsi.
 *
 * @var string      $name
 * @var string      $label
 * @var string|null $value
 * @var int         $rows
 */
$name     = $name ?? '';
$label    = $label ?? '';
$value    = $value ?? old($name);
$rows     = $rows ?? 3;
$help     = $help ?? null;
$error    = $error ?? null;
$required = $required ?? false;
$class    = $class ?? '';
$id       = 'f-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);

$control = '<textarea id="' . esc($id, 'attr') . '" name="' . esc($name, 'attr') . '"'
    . ' rows="' . (int) $rows . '"'
    . ' class="textarea textarea-bordered w-full ' . ($error !== null ? 'textarea-error' : '') . '"'
    . ($required ? ' required' : '')
    . '>' . esc((string) ($value ?? '')) . '</textarea>';

echo component('form/field', compact('id', 'label', 'control', 'help', 'error', 'required', 'class'));
