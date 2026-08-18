<?php
/**
 * Peringatan bahwa sebagian posisi belum punya harga pasar.
 *
 * Ditampilkan karena angka market value dan unrealized menjadi TIDAK LENGKAP
 * tanpa harga tersebut. Menyembunyikan fakta ini akan membuat pembaca mengira
 * unrealized posisi itu nol, padahal yang benar adalah belum diketahui.
 *
 * @var int    $count
 * @var string $bookValue
 */
$count     = $count ?? 0;
$bookValue = $bookValue ?? '';
?>
<?php if ($count > 0): ?>
    <div class="alert alert-warning mb-4">
        <?= component('icon', ['name' => 'warning', 'class' => 'w-5 h-5 shrink-0']) ?>
        <div class="text-sm">
            <p class="font-medium"><?= esc($count) ?> posisi belum memiliki harga pasar.</p>
            <p class="text-xs opacity-90 mt-0.5">
                Book value posisi tersebut sebesar <?= esc($bookValue) ?> dinilai apa adanya, dan
                unrealized-nya tidak dihitung — bukan dianggap nol.
                <a href="<?= site_url('market-prices') ?>" class="link">Input harga pasar</a>
                agar angka market value lengkap.
            </p>
        </div>
    </div>
<?php endif; ?>
