<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Impor Harga Pasar',
    'subtitle'    => 'Unggah berkas ringkasan perdagangan IDX untuk memperbarui harga penutupan sekaligus.',
    'breadcrumbs' => [
        ['label' => 'Portofolio'],
        ['label' => 'Harga Pasar', 'url' => site_url('market-prices')],
        ['label' => 'Impor'],
    ],
]) ?>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="post" action="<?= site_url('market-prices/import') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <?= component('card', [
                'title'    => 'Unggah Berkas XLSX',
                'subtitle' => 'Harga dibaca dari kolom B (Kode Saham) dan kolom K (Penutupan), mulai baris kedua.',
                'body'     => '<div class="space-y-4">'
                    . '<div>'
                    . '<label class="label"><span class="label-text">Berkas ringkasan perdagangan</span></label>'
                    . '<input type="file" name="prices" accept=".xlsx" required class="file-input file-input-bordered w-full">'
                    . '<p class="text-xs text-base-content/60 mt-2">Berkas dibaca langsung lalu '
                    . '<strong>dihapus</strong>; tidak ada yang tersimpan di server.</p>'
                    . '</div>'

                    . '<div>'
                    . '<label class="label"><span class="label-text">Tanggal harga</span></label>'
                    . '<input type="date" name="price_date" required value="' . esc($date, 'attr') . '" '
                    . 'max="' . esc(date('Y-m-d'), 'attr') . '" class="input input-bordered w-48">'
                    . '<p class="text-xs text-base-content/60 mt-2">Harga disimpan pada tanggal ini. '
                    . 'Bila tanggal perdagangan di dalam berkas berbeda, Anda akan diberi tahu setelah impor.</p>'
                    . '</div>'

                    . '<div>'
                    . '<label class="label"><span class="label-text">Cakupan</span></label>'
                    . '<label class="label cursor-pointer justify-start gap-3 py-1">'
                    . '<input type="radio" name="scope" value="held" class="radio radio-sm" checked>'
                    . '<span class="label-text">Hanya saham yang dimiliki '
                    . '<span class="badge badge-ghost badge-sm">' . esc((string) $held) . ' posisi</span></span>'
                    . '</label>'
                    . '<label class="label cursor-pointer justify-start gap-3 py-1">'
                    . '<input type="radio" name="scope" value="all" class="radio radio-sm">'
                    . '<span class="label-text">Seluruh saham aktif</span>'
                    . '</label>'
                    . '<p class="text-xs text-base-content/60 mt-1">Pilihan pertama sudah cukup untuk menghitung '
                    . 'nilai pasar dan menghilangkan peringatan di dasbor. Menyimpan seluruh emiten menambah '
                    . 'ratusan baris setiap hari bursa, dan hanya berguna bila Anda ingin riwayat harga saham '
                    . 'yang belum dimiliki.</p>'
                    . '</div>'
                    . '</div>',
                'footer'   => '<button type="submit" class="btn btn-primary btn-sm">Impor Harga</button>',
            ]) ?>
        </form>
    </div>

    <div class="space-y-4">
        <?= component('card', [
            'title' => 'Berkas yang Diharapkan',
            'body'  => '<p class="text-sm mb-3">Berkas ekspor ringkasan perdagangan harian dari IDX, apa adanya '
                . '&mdash; tidak perlu diubah menjadi CSV atau dirapikan lebih dulu.</p>'
                . '<div class="overflow-x-auto"><table class="table table-xs">'
                . '<thead><tr><th>Kolom</th><th>Header</th><th>Dipakai</th></tr></thead><tbody>'
                . '<tr><td class="font-mono">B</td><td>Kode Saham</td><td>ya</td></tr>'
                . '<tr><td class="font-mono">G</td><td>Tanggal Perdagangan Terakhir</td><td>pemeriksaan</td></tr>'
                . '<tr><td class="font-mono">K</td><td>Penutupan</td><td>ya</td></tr>'
                . '</tbody></table></div>'
                . '<p class="text-xs text-base-content/60 mt-3">Susunan kolom diperiksa lebih dulu. Bila header '
                . 'tidak cocok, impor ditolak &mdash; berkas IDX yang keliru akan mengisi harga dengan angka '
                . 'dari kolom yang sama sekali lain.</p>',
        ]) ?>

        <?= component('card', [
            'title' => 'Yang Perlu Diketahui',
            'body'  => '<ul class="text-sm space-y-2 list-disc list-inside">'
                . '<li>Harga <strong>nol</strong> (saham disuspensi atau tidak diperdagangkan) dilewati, '
                . 'bukan disimpan sebagai nol &mdash; harga terakhir tetap berlaku.</li>'
                . '<li>Kode saham yang tidak ada di master data dilewati dan dihitung.</li>'
                . '<li>Mengimpor ulang tanggal yang sama <strong>menimpa</strong> harga, tidak menggandakan.</li>'
                . '<li>Harga pasar tidak pernah masuk buku besar dan tidak mengubah book cost.</li>'
                . '</ul>',
        ]) ?>
    </div>
</div>

<?= $this->endSection() ?>
