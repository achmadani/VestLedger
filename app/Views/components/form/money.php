<?php
/**
 * Input nilai uang dengan pemisah ribuan otomatis.
 *
 * Yang dikirim ke server adalah hidden input berisi ANGKA MENTAH tanpa titik,
 * sehingga server tidak pernah perlu mem-parsing format tampilan — mencegah
 * salah baca nominal, hal yang fatal untuk data keuangan.
 *
 * @var string      $name
 * @var string      $label
 * @var string|int|float|null $value
 * @var string|null $help
 * @var string|null $error
 * @var bool        $required
 * @var string|null $model  nama properti Alpine di form induk untuk preview (§33)
 * @var string      $class
 */
$name     = $name ?? '';
$label    = $label ?? '';
$value    = $value ?? old($name) ?? '';
$help     = $help ?? null;
$error    = $error ?? null;
$required = $required ?? false;
$model    = $model ?? null;
$class    = $class ?? '';
$id       = 'f-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
$symbol   = investment_config()->currencySymbol;

$initial = $value === '' ? '' : (string) (int) round((float) $value);

ob_start(); ?>
<div class="join w-full"
     x-data='{
        raw: <?= json_encode($initial, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        display: "",
        init() { this.display = this.toDisplay(this.raw); <?= $model !== null ? '$watch("raw", v => ' . $model . ' = Number(v || 0))' : '' ?> },
        toDisplay(v) {
            const digits = String(v ?? "").replace(/[^0-9]/g, "");
            return digits === "" ? "" : Number(digits).toLocaleString("id-ID");
        },
        onInput(e) {
            const digits = e.target.value.replace(/[^0-9]/g, "");
            this.raw = digits;
            this.display = this.toDisplay(digits);
        }
     }'>
    <span class="join-item btn btn-neutral btn-disabled no-animation px-3 text-xs"><?= esc($symbol) ?></span>
    <input type="text"
           id="<?= esc($id, 'attr') ?>"
           inputmode="numeric"
           autocomplete="off"
           x-model="display"
           @input="onInput($event)"
           class="input input-bordered join-item w-full num <?= $error !== null ? 'input-error' : '' ?>"
           <?= $required ? 'required' : '' ?>
           <?= $error !== null ? 'aria-invalid="true"' : '' ?>
           placeholder="0">
    <input type="hidden" name="<?= esc($name, 'attr') ?>" :value="raw">
</div>
<?php
$control = ob_get_clean();

echo component('form/field', compact('id', 'label', 'control', 'help', 'error', 'required', 'class'));
