<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Dashboard portofolio (§20, §31).
 *
 * Controller sengaja tipis (§29): tugasnya hanya mengambil data dari service
 * layer dan menyerahkannya ke view. Pada Phase 1 belum ada transaction/portfolio
 * engine, sehingga angka ringkasan masih berupa state kosong yang jujur —
 * BUKAN angka contoh yang dikarang, agar tidak ada yang salah membacanya
 * sebagai data nyata.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        return view('dashboard/index', [
            'pageTitle' => 'Dashboard',
        ]);
    }
}
