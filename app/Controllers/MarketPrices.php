<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FiltersRequestInput;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketPriceModel;
use App\Models\StockModel;
use App\Models\StockPositionModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Input harga pasar (§14).
 */
class MarketPrices extends BaseController
{
    use FiltersRequestInput;
    use HandlesBusinessRules;

    public function index(): string
    {
        $date   = (string) $this->dateInput('date', date('Y-m-d'));
        $prices = new MarketPriceModel();

        // Saham yang sedang dimiliki didahulukan: itulah yang harganya benar-benar
        // dibutuhkan untuk menghitung market value.
        $heldIds = [];

        foreach ((new StockPositionModel())->held() as $position) {
            $heldIds[$position->stock_id] = true;
        }

        $stocks   = (new StockModel())->active();
        $existing = [];

        foreach ($prices->where('price_date', $date)->findAll() as $price) {
            $existing[$price->stock_id] = $price->closingPrice()->toDecimalString();
        }

        $perPage = config(\Config\Pager::class)->perPage;

        return view('market_prices/index', [
            'pageTitle' => 'Harga Pasar',
            'date'      => $date,
            'stocks'    => $stocks,
            'heldIds'   => $heldIds,
            'existing'  => $existing,
            'history'   => $prices->withStock()
                ->orderBy('market_prices.price_date', 'desc')
                ->orderBy('s.ticker', 'asc')
                ->paginate($perPage),
            'pager'     => $prices->pager,
        ]);
    }

    public function store(): RedirectResponse
    {
        $date   = (string) $this->request->getPost('price_date');
        $prices = (array) ($this->request->getPost('prices') ?? []);

        try {
            $result = service('marketPrices')->recordMany($date, $prices, $this->request->getPost('notes') ?: null);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/market-prices?date=' . urlencode($date));
        }

        return redirect()->to('/market-prices?date=' . urlencode($date))
            ->with('success', sprintf(
                '%d harga disimpan untuk %s%s.',
                $result['saved'],
                $date,
                $result['skipped'] > 0 ? sprintf(' (%d baris dikosongkan, dilewati)', $result['skipped']) : ''
            ));
    }

    public function importForm(): string
    {
        return view('market_prices/import', [
            'pageTitle' => 'Impor Harga Pasar',
            'date'      => (string) $this->dateInput('date', date('Y-m-d')),
            'held'      => count((new StockPositionModel())->held()),
        ]);
    }

    /**
     * Impor harga penutupan dari XLSX ringkasan perdagangan IDX (§14).
     */
    public function import(): RedirectResponse
    {
        $file = $this->request->getFile('prices');
        $date = (string) $this->request->getPost('price_date');
        $back = '/market-prices/import?date=' . urlencode($date);

        if ($file === null || ! $file->isValid()) {
            return redirect()->to($back)
                ->with('error', 'Berkas belum dipilih atau gagal diunggah: '
                    . ($file?->getErrorString() ?? 'tidak ada berkas'));
        }

        if (strtolower($file->getClientExtension()) !== 'xlsx') {
            return redirect()->to($back)
                ->with('error', 'Hanya berkas XLSX yang diterima, sesuai berkas ringkasan perdagangan IDX.');
        }

        $path     = $file->getTempName();
        $heldOnly = $this->request->getPost('scope') !== 'all';

        try {
            $result = service('marketPriceImport')->importFile($path, $date, $heldOnly);
        } catch (BusinessRuleException $e) {
            // Berkas dihapus juga saat impor gagal: ia tidak lagi berguna, dan
            // membiarkannya menumpuk di direktori sementara tidak ada gunanya.
            $this->discard($path);

            return $this->redirectWithRuleError($e, $back);
        }

        $this->discard($path);

        $redirect = redirect()->to('/market-prices?date=' . urlencode($result['date']));

        // Berkas yang terbaca tetapi tidak menghasilkan satu harga pun — hari
        // libur bursa, atau seluruh saham disuspensi — bukan kegagalan, tetapi
        // juga tidak pantas dilaporkan sebagai keberhasilan.
        $redirect->with(
            $result['saved'] + $result['updated'] === 0 ? 'warning' : 'success',
            sprintf(
                'Impor selesai untuk %s: %d harga baru, %d diperbarui%s%s.',
                $result['date'],
                $result['saved'],
                $result['updated'],
                $result['skipped'] > 0 ? sprintf(', %d dilewati', $result['skipped']) : '',
                $result['unknown'] > 0 ? sprintf(', %d di luar daftar', $result['unknown']) : '',
            ),
        );

        $warnings = $result['problems'];

        // Tanggal di dalam berkas berbeda dengan yang dipilih hampir selalu
        // berarti berkas kemarin diunggah hari ini. Harga tetap disimpan pada
        // tanggal pilihan pengguna — tetapi ia harus tahu.
        if ($result['fileDate'] !== null && $result['fileDate'] !== $result['date']) {
            array_unshift($warnings, sprintf(
                'Berkas menyebut tanggal perdagangan %s, sedangkan harga disimpan pada %s.',
                $result['fileDate'],
                $result['date'],
            ));
        }

        if ($warnings !== []) {
            $redirect->with('warning', implode(' ', array_slice($warnings, 0, 5)));
        }

        return $redirect;
    }

    /**
     * Menghapus berkas unggahan segera setelah dibaca.
     *
     * PHP memang membersihkan berkas sementara di akhir request, tetapi
     * penghapusan di sini disengaja dan eksplisit: isinya sudah tidak
     * diperlukan begitu harga tersimpan.
     */
    private function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            service('marketPrices')->delete($id);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/market-prices');
        }

        return redirect()->to('/market-prices')->with('success', 'Data harga dihapus.');
    }
}
