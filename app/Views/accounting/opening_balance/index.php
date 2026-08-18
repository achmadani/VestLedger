<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var array $current */
$hasOpening = $current !== [];
?>

<?= component('page_header', [
    'title'       => 'Saldo Awal',
    'subtitle'    => 'Posisi kas dan saham yang sudah dimiliki sebelum aplikasi ini mulai dipakai.',
    'breadcrumbs' => [['label' => 'Akuntansi'], ['label' => 'Saldo Awal']],
]) ?>

<?php if ($hasOpening): ?>
    <?php
    $rows = '';

    foreach ($current['positions'] as $p) {
        $rows .= '<tr class="hover">'
            . '<td class="num">' . esc(fmt_qty($p['quantity'])) . '</td>'
            . '<td class="num">' . esc(fmt_money((float) $p['amount'])) . '</td>'
            . '<td class="num">' . esc(fmt_avg_cost((float) $p['amount'] / max(1, (int) $p['quantity']))) . '</td>'
            . '</tr>';
    }
    ?>

    <div class="alert alert-success mb-4">
        <?= component('icon', ['name' => 'check', 'class' => 'w-5 h-5 shrink-0']) ?>
        <span class="text-sm">
            Saldo awal sudah tercatat per <?= esc(fmt_date($current['as_of_date'])) ?>.
        </span>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-4">
        <?= component('stat', ['label' => 'Kas Awal', 'value' => fmt_rupiah($current['cash']->toFloat())]) ?>
        <?= component('stat', ['label' => 'Book Value Portofolio', 'value' => fmt_rupiah($current['portfolio']->toFloat())]) ?>
        <?= component('stat', ['label' => 'Modal Disetor', 'value' => fmt_rupiah($current['capital']->toFloat())]) ?>
        <?= component('stat', [
            'label'      => 'Laba Ditahan',
            'value'      => fmt_signed($current['retained']->toFloat()),
            'sub'        => 'Angka penyeimbang: aset − modal',
            'valueClass' => amount_class($current['retained']->toFloat()),
        ]) ?>
    </div>

    <?= component('card', [
        'title'    => 'Rincian Saldo Awal',
        'subtitle' => 'Total aset ' . fmt_rupiah($current['assets']->toFloat())
            . ' seimbang dengan modal disetor ditambah laba ditahan.',
        'flush'    => true,
        'body'     => $current['positions'] === []
            ? component('empty_state', ['title' => 'Saldo awal hanya berisi kas'])
            : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
                . '<thead><tr><th class="num">Lembar</th><th class="num">Book Value</th>'
                . '<th class="num">Average Cost</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div>',
    ]) ?>

    <div class="mt-4">
        <?= component('confirm_form', [
            'action'  => site_url('accounting/opening-balance/reset'),
            'label'   => 'Hapus Saldo Awal',
            'message' => 'Hapus saldo awal? Sistem akan membuat jurnal pembalik; jurnal aslinya tetap tersimpan.',
            'class'   => 'btn btn-sm btn-error',
        ]) ?>
        <p class="text-xs text-base-content/60 mt-2">
            Penghapusan hanya mungkin selama belum ada transaksi sama sekali. Setelah ada transaksi,
            average cost-nya sudah dibangun di atas posisi awal ini.
        </p>
    </div>
<?php else: ?>
    <div class="alert alert-info mb-4">
        <?= component('icon', ['name' => 'info', 'class' => 'w-5 h-5 shrink-0']) ?>
        <div class="text-sm">
            <p class="font-medium">Laba ditahan dihitung otomatis.</p>
            <p class="text-xs opacity-80 mt-0.5">
                Isi kas, posisi saham, dan modal yang pernah disetorkan. Selisihnya menjadi laba
                ditahan — akumulasi laba masa lalu — sehingga saldo awal dijamin balance.
                Saldo awal harus bertanggal SEBELUM seluruh transaksi.
            </p>
        </div>
    </div>

    <form method="post" action="<?= site_url('accounting/opening-balance') ?>"
          x-data='openingBalance(<?= json_encode(array_keys($accounts), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
        <?= csrf_field() ?>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-4">
                <?php
                $cashFields = '';

                foreach ($accounts as $id => $label) {
                    $cashFields .= component('form/money', [
                        'name'  => 'cash[' . $id . ']',
                        'label' => $label,
                        'model' => 'cash[' . $id . ']',
                    ]);
                }
                ?>

                <?= component('card', [
                    'title'    => 'Kas per Rekening',
                    'subtitle' => 'Saldo kas/RDN pada masing-masing rekening per tanggal saldo awal.',
                    'body'     => $accounts === []
                        ? component('empty_state', [
                            'title'       => 'Belum ada rekening sekuritas',
                            'description' => 'Tambahkan sekuritas terlebih dahulu di Master Data.',
                        ])
                        : '<div class="grid gap-3 sm:grid-cols-2">' . $cashFields . '</div>',
                ]) ?>

                <?= component('card', [
                    'title'    => 'Posisi Saham',
                    'subtitle' => 'Book value adalah seluruh biaya perolehan, bukan nilai pasar saat ini.',
                    'body'     => '<template x-for="(row, i) in positions" :key="i">'
                        . '<div class="grid gap-2 sm:grid-cols-12 items-end mb-2 pb-2 border-b border-base-200">'
                        . '<div class="sm:col-span-4"><label class="label label-text text-xs pb-1">Rekening</label>'
                        . '<select :name="`positions[${i}][securities_account_id]`" class="select select-bordered select-sm w-full">'
                        . '<option value="">-- Pilih --</option>'
                        . implode('', array_map(
                            static fn ($id, $label): string => '<option value="' . esc((string) $id, 'attr') . '">' . esc($label) . '</option>',
                            array_keys($accounts),
                            $accounts
                        ))
                        . '</select></div>'
                        . '<div class="sm:col-span-3"><label class="label label-text text-xs pb-1">Saham</label>'
                        . '<select :name="`positions[${i}][stock_id]`" class="select select-bordered select-sm w-full">'
                        . '<option value="">-- Pilih --</option>'
                        . implode('', array_map(
                            static fn ($id, $label): string => '<option value="' . esc((string) $id, 'attr') . '">' . esc($label) . '</option>',
                            array_keys($stocks),
                            $stocks
                        ))
                        . '</select></div>'
                        . '<div class="sm:col-span-2"><label class="label label-text text-xs pb-1">Lembar</label>'
                        . '<input type="number" min="0" :name="`positions[${i}][quantity]`" x-model="row.quantity" '
                        . 'class="input input-bordered input-sm w-full num"></div>'
                        . '<div class="sm:col-span-3"><label class="label label-text text-xs pb-1">Book Value</label>'
                        . '<input type="number" min="0" :name="`positions[${i}][book_value]`" x-model="row.book_value" '
                        . 'class="input input-bordered input-sm w-full num"></div>'
                        . '</div></template>'
                        . '<button type="button" class="btn btn-sm btn-ghost mt-2" @click="addRow()">+ Tambah Baris</button>',
                ]) ?>
            </div>

            <?php ob_start(); ?>
            <div class="space-y-3 text-sm">
                <?= component('form/input', [
                    'name'     => 'as_of_date',
                    'label'    => 'Tanggal Saldo Awal',
                    'type'     => 'date',
                    'value'    => old('as_of_date', date('Y') . '-01-01'),
                    'required' => true,
                    'help'     => 'Harus mendahului seluruh transaksi.',
                ]) ?>

                <?= component('form/money', [
                    'name'  => 'paid_in_capital',
                    'label' => 'Modal Disetor',
                    'model' => 'capital',
                    'help'  => 'Total dana yang pernah Anda setorkan.',
                ]) ?>

                <div class="border-t border-base-300 pt-3 space-y-2">
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Total Kas</span>
                        <span class="num" x-text="fmt(totalCash)"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Total Book Value</span>
                        <span class="num" x-text="fmt(totalPortfolio)"></span>
                    </div>
                    <div class="flex justify-between font-medium border-t border-base-300 pt-2">
                        <span>Total Aset</span>
                        <span class="num" x-text="fmt(totalAssets)"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Modal Disetor</span>
                        <span class="num" x-text="fmt(Number(capital || 0))"></span>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span>Laba Ditahan</span>
                        <span class="num" :class="retained < 0 ? 'text-error' : 'text-success'"
                              x-text="fmt(retained)"></span>
                    </div>
                    <p class="text-[11px] text-base-content/50">
                        Laba ditahan adalah selisih aset dan modal disetor. Nilai negatif berarti
                        akumulasi rugi, dan tetap sah.
                    </p>
                </div>
            </div>
            <?php $preview = ob_get_clean(); ?>

            <div>
                <div class="lg:sticky lg:top-20">
                    <?= component('card', ['title' => 'Ringkasan', 'body' => $preview]) ?>
                    <button type="submit" class="btn btn-primary btn-sm w-full mt-4">Simpan Saldo Awal</button>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('pageScripts') ?>
<script>
    function openingBalance(accountIds) {
        return {
            cash: {},
            capital: 0,
            positions: [{ quantity: '', book_value: '' }],

            init() {
                accountIds.forEach(id => { this.cash[id] = 0; });
            },
            addRow() {
                this.positions.push({ quantity: '', book_value: '' });
            },
            get totalCash() {
                return Object.values(this.cash).reduce((sum, v) => sum + Number(v || 0), 0);
            },
            get totalPortfolio() {
                return this.positions.reduce((sum, r) => sum + Number(r.book_value || 0), 0);
            },
            get totalAssets() { return this.totalCash + this.totalPortfolio; },
            get retained() { return this.totalAssets - Number(this.capital || 0); },
            fmt(value) {
                if (!isFinite(value)) return '-';
                return 'Rp ' + Number(value).toLocaleString('id-ID', { maximumFractionDigits: 0 });
            },
        };
    }
</script>
<?= $this->endSection() ?>
