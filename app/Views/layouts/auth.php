<?php
/**
 * Layout untuk halaman tamu (login, reset password, magic link).
 *
 * Nama section mengikuti kontrak view bawaan Shield (title, pageStyles, main,
 * pageScripts) supaya seluruh halaman Shield yang belum di-override tetap
 * ikut memakai tampilan VestLedger.
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
    <title><?= $this->renderSection('title') ?> &middot; <?= esc($appName) ?></title>
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
<body class="min-h-screen bg-base-200 flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <div class="flex items-center justify-center gap-2 mb-6">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-primary text-primary-content font-bold">VL</span>
        <div>
            <p class="font-semibold leading-tight"><?= esc($appName) ?></p>
            <p class="text-[11px] text-base-content/60 leading-tight">Portfolio &amp; Accounting</p>
        </div>
    </div>

    <?= $this->renderSection('main') ?>

    <p class="text-center text-[11px] text-base-content/40 mt-6">
        Data keuangan pribadi. Jangan bagikan kredensial Anda kepada siapa pun.
    </p>
    <p class="text-center text-[11px] text-base-content/30 mt-1">
        <?= esc(\Config\Version::full()) ?>
    </p>
</div>

<?= $this->renderSection('pageScripts') ?>
</body>
</html>
