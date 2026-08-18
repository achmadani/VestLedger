<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\TransactionHistoryRepository;

/**
 * Dashboard portofolio (§20, §31).
 *
 * Controller tetap tipis (§29): ia hanya meminta potret portofolio dari service
 * layer dan beberapa transaksi terakhir, lalu menyerahkannya ke view.
 */
class Dashboard extends BaseController
{
    public function index(): string
    {
        $snapshot = service('portfolio')->snapshot();

        // Sepuluh transaksi terbaru, diambil lewat query berpaginasi —
        // bukan dengan mengambil seluruh riwayat lalu memotongnya di PHP (§34).
        $recent = (new TransactionHistoryRepository())->paginate([], 10, 1);

        // Top holdings menurut market value; posisi tanpa harga memakai book value
        // sebagai penggantinya, dan hal itu ditandai di tampilan.
        $holdings = $snapshot['by_ticker'];

        usort($holdings, static function (array $a, array $b): int {
            $left  = $a['has_price'] ? $a['market_value']->minor() : $a['book_value']->minor();
            $right = $b['has_price'] ? $b['market_value']->minor() : $b['book_value']->minor();

            return $right <=> $left;
        });

        return view('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'snapshot'  => $snapshot,
            'recent'    => $recent['rows'],
            'holdings'  => array_slice($holdings, 0, 5),
        ]);
    }
}
