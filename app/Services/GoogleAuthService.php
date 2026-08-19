<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Libraries\HttpClient;
use App\Services\Accounting\AuditLogger;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\GoogleAuth;

/**
 * Login dengan akun Google, memakai authorization code flow.
 *
 * Tidak ada dependency tambahan dan tidak ada pemeriksaan tanda tangan JWT:
 * kode otorisasi ditukar langsung ke server Google lewat koneksi TLS dari
 * server ini, dan identitas pengguna diambil dari endpoint userinfo memakai
 * access token hasil penukaran itu. Kanalnya sendiri yang menjamin keaslian
 * jawaban, sehingga pustaka JWT tidak diperlukan.
 *
 * Yang TIDAK dilakukan: implicit flow maupun mempercayai id_token yang dikirim
 * browser — keduanya memindahkan kepercayaan ke pihak yang dapat dimanipulasi.
 */
class GoogleAuthService
{
    private const STATE_KEY = 'google_oauth_state';

    public function __construct(
        private GoogleAuth $config,
        private UserModel $users,
        private AuditLogger $audit,
        private ?HttpClient $http = null,
    ) {
        // Tidak memakai service('curlrequest'): hosting produksi memblokir
        // curl_exec, dan CodeIgniter tidak punya jalur keluar lain.
        $this->http ??= new HttpClient(15);
    }

    public function isEnabled(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * URL tujuan untuk memulai login, lengkap dengan state anti-CSRF.
     */
    public function authorizationUrl(): string
    {
        $this->assertEnabled();

        // State mengikat balasan Google ke sesi yang memulainya. Tanpa ini,
        // penyerang dapat memancing korban menyelesaikan alur login memakai
        // kode milik penyerang.
        $state = bin2hex(random_bytes(16));
        session()->set(self::STATE_KEY, $state);

        return $this->config->authorizeUrl . '?' . http_build_query([
            'client_id'     => $this->config->clientId,
            'redirect_uri'  => $this->config->redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Menyelesaikan alur login dan mengembalikan pengguna yang cocok.
     *
     * @throws BusinessRuleException bila state tidak cocok, penukaran kode
     *                               gagal, email belum terverifikasi, atau
     *                               email tidak terdaftar
     */
    public function completeLogin(?string $code, ?string $state): User
    {
        $this->assertEnabled();

        $expected = session()->get(self::STATE_KEY);
        session()->remove(self::STATE_KEY);

        if ($expected === null || $state === null || ! hash_equals((string) $expected, $state)) {
            throw new BusinessRuleException(
                'Proses login Google tidak dapat diverifikasi. Silakan ulangi dari halaman login.'
            );
        }

        if ($code === null || $code === '') {
            throw new BusinessRuleException('Login Google dibatalkan.');
        }

        $profile = $this->fetchProfile($code);
        $email   = strtolower(trim((string) ($profile['email'] ?? '')));

        if ($email === '') {
            throw new BusinessRuleException('Akun Google tidak memberikan alamat email.');
        }

        if (($profile['email_verified'] ?? false) !== true) {
            throw new BusinessRuleException(
                'Alamat email akun Google tersebut belum diverifikasi oleh Google.'
            );
        }

        $user = $this->users->findByCredentials(['email' => $email]);

        if ($user === null) {
            // Pesannya sengaja tidak menyebut apakah email itu ada atau tidak
            // di sistem, agar halaman login tidak menjadi alat pemetaan akun.
            $this->audit->record(
                'login_rejected',
                'user',
                null,
                'Login Google ditolak: email tidak terdaftar',
            );

            throw new BusinessRuleException(
                'Akun Google tersebut tidak dapat dipakai masuk ke aplikasi ini. '
                . 'Hubungi pemilik aplikasi untuk didaftarkan.'
            );
        }

        if ($user->isBanned()) {
            throw new BusinessRuleException('Akun Anda sedang dinonaktifkan.');
        }

        return $user;
    }

    /**
     * Menukar authorization code menjadi profil pengguna.
     *
     * @return array<string, mixed>
     */
    private function fetchProfile(string $code): array
    {
        try {
            $tokenResponse = $this->http->postForm($this->config->tokenUrl, [
                'code'          => $code,
                'client_id'     => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'redirect_uri'  => $this->config->redirectUri(),
                'grant_type'    => 'authorization_code',
            ]);
        } catch (\Throwable $e) {
            throw new BusinessRuleException($this->connectionMessage($e));
        }

        $token = json_decode($tokenResponse['body'], true);

        if (! is_array($token) || empty($token['access_token'])) {
            // Isi balasan sengaja TIDAK ikut ditampilkan maupun dicatat:
            // di dalamnya dapat terkandung potongan kredensial.
            throw new BusinessRuleException('Penukaran kode login dengan Google gagal.');
        }

        try {
            $userResponse = $this->http->get($this->config->userInfoUrl, [
                'Authorization' => 'Bearer ' . $token['access_token'],
            ]);
        } catch (\Throwable $e) {
            throw new BusinessRuleException($this->connectionMessage($e));
        }

        $profile = json_decode($userResponse['body'], true);

        if (! is_array($profile)) {
            throw new BusinessRuleException('Profil dari Google tidak dapat dibaca.');
        }

        return $profile;
    }

    /**
     * Pesan kegagalan koneksi yang menyebut sebabnya.
     *
     * Server produksi tidak punya shell maupun akses log yang nyaman, sehingga
     * pesan inilah satu-satunya alat diagnosis yang dimiliki pengguna. Bila
     * seluruh jalur keluar tertutup, yang perlu diketahui bukan "gagal
     * menghubungi", melainkan jalur mana yang diblokir hosting.
     */
    private function connectionMessage(\Throwable $e): string
    {
        $blocked = array_keys(array_filter(
            $this->http->availability(),
            static fn (bool $usable): bool => ! $usable,
        ));

        $message = 'Tidak dapat menghubungi server Google.';

        if ($blocked !== []) {
            $message .= ' Jalur keluar yang diblokir hosting: ' . implode(', ', $blocked) . '.';
        }

        return $message . ' (' . $e->getMessage() . ')';
    }

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new BusinessRuleException(
                'Login dengan Google belum dikonfigurasi. Isi googleauth.clientId dan '
                . 'googleauth.clientSecret di berkas .env.'
            );
        }
    }
}
