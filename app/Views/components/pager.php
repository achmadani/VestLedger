<?php
/**
 * Template Pager CodeIgniter 4 bergaya DaisyUI.
 *
 * Didaftarkan di app/Config/Pager.php sehingga $pager->links() di seluruh
 * aplikasi otomatis memakai tampilan ini.
 *
 * @var CodeIgniter\Pager\PagerRenderer $pager
 */
$pager->setSurroundCount(2);
?>
<nav class="flex justify-center no-print" aria-label="Navigasi halaman">
    <div class="join">
        <?php if ($pager->hasPrevious()): ?>
            <a href="<?= $pager->getFirst() ?>" class="join-item btn btn-sm" aria-label="Halaman pertama">&laquo;</a>
            <a href="<?= $pager->getPrevious() ?>" class="join-item btn btn-sm">Sebelumnya</a>
        <?php endif; ?>

        <?php foreach ($pager->links() as $link): ?>
            <a href="<?= $link['uri'] ?>"
               class="join-item btn btn-sm <?= $link['active'] ? 'btn-active btn-primary' : '' ?>"
               <?= $link['active'] ? 'aria-current="page"' : '' ?>>
                <?= esc($link['title']) ?>
            </a>
        <?php endforeach; ?>

        <?php if ($pager->hasNext()): ?>
            <a href="<?= $pager->getNext() ?>" class="join-item btn btn-sm">Berikutnya</a>
            <a href="<?= $pager->getLast() ?>" class="join-item btn btn-sm" aria-label="Halaman terakhir">&raquo;</a>
        <?php endif; ?>
    </div>
</nav>
