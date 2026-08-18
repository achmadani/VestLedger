<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Login dengan akun Google.
 */
class Google extends BaseController
{
    public function redirectToProvider(): RedirectResponse
    {
        if (auth()->loggedIn()) {
            return redirect()->to('/dashboard');
        }

        try {
            return redirect()->to(service('googleAuth')->authorizationUrl());
        } catch (BusinessRuleException $e) {
            return redirect()->to('/login')->with('error', $e->getMessage());
        }
    }

    public function callback(): RedirectResponse
    {
        // Google mengirim error di query string bila pengguna menolak izin.
        if ($this->request->getGet('error') !== null) {
            return redirect()->to('/login')->with('error', 'Login Google dibatalkan.');
        }

        try {
            $user = service('googleAuth')->completeLogin(
                $this->request->getGet('code'),
                $this->request->getGet('state'),
            );
        } catch (BusinessRuleException $e) {
            return redirect()->to('/login')->with('error', $e->getMessage());
        }

        // Sesi dibuat lewat Shield sendiri, sehingga seluruh aturan sesi,
        // remember-me, dan pencatatan login tetap berlaku sama seperti login
        // memakai kata sandi.
        auth()->login($user);

        service('auditLogger')->record(
            'login',
            'user',
            $user->id,
            'Masuk menggunakan akun Google',
        );

        return redirect()->to(config('Auth')->loginRedirect());
    }
}
