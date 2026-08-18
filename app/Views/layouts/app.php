<?php
/**
 * Layout utama aplikasi (halaman yang membutuhkan login).
 *
 * Struktur: DaisyUI drawer — sidebar permanen di layar >= lg, dan menjadi
 * drawer geser pada tablet/mobile (§2 responsive).
 *
 * @var string|null $pageTitle
 */
$appName      = 'VestLedger';
$defaultTheme = investment_config()->defaultTheme;
?>
<!doctype html>
<html lang="id" data-theme="<?= esc($defaultTheme, 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($pageTitle ?? 'Dashboard') ?> &middot; <?= esc($appName) ?></title>

    <?php /* Terapkan tema sebelum render agar tidak ada kedipan tema (FOUC). */ ?>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('vestledger-theme');
                if (t) { document.documentElement.setAttribute('data-theme', t); }
            } catch (e) {}
        })();
    </script>

    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
    <?= $this->renderSection('pageStyles') ?>
    <script defer src="<?= asset_url('assets/js/alpine.min.js') ?>"></script>
</head>
<body class="min-h-screen bg-base-200/50">

<div class="drawer lg:drawer-open">
    <input id="vl-drawer" type="checkbox" class="drawer-toggle">

    <div class="drawer-content flex flex-col min-h-screen">
        <?= view('components/navbar', ['pageTitle' => $pageTitle ?? ''], ['saveData' => false]) ?>

        <main class="flex-1 p-4 sm:p-6 max-w-[1600px] w-full mx-auto">
            <?= component('flash') ?>
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="px-4 sm:px-6 py-4 text-xs text-base-content/40 no-print">
            <?= esc($appName) ?> &middot; Investment Portfolio &amp; Accounting Management System
        </footer>
    </div>

    <div class="drawer-side z-40">
        <label for="vl-drawer" aria-label="Tutup menu" class="drawer-overlay"></label>
        <?= component('sidebar') ?>
    </div>
</div>

<?= $this->renderSection('pageScripts') ?>
</body>
</html>
