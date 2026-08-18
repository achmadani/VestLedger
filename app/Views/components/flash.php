<?php
/**
 * Menampilkan flash message dari session.
 *
 * Mendukung key bawaan Shield (error/errors/message) dan key aplikasi
 * (success/warning/info) sehingga satu komponen cukup untuk seluruh aplikasi.
 */
$map = [
    'success' => ['alert-success', 'check'],
    'message' => ['alert-success', 'check'],
    'info'    => ['alert-info', 'info'],
    'warning' => ['alert-warning', 'warning'],
    'error'   => ['alert-error', 'error'],
];

$messages = [];

foreach ($map as $key => [$class, $icon]) {
    $value = session()->getFlashdata($key);

    if ($value === null || $value === '' || $value === []) {
        continue;
    }

    foreach ((array) $value as $text) {
        $messages[] = [$class, $icon, (string) $text];
    }
}

// Shield mengirim daftar error validasi lewat key 'errors'
foreach ((array) (session()->getFlashdata('errors') ?? []) as $text) {
    $messages[] = ['alert-error', 'error', (string) $text];
}
?>
<?php if ($messages !== []): ?>
    <div class="space-y-2 mb-4" role="status" aria-live="polite">
        <?php foreach ($messages as [$class, $icon, $text]): ?>
            <div x-data="{ show: true }" x-show="show" x-cloak
                 class="alert <?= esc($class, 'attr') ?> shadow-sm">
                <?= component('icon', ['name' => $icon, 'class' => 'w-5 h-5 shrink-0']) ?>
                <span class="text-sm"><?= esc($text) ?></span>
                <button type="button" class="btn btn-ghost btn-xs" @click="show = false"
                        aria-label="Tutup notifikasi">&times;</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
