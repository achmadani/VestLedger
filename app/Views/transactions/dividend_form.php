<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Catat Dividen',
    'subtitle'    => 'Pendapatan dicatat bruto pada akun 4100; pajaknya dibebankan terpisah ke akun 5200.',
    'breadcrumbs' => [['label' => 'Transaksi', 'url' => site_url('transactions')], ['label' => 'Dividen']],
]) ?>

<form method="post" action="<?= site_url('transactions/dividend') ?>" class="max-w-3xl"
      x-data='dividendForm(<?= json_encode($positions, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
    <?= csrf_field() ?>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <?= component('card', [
                'body' => '<div class="grid gap-3 sm:grid-cols-2">'
                    . component('form/input', [
                        'name' => 'transaction_date', 'label' => 'Tanggal Pembayaran', 'type' => 'date',
                        'value' => old('transaction_date', date('Y-m-d')), 'required' => true,
                    ])
                    . component('form/select', [
                        'name' => 'securities_account_id', 'label' => 'Rekening Sekuritas',
                        'options' => $accounts, 'value' => old('securities_account_id'), 'required' => true,
                        'attrs' => ['x-model' => 'accountId'],
                    ])
                    . component('form/select', [
                        'name' => 'stock_id', 'label' => 'Saham',
                        'options' => $stocks, 'value' => old('stock_id'), 'required' => true,
                        'attrs' => ['x-model' => 'stockId'],
                    ])
                    . component('form/input', [
                        'name' => 'quantity_eligible', 'label' => 'Lembar Berhak', 'type' => 'number',
                        'value' => old('quantity_eligible'), 'required' => true,
                        'attrs' => ['min' => '1', 'x-model' => 'quantity', 'class' => 'input input-bordered w-full num'],
                    ])
                    . component('form/input', [
                        'name' => 'dividend_per_share', 'label' => 'Dividen per Lembar', 'type' => 'number',
                        'value' => old('dividend_per_share'), 'required' => true,
                        'attrs' => ['step' => '0.0001', 'min' => '0', 'x-model' => 'perShare', 'class' => 'input input-bordered w-full num'],
                    ])
                    . component('form/money', ['name' => 'tax', 'label' => 'Pajak Dividen', 'model' => 'tax'])
                    . '</div>'
                    . component('form/textarea', ['name' => 'notes', 'label' => 'Catatan', 'rows' => 2, 'class' => 'mt-3']),
            ]) ?>
        </div>

        <?php ob_start(); ?>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-base-content/60">Dividen Bruto</span>
                <span class="num font-medium" x-text="fmt(gross)"></span>
            </div>
            <div class="flex justify-between">
                <span class="text-base-content/60">Pajak</span>
                <span class="num" x-text="fmt(Number(tax || 0))"></span>
            </div>
            <div class="flex justify-between border-t border-base-300 pt-2">
                <span class="font-medium">Dividen Netto (kas masuk)</span>
                <span class="num font-semibold" x-text="fmt(gross - Number(tax || 0))"></span>
            </div>
            <template x-if="heldQuantity !== null && Number(quantity) > heldQuantity">
                <div class="alert alert-warning text-xs mt-2">
                    Lembar berhak melebihi kepemilikan saat ini
                    (<span x-text="heldQuantity.toLocaleString('id-ID')"></span> lembar).
                    Periksa kembali — kecuali dividen ini memang untuk posisi yang sudah dijual.
                </div>
            </template>
        </div>
        <?php $preview = ob_get_clean(); ?>

        <div>
            <?= component('card', ['title' => 'Preview', 'body' => $preview]) ?>
            <div class="flex items-center gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-sm flex-1">Simpan</button>
                <a href="<?= site_url('transactions') ?>" class="btn btn-ghost btn-sm">Batal</a>
            </div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('pageScripts') ?>
<script>
    function dividendForm(positions) {
        return {
            positions,
            accountId: '', stockId: '', quantity: 0, perShare: 0, tax: 0,
            get heldQuantity() {
                const key = this.accountId + ':' + this.stockId;
                return this.positions[key] !== undefined ? Number(this.positions[key]) : null;
            },
            get gross() { return Number(this.quantity || 0) * Number(this.perShare || 0); },
            fmt(value) {
                if (!isFinite(value)) return '-';
                return 'Rp ' + Number(value).toLocaleString('id-ID', { maximumFractionDigits: 0 });
            },
        };
    }
</script>
<?= $this->endSection() ?>
