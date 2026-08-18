<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
use App\Enums\StockTransactionType;

/** @var StockTransactionType $type */
$isBuy    = $type === StockTransactionType::Buy;
$slug     = $isBuy ? 'buy' : 'sell';
$perLot   = investment_config()->sharesPerLot;
?>

<?= component('page_header', [
    'title'       => $isBuy ? 'Beli Saham' : 'Jual Saham',
    'subtitle'    => $isBuy
        ? 'Seluruh biaya perolehan dikapitalisasi ke book cost — pembelian tidak menghasilkan beban.'
        : 'Book value yang dilepas dihitung proporsional terhadap posisi saat transaksi.',
    'breadcrumbs' => [
        ['label' => 'Transaksi', 'url' => site_url('transactions')],
        ['label' => $isBuy ? 'Beli' : 'Jual'],
    ],
]) ?>

<form method="post" action="<?= site_url('transactions/' . $slug) ?>"
      x-data='stockForm(<?= json_encode([
          "positions" => $positions,
          "cash"      => $cash,
          "feeRates"  => $feeRates,
          "perLot"    => $perLot,
          "isBuy"     => $isBuy,
          "levyPct"   => investment_config()->exchangeLevyPercent,
          "taxPct"    => investment_config()->sellTaxPercent,
          "stampDuty" => investment_config()->stampDutyAmount,
          "stampMin"  => investment_config()->stampDutyThreshold,
      ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
    <?= csrf_field() ?>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <?= component('card', [
                'title' => 'Data Transaksi',
                'body'  => '<div class="grid gap-3 sm:grid-cols-2">'
                    . component('form/input', [
                        'name'     => 'transaction_date',
                        'label'    => 'Tanggal',
                        'type'     => 'date',
                        'value'    => old('transaction_date', date('Y-m-d')),
                        'required' => true,
                    ])
                    . component('form/select', [
                        'name'     => 'securities_account_id',
                        'label'    => 'Rekening Sekuritas',
                        'options'  => $accounts,
                        'value'    => old('securities_account_id'),
                        'required' => true,
                        'attrs'    => ['x-model' => 'accountId'],
                    ])
                    . component('form/stock_search', ['name' => 'stock_id', 'model' => 'stockId'])
                    . component('form/input', [
                        'name'     => 'price',
                        'label'    => 'Harga per Lembar',
                        'type'     => 'number',
                        'value'    => old('price'),
                        'required' => true,
                        'attrs'    => ['step' => '0.0001', 'min' => '0', 'x-model' => 'price', 'class' => 'input input-bordered w-full num'],
                    ])
                    . '</div>'
                    . '<div class="mt-3">'
                    . component('form/quantity', [
                        'nameShares' => 'quantity',
                        'label'      => 'Jumlah',
                        'required'   => true,
                        'model'      => 'quantity',
                    ])
                    . '</div>',
            ]) ?>

            <?= component('card', [
                'title'    => 'Biaya Transaksi',
                'subtitle' => $isBuy
                    ? 'Seluruhnya masuk ke book cost, tidak menjadi beban periode berjalan.'
                    : 'Fee menjadi beban 5000; pajak & levy menjadi beban 5200.',
                'body'     => '<div class="grid gap-3 sm:grid-cols-3">'
                    . '<label class="form-control"><span class="label-text text-xs pb-1">Broker Fee</span>'
                    . '<input type="number" min="0" step="1" name="broker_fee" x-model="brokerFee" @input="touched = true" '
                    . 'class="input input-bordered input-sm w-full num"></label>'
                    . '<label class="form-control"><span class="label-text text-xs pb-1">Pajak</span>'
                    . '<input type="number" min="0" step="1" name="tax" x-model="tax" @input="touched = true" '
                    . 'class="input input-bordered input-sm w-full num"></label>'
                    . '<label class="form-control"><span class="label-text text-xs pb-1">Levy</span>'
                    . '<input type="number" min="0" step="1" name="levy" x-model="levy" @input="touched = true" '
                    . 'class="input input-bordered input-sm w-full num"></label>'
                    . '</div>'
                    . '<div class="flex items-center gap-2 mt-2">'
                    . '<button type="button" class="btn btn-xs btn-ghost" @click="touched = false; applyFees()">'
                    . 'Hitung ulang dari tarif sekuritas</button>'
                    . '<span class="text-xs text-base-content/50" x-show="touched" x-cloak>'
                    . 'Biaya diubah manual — tidak lagi mengikuti tarif otomatis.</span>'
                    . '</div>'
                    . component('form/textarea', ['name' => 'notes', 'label' => 'Catatan', 'rows' => 2, 'class' => 'mt-3']),
            ]) ?>
        </div>

        <?php
        // Preview §33. Seluruh angkanya dihitung ulang di server saat disimpan —
        // ini hanya bantuan visual agar pengguna tahu dampak transaksinya sebelum
        // menekan simpan.
        ob_start(); ?>
        <div class="space-y-2 text-sm">
            <template x-if="!hasPosition && !isBuy">
                <div class="alert alert-warning text-xs">
                    Tidak ada posisi untuk kombinasi rekening dan saham ini.
                </div>
            </template>

            <div class="flex justify-between">
                <span class="text-base-content/60">Gross Amount</span>
                <span class="num font-medium" x-text="fmt(gross)"></span>
            </div>

            <template x-if="isBuy">
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Total Biaya</span>
                        <span class="num" x-text="fmt(charges)"></span>
                    </div>
                    <div class="flex justify-between border-t border-base-300 pt-2">
                        <span class="font-medium">Total Cost (kas keluar)</span>
                        <span class="num font-semibold" x-text="fmt(gross + charges)"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Average Cost Saat Ini</span>
                        <span class="num" x-text="currentQty > 0 ? fmt(currentAvg, 2) : '-'"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Average Cost Baru</span>
                        <span class="num font-medium" x-text="quantity > 0 ? fmt(newAverage, 2) : '-'"></span>
                    </div>
                </div>
            </template>

            <template x-if="!isBuy">
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Kepemilikan Saat Ini</span>
                        <span class="num" x-text="fmtQty(currentQty)"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Average Cost Saat Ini</span>
                        <span class="num" x-text="currentQty > 0 ? fmt(currentAvg, 2) : '-'"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Book Value Dilepas</span>
                        <span class="num" x-text="fmt(bookValueSold)"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Estimasi Biaya</span>
                        <span class="num" x-text="fmt(charges)"></span>
                    </div>
                    <div class="flex justify-between border-t border-base-300 pt-2">
                        <span class="font-medium">Estimasi Kas Diterima</span>
                        <span class="num font-semibold" x-text="fmt(gross - charges)"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium">Estimasi Realized G/L</span>
                        <span class="num font-semibold"
                              :class="realizedNet > 0 ? 'text-success' : (realizedNet < 0 ? 'text-error' : '')"
                              x-text="signed(realizedNet)"></span>
                    </div>
                    <p class="text-[11px] text-base-content/50 pt-1">
                        Realized G/L di atas sudah dikurangi biaya (§11 Step 3). Yang masuk akun 4000/4001
                        adalah gross &minus; book value, yaitu <span class="num" x-text="signed(realizedGross)"></span>.
                    </p>
                </div>
            </template>

            <template x-if="stampDutyDue">
                <div class="flex justify-between">
                    <span class="text-base-content/60">Bea Materai</span>
                    <span class="num" x-text="fmt(stampDuty)"></span>
                </div>
            </template>

            <div class="border-t border-base-300 pt-2 mt-2 space-y-2">
                <div class="flex justify-between">
                    <span class="text-base-content/60">Kas Rekening Saat Ini</span>
                    <span class="num" x-text="accountId ? fmt(currentCash) : '-'"></span>
                </div>
                <div class="flex justify-between">
                    <span class="font-medium">Kas Setelah Transaksi</span>
                    <span class="num font-semibold"
                          :class="projectedCash < 0 ? 'text-warning' : ''"
                          x-text="accountId ? fmt(projectedCash) : '-'"></span>
                </div>
            </div>

            <template x-if="accountId && projectedCash < 0">
                <div class="alert alert-warning text-xs mt-2">
                    Transaksi ini membuat saldo kas rekening menjadi negatif.
                    Pencatatan tetap dilanjutkan — transaksi memang boleh dimasukkan mundur —
                    tetapi periksa apakah ada top up yang belum dicatat.
                </div>
            </template>

            <template x-if="quantity > currentQty && !isBuy">
                <div class="alert alert-error text-xs mt-2">
                    Jumlah jual melebihi kepemilikan. Transaksi akan ditolak.
                </div>
            </template>
        </div>
        <?php
        $preview = ob_get_clean();
        ?>

        <div class="lg:col-span-1">
            <div class="lg:sticky lg:top-20">
                <?= component('card', ['title' => 'Preview', 'body' => $preview]) ?>

                <div class="flex items-center gap-2 mt-4">
                    <button type="submit" class="btn btn-primary btn-sm flex-1">
                        Simpan <?= $isBuy ? 'Pembelian' : 'Penjualan' ?>
                    </button>
                    <a href="<?= site_url('transactions') ?>" class="btn btn-ghost btn-sm">Batal</a>
                </div>
            </div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('pageScripts') ?>
<script>
    /**
     * Preview transaksi saham (§33).
     *
     * Perhitungan di sini HANYA untuk tampilan. Server menghitung ulang semuanya
     * saat menyimpan, jadi angka di layar tidak pernah menjadi sumber kebenaran.
     */
    function stockForm(config) {
        return {
            ...config,
            accountId: '', stockId: '',
            quantity: 0, price: 0, brokerFee: 0, tax: 0, levy: 0,

            /** Sekali pengguna mengubah biaya sendiri, isian otomatis berhenti. */
            touched: false,

            init() {
                // Biaya dihitung ulang setiap kali dasar perhitungannya berubah,
                // kecuali pengguna sudah mengetik angkanya sendiri.
                ['accountId', 'quantity', 'price'].forEach(field => {
                    this.$watch(field, () => this.applyFees());
                });
            },

            /**
             * Memecah tarif all-in menjadi levy, pajak, dan sisanya fee broker —
             * cerminan persis dari perhitungan di server, semata agar pengguna
             * melihat angkanya sebelum menyimpan. Server tetap menghitung ulang.
             */
            applyFees() {
                if (this.touched) return;

                const rate = this.feeRates[this.accountId];
                if (!rate || !this.gross) {
                    this.brokerFee = 0; this.tax = 0; this.levy = 0;
                    return;
                }

                const allIn = Math.round(this.gross * (this.isBuy ? rate.buy : rate.sell) / 100);
                const levy  = Math.round(this.gross * this.levyPct / 100);
                const tax   = this.isBuy ? 0 : Math.round(this.gross * this.taxPct / 100);

                this.levy      = levy;
                this.tax       = tax;
                this.brokerFee = Math.max(0, allIn - levy - tax);
            },

            get stampDutyDue() {
                return this.gross > this.stampMin;
            },

            get positionKey() { return this.accountId + ':' + this.stockId; },
            get position() { return this.positions[this.positionKey] || null; },
            get hasPosition() { return this.position !== null; },
            get currentQty() { return this.position ? Number(this.position.quantity) : 0; },
            get currentBookValue() { return this.position ? Number(this.position.book_value) : 0; },
            get currentAvg() { return this.currentQty > 0 ? this.currentBookValue / this.currentQty : 0; },

            get gross() { return Number(this.price || 0) * Number(this.quantity || 0); },
            get charges() { return Number(this.brokerFee || 0) + Number(this.tax || 0) + Number(this.levy || 0); },

            get newAverage() {
                const qty = this.currentQty + Number(this.quantity || 0);
                if (qty <= 0) return 0;
                return (this.currentBookValue + this.gross + this.charges) / qty;
            },

            get bookValueSold() {
                if (this.currentQty <= 0) return 0;
                const qty = Math.min(Number(this.quantity || 0), this.currentQty);
                return this.currentBookValue * qty / this.currentQty;
            },

            get realizedGross() { return this.gross - this.bookValueSold; },
            get realizedNet() { return this.realizedGross - this.charges; },

            get currentCash() { return Number(this.cash[this.accountId] || 0); },

            /**
             * Saldo kas seandainya transaksi ini disimpan.
             * Beli mengurangi kas sebesar gross + biaya; jual menambah kas netto.
             */
            get projectedCash() {
                // Bea materai ikut diperhitungkan karena ia juga mengurangi kas
                // pada hari yang sama.
                const duty = this.stampDutyDue ? this.stampDuty : 0;

                return this.isBuy
                    ? this.currentCash - (this.gross + this.charges) - duty
                    : this.currentCash + (this.gross - this.charges) - duty;
            },

            fmt(value, decimals = 0) {
                if (!isFinite(value)) return '-';
                return 'Rp ' + Number(value).toLocaleString('id-ID', {
                    minimumFractionDigits: decimals, maximumFractionDigits: decimals,
                });
            },
            signed(value) {
                if (!isFinite(value) || value === 0) return 'Rp 0';
                return (value > 0 ? '+' : '-') + this.fmt(Math.abs(value)).replace('Rp ', 'Rp ');
            },
            fmtQty(value) {
                return Number(value || 0).toLocaleString('id-ID') + ' lembar';
            },
        };
    }

    /**
     * Kotak ketik-cari saham.
     *
     * Mencari di server dan membatasi hasilnya, alih-alih mengirim seluruh
     * daftar emiten ke browser pada setiap pembukaan form.
     */
    function stockSearch() {
        return {
            term: '', results: [], open: false, highlight: 0, selected: null,
            controller: null,

            async search() {
                const q = this.term.trim();

                if (q.length < 2) { this.results = []; this.open = false; return; }

                // Batalkan permintaan sebelumnya agar hasil lama tidak menimpa
                // hasil ketikan terbaru.
                this.controller?.abort();
                this.controller = new AbortController();

                try {
                    const res = await fetch('<?= site_url('api/stocks/search') ?>?q=' + encodeURIComponent(q),
                        { signal: this.controller.signal, headers: { 'Accept': 'application/json' } });

                    if (!res.ok) { this.results = []; return; }

                    this.results  = await res.json();
                    this.highlight = 0;
                    this.open      = this.results.length > 0;
                } catch (e) {
                    if (e.name !== 'AbortError') { this.results = []; }
                }
            },

            move(step) {
                if (!this.results.length) return;
                this.highlight = (this.highlight + step + this.results.length) % this.results.length;
            },

            choose(row) {
                if (!row) return;

                this.selected = row;
                this.term     = row.ticker;
                this.open     = false;

                // stockId tidak didefinisikan di scope ini, sehingga Alpine
                // meneruskan penulisannya ke scope induk — yaitu form transaksi,
                // yang memakainya untuk posisi dan preview.
                this.stockId = String(row.id);
            },
        };
    }
</script>
<?= $this->endSection() ?>
