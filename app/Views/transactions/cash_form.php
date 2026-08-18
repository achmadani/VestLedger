<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
use App\Enums\CashTransactionType;

/** @var CashTransactionType $type */
$explanation = match ($type) {
    CashTransactionType::TopUp      => 'Top up dicatat sebagai setoran modal (akun 3000), bukan pendapatan. Biaya yang dipotong menjadi beban administrasi.',
    CashTransactionType::Withdrawal => 'Withdrawal dicatat pada akun kontra-ekuitas 3200, bukan beban. Modal disetor tetap mencatat nilai brutonya.',
    CashTransactionType::Transfer   => 'Transfer memindahkan kas antar rekening tanpa menyentuh pendapatan maupun beban. Tanpa biaya, total kas global tidak berubah.',
    CashTransactionType::AdminFee   => 'Biaya administrasi dibebankan ke akun 5100 dan mengurangi kas.',
};
?>

<?= component('page_header', [
    'title'       => $type->label(),
    'subtitle'    => $explanation,
    'breadcrumbs' => [
        ['label' => 'Transaksi', 'url' => site_url('transactions')],
        ['label' => $type->label()],
    ],
]) ?>

<form method="post" action="<?= site_url('transactions/' . $slug) ?>" class="max-w-2xl">
    <?= csrf_field() ?>

    <?php
    $fields = component('form/input', [
        'name'     => 'transaction_date',
        'label'    => 'Tanggal',
        'type'     => 'date',
        'value'    => old('transaction_date', date('Y-m-d')),
        'required' => true,
    ])
    . component('form/select', [
        'name'     => 'securities_account_id',
        'label'    => $type === CashTransactionType::Transfer ? 'Rekening Asal' : 'Rekening Sekuritas',
        'options'  => $accounts,
        'value'    => old('securities_account_id'),
        'required' => true,
        'class'    => 'mt-3',
    ]);

    if ($type->needsCounterpart()) {
        $fields .= component('form/select', [
            'name'     => 'counterpart_account_id',
            'label'    => 'Rekening Tujuan',
            'options'  => $accounts,
            'value'    => old('counterpart_account_id'),
            'required' => true,
            'class'    => 'mt-3',
        ]);
    }

    $fields .= '<div class="grid gap-3 sm:grid-cols-2 mt-3">'
        . component('form/money', [
            'name'     => 'amount',
            'label'    => match ($type) {
                CashTransactionType::TopUp      => 'Nominal Setoran',
                CashTransactionType::Withdrawal => 'Nominal Penarikan',
                CashTransactionType::Transfer   => 'Nominal Transfer',
                CashTransactionType::AdminFee   => 'Nominal Biaya',
            },
            'required' => true,
        ]);

    if ($type !== CashTransactionType::AdminFee) {
        $fields .= component('form/money', [
            'name'  => 'fee',
            'label' => 'Biaya',
            'help'  => 'Opsional. Dibebankan ke akun 5100.',
        ]);
    }

    $fields .= '</div>'
        . component('form/textarea', ['name' => 'notes', 'label' => 'Catatan', 'rows' => 2, 'class' => 'mt-3']);
    ?>

    <?= component('card', ['body' => $fields]) ?>

    <div class="flex items-center gap-2 mt-4">
        <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
        <a href="<?= site_url('transactions') ?>" class="btn btn-ghost btn-sm">Batal</a>
    </div>
</form>
<?= $this->endSection() ?>
