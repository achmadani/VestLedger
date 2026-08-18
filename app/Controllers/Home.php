<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Entry point aplikasi.
 *
 * VestLedger tidak memiliki halaman publik: akar situs selalu mengarahkan ke
 * dashboard (bila sudah login) atau ke halaman login (bila belum).
 */
class Home extends BaseController
{
    public function index(): RedirectResponse
    {
        return auth()->loggedIn()
            ? redirect()->to('/dashboard')
            : redirect()->to('/login');
    }
}
