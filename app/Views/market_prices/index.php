<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$canManage = auth()->user()?->can('price.manage') ?? false;
$idxUrl = trim(config(\Config\Investment::class)->idxDailySummaryUrl);

// Hanya http/https yang ditampilkan sebagai tautan. Nilainya memang berasal
// dari konfigurasi milik pemilik aplikasi, bukan dari pengguna — tetapi skema
// seperti javascript: tidak akan pernah menjadi alamat unduhan yang sah, dan
// menolaknya di sini jauh lebih murah daripada memercayai isi .env.
if (! preg_match('#^https?://#i', $idxUrl)) {
    $idxUrl = '';
}
?>

<?= component('page_header', [
    'title'       => 'Harga Pasar',
    'subtitle'    => 'Harga penutupan dimasukkan manual. Harga tidak pernah mengubah book cost historis dan tidak menghasilkan jurnal.',
    'breadcrumbs' => [['label' => 'Portofolio'], ['label' => 'Harga Pasar']],
    'actions'     => $canManage
        ? '<a href="' . site_url('market-prices/import') . '" class="btn btn-sm btn-primary">Impor dari XLSX IDX</a>'
        : null,
]) ?>

<?php if ($idxUrl !== ''): ?>
    <?php
    // Tautan langsung ke halaman unduhan IDX: berkas ringkasan saham harian
    // itulah yang diunggah di /market-prices/import, dan mengetik URL-nya
    // setiap hari bursa jelas menyusahkan. URL-nya parameter di
    // Config\Investment, sebab IDX sesekali mengubah tata letak situsnya.
    ?>
    <div class="alert alert-info mb-4 text-sm no-print">
        <span>
            Harga penutupan dapat diunduh di IDX &mdash;
            <a href="<?= esc($idxUrl) ?>" target="_blank" rel="noopener noreferrer"
               class="link font-medium">Ringkasan Saham</a>.
            <?php if ($canManage): ?>
                Berkas XLSX-nya dapat langsung diunggah lewat
                <a href="<?= site_url('market-prices/import') ?>" class="link">Impor dari XLSX IDX</a>.
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>

<div class="mb-4">
    <form method="get" class="flex items-end gap-2">
        <?= component('form/input', ['name' => 'date', 'label' => 'Tanggal', 'type' => 'date', 'value' => $date, 'class' => 'w-48']) ?>
        <button type="submit" class="btn btn-sm btn-neutral">Tampilkan</button>
    </form>
</div>

<?php if ($canManage): ?>
    <?php
    $inputRows = '';

    foreach ($stocks as $stock) {
        $isHeld = isset($heldIds[$stock->id]);

        $inputRows .= '<tr class="hover">'
            . '<td class="font-mono font-semibold">' . esc($stock->ticker)
                . ($isHeld ? ' <span class="badge badge-primary badge-xs">dimiliki</span>' : '') . '</td>'
            . '<td class="text-sm">' . esc($stock->company_name) . '</td>'
            . '<td class="num"><input type="text" inputmode="decimal" name="prices[' . $stock->id . ']" '
                . 'value="' . esc($existing[$stock->id] ?? '', 'attr') . '" '
                . 'class="input input-bordered input-sm w-32 num" placeholder="—"></td>'
            . '</tr>';
    }
    ?>

    <form method="post" action="<?= site_url('market-prices') ?>" class="mb-6">
        <?= csrf_field() ?>
        <input type="hidden" name="price_date" value="<?= esc($date, 'attr') ?>">

        <?= component('card', [
            'title'    => 'Input Harga — ' . fmt_date($date),
            'subtitle' => 'Baris yang dikosongkan dilewati, bukan dianggap nol. Mengisi ulang tanggal yang sama akan menimpa harga sebelumnya.',
            'flush'    => true,
            'body'     => '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
                . '<thead><tr><th>Ticker</th><th>Perusahaan</th><th class="num">Harga Penutupan</th></tr></thead>'
                . '<tbody>' . $inputRows . '</tbody></table></div>',
            'footer'   => '<button type="submit" class="btn btn-primary btn-sm">Simpan Harga</button>',
        ]) ?>
    </form>
<?php endif; ?>

<?php
$historyRows = '';

foreach ($history as $price) {
    $action = $canManage
        ? component('confirm_form', [
            'action'  => site_url('market-prices/' . $price->id . '/delete'),
            'label'   => 'Hapus',
            'message' => 'Hapus harga ' . $price->ticker . ' tanggal ' . $price->price_date->format('Y-m-d') . '?',
            'class'   => 'btn btn-ghost btn-xs text-error',
        ])
        : '';

    $historyRows .= '<tr class="hover">'
        . '<td class="whitespace-nowrap">' . esc(fmt_date($price->price_date->format('Y-m-d'))) . '</td>'
        . '<td class="font-mono font-semibold">' . esc($price->ticker) . '</td>'
        . '<td class="text-sm">' . esc($price->company_name) . '</td>'
        . '<td class="num">' . esc(fmt_price($price->closingPrice()->toFloat())) . '</td>'
        . '<td class="text-right">' . $action . '</td>'
        . '</tr>';
}
?>

<?= component('card', [
    'title' => 'Riwayat Harga',
    'flush' => true,
    'body'  => $history === []
        ? component('empty_state', ['title' => 'Belum ada harga tercatat', 'icon' => 'chart'])
        : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
            . '<thead><tr><th>Tanggal</th><th>Ticker</th><th>Perusahaan</th><th class="num">Penutupan</th><th></th></tr></thead>'
            . '<tbody>' . $historyRows . '</tbody></table></div>',
]) ?>

<?php if ($pager !== null && $pager->getPageCount() > 1): ?>
    <div class="mt-4"><?= $pager->links() ?></div>
<?php endif; ?>
<?= $this->endSection() ?>
