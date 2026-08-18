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
