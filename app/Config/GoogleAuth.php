<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Login dengan akun Google (OAuth 2.0 authorization code flow).
 *
 * Kredensial diisi lewat .env, tidak pernah ditulis di dalam kode:
 *
 *   googleauth.clientId     = '....apps.googleusercontent.com'
 *   googleauth.clientSecret = '...'
 *
 * Buat kredensialnya di Google Cloud Console → APIs & Services → Credentials →
 * OAuth client ID (jenis "Web application"), lalu daftarkan Authorized redirect
 * URI yang sama persis dengan nilai redirectUri di bawah.
 */
class GoogleAuth extends BaseConfig
{
    public string $clientId     = '';
    public string $clientSecret = '';

    /**
     * Dikosongkan berarti diturunkan dari baseURL aplikasi.
     */
    public string $redirectUri = '';

    /**
     * Login Google HANYA berhasil untuk email yang sudah terdaftar sebagai
     * pengguna aplikasi.
     *
     * Membuat akun otomatis akan berarti setiap pemilik akun Google di dunia
     * dapat masuk ke data keuangan pribadi — tidak pantas untuk aplikasi ini.
     * Akun baru dibuat lewat halaman Pengguna.
     */
    public bool $allowAutoRegistration = false;

    public string $authorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
    public string $tokenUrl     = 'https://oauth2.googleapis.com/token';
    public string $userInfoUrl  = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function redirectUri(): string
    {
        return $this->redirectUri !== '' ? $this->redirectUri : site_url('auth/google/callback');
    }
}
