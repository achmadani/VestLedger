<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?= component('page_header', [
    'title'       => 'Impor Master Saham',
    'subtitle'    => 'Perbarui daftar emiten dari data IDX, misalnya setelah ada IPO baru.',
    'breadcrumbs' => [
        ['label' => 'Master Data'],
        ['label' => 'Saham', 'url' => site_url('master/stocks')],
        ['label' => 'Impor'],
    ],
]) ?>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="post" action="<?= site_url('master/stocks/import') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <?= component('card', [
                'title'    => 'Unggah Berkas CSV',
                'subtitle' => 'Impor bersifat memperbarui: saham yang sudah ada disesuaikan profilnya, yang belum ada ditambahkan. Tidak ada yang dihapus.',
                'body'     => '<input type="file" name="csv" accept=".csv,text/csv" required '
                    . 'class="file-input file-input-bordered w-full">'
                    . '<p class="text-xs text-base-content/60 mt-2">Ukuran maksimum mengikuti batas unggah server. '
                    . 'Berkas dibaca langsung dan tidak disimpan di server.</p>',
                'footer'   => '<button type="submit" class="btn btn-primary btn-sm">Impor</button>',
            ]) ?>
        </form>

        <div class="mt-4">
            <?= component('card', [
                'title' => 'Format Kolom',
                'body'  => '<p class="text-sm mb-2">Baris pertama harus berupa header. Dua kolom wajib:</p>'
                    . '<ul class="text-sm list-disc list-inside mb-3">'
                    . '<li><code>ticker</code> — kode saham</li>'
                    . '<li><code>company_name</code> — nama perusahaan</li>'
                    . '</ul>'
                    . '<p class="text-sm mb-2">Kolom berikut bersifat opsional dan ikut diperbarui bila ada:</p>'
                    . '<p class="text-xs font-mono bg-base-200 p-2 rounded">'
                    . 'sector, sub_sector, industry, sub_industry, sub_industry_code,<br>'
                    . 'index_membership, listing_date, listing_board, shares_outstanding, market_cap'
                    . '</p>'
                    . '<p class="text-xs text-base-content/60 mt-3">'
                    . 'Kolom yang tidak disertakan dibiarkan apa adanya. Status aktif/nonaktif tidak pernah '
                    . 'ditimpa impor, sehingga saham yang sengaja Anda nonaktifkan tetap nonaktif.</p>',
            ]) ?>
        </div>
    </div>

    <div>
        <?= component('card', [
            'title' => 'Kondisi Saat Ini',
            'body'  => '<div class="space-y-2 text-sm">'
                . '<div class="flex justify-between"><span class="text-base-content/60">Saham terdaftar</span>'
                . '<span class="num font-medium">' . esc(fmt_number($total)) . '</span></div>'
                . '<div class="flex justify-between"><span class="text-base-content/60">Profil terakhir diperbarui</span>'
                . '<span>' . esc($lastImport ? fmt_date($lastImport->format('Y-m-d')) : 'belum pernah') . '</span></div>'
                . '</div>',
        ]) ?>

        <div class="mt-4">
            <?= component('card', [
                'title' => 'Mengapa CSV, bukan XLSX?',
                'body'  => '<p class="text-sm">Membaca XLSX memerlukan pustaka tambahan sekitar 5 MB beserta '
                    . 'ekstensi PHP zip dan xml — beban yang tidak sepadan untuk pekerjaan yang dilakukan '
                    . 'beberapa kali setahun, dan berisiko tidak tersedia di shared hosting.</p>'
                    . '<p class="text-sm mt-2">Di Excel maupun Google Sheets, pilih '
                    . '<em>Save As</em> lalu format <strong>CSV UTF-8</strong>.</p>',
            ]) ?>
        </div>

        <div class="mt-4">
            <?= component('card', [
                'title' => 'Lewat Terminal',
                'body'  => '<p class="text-sm mb-2">Bisa juga dijalankan tanpa membuka aplikasi:</p>'
                    . '<pre class="text-xs bg-base-200 p-2 rounded overflow-x-auto">php spark vestledger:import-stocks berkas.csv</pre>',
            ]) ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
