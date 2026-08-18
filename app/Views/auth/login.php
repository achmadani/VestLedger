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

        <?php if (service('googleAuth')->isEnabled()): ?>
            <div class="divider text-xs text-base-content/50 my-3">atau</div>

            <a href="<?= site_url('auth/google') ?>" class="btn btn-outline w-full gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.4-.18-2.05H12v3.88h5.38a4.6 4.6 0 0 1-2 3.02v2.5h3.24c1.89-1.74 2.98-4.3 2.98-7.35z"/>
                    <path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.42l-3.24-2.5c-.9.6-2.05.96-3.38.96-2.6 0-4.8-1.75-5.59-4.1H3.06v2.58A10 10 0 0 0 12 22z"/>
                    <path fill="#FBBC05" d="M6.41 13.94a6 6 0 0 1 0-3.83V7.53H3.06a10 10 0 0 0 0 8.97l3.35-2.56z"/>
                    <path fill="#EA4335" d="M12 5.98c1.47 0 2.79.5 3.83 1.5l2.87-2.87C16.96 2.99 14.7 2 12 2a10 10 0 0 0-8.94 5.53l3.35 2.58C7.2 7.73 9.4 5.98 12 5.98z"/>
                </svg>
                Masuk dengan Google
            </a>

            <p class="text-[11px] text-base-content/50 text-center mt-2">
                Hanya alamat email yang sudah terdaftar sebagai pengguna yang dapat masuk.
            </p>
        <?php endif; ?>

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
