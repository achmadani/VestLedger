<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?>Masuk<?= $this->endSection() ?>

<?= $this->section('main') ?>
<?php
/** @var array $config */
$authConfig       = config('Auth');
$allowRemembering = $authConfig->sessionConfig['allowRemembering'] ?? false;
$allowMagicLink   = $authConfig->allowMagicLinkLogins ?? false;
?>
<div class="card bg-base-100 shadow-lg border border-base-300">
    <div class="card-body">
        <h1 class="text-lg font-semibold">Masuk ke akun Anda</h1>
        <p class="text-xs text-base-content/60 -mt-1 mb-2">
            Gunakan email dan kata sandi yang terdaftar.
        </p>

        <?php if (session('error') !== null): ?>
            <div class="alert alert-error text-sm" role="alert"><?= esc(session('error')) ?></div>
        <?php elseif (session('errors') !== null): ?>
            <div class="alert alert-error text-sm" role="alert">
                <ul class="list-disc list-inside">
                    <?php foreach ((array) session('errors') as $error): ?>
                        <li><?= esc($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (session('message') !== null): ?>
            <div class="alert alert-success text-sm" role="alert"><?= esc(session('message')) ?></div>
        <?php endif; ?>

        <form action="<?= url_to('login') ?>" method="post" class="space-y-3 mt-2">
            <?= csrf_field() ?>

            <?= component('form/input', [
                'name'     => 'email',
                'label'    => 'Email',
                'type'     => 'email',
                'value'    => old('email'),
                'required' => true,
                'attrs'    => ['autocomplete' => 'email', 'inputmode' => 'email', 'autofocus' => 'autofocus'],
            ]) ?>

            <?= component('form/input', [
                'name'     => 'password',
                'label'    => 'Kata Sandi',
                'type'     => 'password',
                'required' => true,
                'attrs'    => ['autocomplete' => 'current-password'],
            ]) ?>

            <?php if ($allowRemembering): ?>
                <label class="label cursor-pointer justify-start gap-2 py-0">
                    <input type="checkbox" name="remember" class="checkbox checkbox-sm"
                           <?= old('remember') ? 'checked' : '' ?>>
                    <span class="label-text text-sm">Ingat saya di perangkat ini</span>
                </label>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary w-full">Masuk</button>
        </form>

        <?php if ($allowMagicLink): ?>
            <div class="text-center mt-2">
                <a href="<?= url_to('magic-link') ?>" class="link link-hover text-xs">
                    Lupa kata sandi? Kirim tautan masuk ke email
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
