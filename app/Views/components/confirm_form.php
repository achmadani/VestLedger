<?php
/**
 * Tombol yang mengirim POST setelah konfirmasi (§2, §30).
 *
 * Dipakai untuk aksi berbahaya (void/reversal transaksi, tutup periode).
 * Selalu POST + CSRF token — tidak pernah GET — agar aksi tidak bisa dipicu
 * lewat prefetch atau link yang dibagikan (§36).
 *
 * @var string $action  URL tujuan
 * @var string $label   teks tombol
 * @var string $message pertanyaan konfirmasi
 * @var string $class   kelas tombol
 * @var array  $fields  hidden field tambahan
 */
$action  = $action ?? '#';
$label   = $label ?? 'Kirim';
$message = $message ?? 'Yakin ingin melanjutkan?';
$class   = $class ?? 'btn btn-sm btn-error';
$fields  = $fields ?? [];
?>
<form method="post" action="<?= esc($action, 'attr') ?>" class="inline"
      x-data
      @submit="if (! window.confirm('<?= esc($message, 'js') ?>')) $event.preventDefault()">
    <?= csrf_field() ?>
    <?php foreach ($fields as $name => $value): ?>
        <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc((string) $value, 'attr') ?>">
    <?php endforeach; ?>
    <button type="submit" class="<?= esc($class, 'attr') ?>"><?= esc($label) ?></button>
</form>
