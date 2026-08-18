<?php
/**
 * Input kuantitas saham berpasangan: LOT dan LEMBAR saling menyesuaikan (§7).
 *
 * Yang dikirim ke server adalah jumlah LEMBAR, karena lembar adalah unit utama
 * perhitungan akuntansi dan portofolio (§7). Lot hanya alat bantu input.
 *
 * @var string      $nameShares  nama field lembar
 * @var string      $nameLots    nama field lot (opsional, ikut dikirim untuk pencatatan)
 * @var int|null    $value       jumlah lembar
 * @var string|null $help
 * @var string|null $error
 * @var bool        $required
 * @var string|null $model       properti Alpine induk untuk preview
 */
$nameShares = $nameShares ?? 'quantity';
$nameLots   = $nameLots ?? 'lots';
$value      = $value ?? old($nameShares) ?? '';
$help       = $help ?? null;
$error      = $error ?? null;
$required   = $required ?? false;
$model      = $model ?? null;
$class      = $class ?? '';
$id         = 'f-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $nameShares);
$perLot     = investment_config()->sharesPerLot;

$initialShares = $value === '' ? '' : (string) (int) $value;

ob_start(); ?>
<div class="grid grid-cols-2 gap-2"
     x-data='{
        perLot: <?= $perLot ?>,
        shares: <?= json_encode($initialShares, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        lots: "",
        init() {
            this.lots = this.shares === "" ? "" : String(Number(this.shares) / this.perLot);
            <?= $model !== null ? '$watch("shares", v => ' . $model . ' = Number(v || 0))' : '' ?>
        },
        fromLots(e) {
            const l = e.target.value;
            this.lots = l;
            this.shares = l === "" ? "" : String(Math.round(Number(l) * this.perLot));
        },
        fromShares(e) {
            const s = e.target.value.replace(/[^0-9]/g, "");
            this.shares = s;
            this.lots = s === "" ? "" : String(Number(s) / this.perLot);
        }
     }'>
    <label class="form-control">
        <span class="label-text text-xs text-base-content/60 pb-1">Lot</span>
        <input type="number" min="0" step="any" inputmode="decimal"
               x-model="lots" @input="fromLots($event)"
               class="input input-bordered input-sm w-full num" placeholder="0">
    </label>
    <label class="form-control">
        <span class="label-text text-xs text-base-content/60 pb-1">Lembar</span>
        <input type="text" id="<?= esc($id, 'attr') ?>" inputmode="numeric" autocomplete="off"
               x-model="shares" @input="fromShares($event)"
               class="input input-bordered input-sm w-full num <?= $error !== null ? 'input-error' : '' ?>"
               <?= $required ? 'required' : '' ?> placeholder="0">
    </label>
    <input type="hidden" name="<?= esc($nameShares, 'attr') ?>" :value="shares">
    <input type="hidden" name="<?= esc($nameLots, 'attr') ?>" :value="lots">
</div>
<?php
$control = ob_get_clean();
$help  ??= '1 lot = ' . fmt_number($perLot) . ' lembar. Nilai yang disimpan adalah jumlah lembar.';

echo component('form/field', [
    'id' => $id, 'label' => $label ?? 'Jumlah', 'control' => $control,
    'help' => $help, 'error' => $error, 'required' => $required, 'class' => $class,
]);
