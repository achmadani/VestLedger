<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/** @var App\Entities\Security $security */
/** @var list<App\Entities\SecuritiesAccount> $accounts */
$canManage = auth()->user()?->can('masterdata.manage') ?? false;

$actions = '';

if ($canManage) {
    $actions .= '<a href="' . site_url('master/securities/' . $security->id . '/edit') . '" class="btn btn-sm">Ubah</a>';

    $actions .= $security->is_active
        ? component('confirm_form', [
            'action'  => site_url('master/securities/' . $security->id . '/deactivate'),
            'label'   => 'Nonaktifkan',
            'message' => 'Nonaktifkan ' . $security->code . '? Sekuritas ini tidak akan muncul lagi di form transaksi baru.',
            'class'   => 'btn btn-sm btn-warning',
        ])
        : component('confirm_form', [
            'action'  => site_url('master/securities/' . $security->id . '/activate'),
            'label'   => 'Aktifkan',
            'message' => 'Aktifkan kembali ' . $security->code . '?',
            'class'   => 'btn btn-sm btn-success',
        ]);

    if ($accounts === []) {
        $actions .= component('confirm_form', [
            'action'  => site_url('master/securities/' . $security->id . '/delete'),
            'label'   => 'Hapus',
            'message' => 'Hapus ' . $security->code . ' secara permanen?',
        ]);
    }
}
?>

<?= component('page_header', [
    'title'       => $security->displayName(),
    'subtitle'    => $security->is_active ? 'Sekuritas aktif' : 'Sekuritas nonaktif — tidak muncul di form transaksi baru',
    'breadcrumbs' => [
        ['label' => 'Master Data'],
        ['label' => 'Sekuritas', 'url' => site_url('master/securities')],
        ['label' => $security->code],
    ],
    'actions'     => $actions,
]) ?>

<div class="grid gap-3 sm:grid-cols-2 mb-4">
    <?= component('stat', [
        'label' => 'Fee Beli',
        'value' => fmt_number($security->buyFeePercent(), 3) . '%',
        'sub'   => 'All-in, sudah termasuk levy bursa',
    ]) ?>
    <?= component('stat', [
        'label' => 'Fee Jual',
        'value' => fmt_number($security->sellFeePercent(), 3) . '%',
        'sub'   => 'All-in, sudah termasuk PPh final dan levy',
    ]) ?>
</div>

<?php if ($security->notes): ?>
    <div class="mb-4">
        <?= component('card', ['title' => 'Catatan', 'body' => '<p class="text-sm whitespace-pre-line">' . esc($security->notes) . '</p>']) ?>
    </div>
<?php endif; ?>

<?php
$rows = '';

foreach ($accounts as $account) {
    $status = $account->is_active
        ? '<span class="badge badge-success badge-sm">Aktif</span>'
        : '<span class="badge badge-ghost badge-sm">Nonaktif</span>';

    // Nomor rekening penuh hanya dibuka atas tindakan eksplisit pengguna (§36).
    $number = $account->account_number
        ? '<span x-data="{ open: false }">'
            . '<span x-show="!open" class="font-mono">' . esc($account->maskedAccountNumber()) . '</span>'
            . '<span x-show="open" x-cloak class="font-mono">' . esc($account->account_number) . '</span>'
            . '<button type="button" class="btn btn-ghost btn-xs ml-1" @click="open = !open" '
            . ':aria-label="open ? \'Sembunyikan nomor rekening\' : \'Tampilkan nomor rekening\'">'
            . '<span x-text="open ? \'Sembunyikan\' : \'Lihat\'"></span></button>'
            . '</span>'
        : '<span class="text-base-content/40">-</span>';

    $rows .= '<tr class="hover">'
        . '<td class="font-medium">' . esc($account->label) . '</td>'
        . '<td>' . $number . '</td>'
        . '<td>' . esc($account->bank_name ?? '-') . '</td>'
        . '<td>' . esc(fmt_date($account->opened_at?->format('Y-m-d'))) . '</td>'
        . '<td>' . $status . '</td>'
        . '</tr>';
}

$accountsBody = $accounts === []
    ? component('empty_state', ['title' => 'Belum ada rekening', 'description' => 'Tambahkan minimal satu rekening agar sekuritas ini dapat dipakai bertransaksi.'])
    : '<div class="overflow-x-auto"><table class="table table-sm table-zebra">'
        . '<thead><tr><th>Nama</th><th>Nomor</th><th>Bank</th><th>Dibuka</th><th>Status</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
?>

<?= component('card', [
    'title'    => 'Rekening Efek / RDN',
    'subtitle' => 'Entitas inilah yang dirujuk setiap transaksi kas dan transaksi saham.',
    'flush'    => true,
    'body'     => $accountsBody,
]) ?>

<?php if ($canManage): ?>
    <div class="mt-4">
        <form method="post" action="<?= site_url('master/securities/' . $security->id . '/accounts') ?>" class="max-w-2xl">
            <?= csrf_field() ?>
            <?= component('card', [
                'title' => 'Tambah Rekening',
                'body'  => '<div class="grid gap-3 sm:grid-cols-2">'
                    . component('form/input', ['name' => 'label', 'label' => 'Nama Rekening', 'required' => true])
                    . component('form/input', ['name' => 'account_number', 'label' => 'Nomor Rekening / RDN'])
                    . component('form/input', ['name' => 'bank_name', 'label' => 'Bank RDN'])
                    . component('form/input', ['name' => 'opened_at', 'label' => 'Tanggal Dibuka', 'type' => 'date'])
                    . '</div>'
                    . '<button type="submit" class="btn btn-primary btn-sm mt-3">Tambah Rekening</button>',
            ]) ?>
        </form>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
