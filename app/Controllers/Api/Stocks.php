<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\StockModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Titik akhir pencarian saham untuk kotak ketik-cari pada form transaksi.
 *
 * Ini satu-satunya endpoint JSON di aplikasi, dan hanya melayani pencarian
 * master data — bukan data keuangan. Ia tetap berada di balik filter session
 * dan permission seperti halaman lain.
 */
class Stocks extends BaseController
{
    public function search(): ResponseInterface
    {
        $query = trim((string) $this->request->getGet('q'));

        // Pencarian satu huruf mengembalikan hampir seluruh daftar tanpa
        // membantu siapa pun; tunggu sampai dua karakter.
        if (mb_strlen($query) < 2) {
            return $this->response->setJSON([]);
        }

        return $this->response->setJSON((new StockModel())->search($query));
    }
}
