<?php

declare(strict_types=1);

namespace App\Controllers\Transactions;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Enums\StockTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\Models\StockPositionModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Form beli & jual saham (§33).
 */
class Stocks extends BaseController
{
    use HandlesBusinessRules;

    public function buyForm(): string
    {
        return view('transactions/stock_form', [
            'pageTitle' => 'Beli Saham',
            'type'      => StockTransactionType::Buy,
            'accounts'  => (new SecuritiesAccountModel())->optionsByUsage(),
            'positions' => $this->positionMap(),
            'cash'      => service('portfolio')->cashBalances(),
            'feeRates'  => $this->feeRates(),
        ]);
    }

    public function sellForm(): string
    {
        return view('transactions/stock_form', [
            'pageTitle' => 'Jual Saham',
            'type'      => StockTransactionType::Sell,
            'accounts'  => (new SecuritiesAccountModel())->optionsByUsage(),
            'positions' => $this->positionMap(),
            'cash'      => service('portfolio')->cashBalances(),
            'feeRates'  => $this->feeRates(),
        ]);
    }

    public function store(string $slug): RedirectResponse
    {
        $type = $slug === 'buy' ? StockTransactionType::Buy : ($slug === 'sell' ? StockTransactionType::Sell : null);

        if ($type === null) {
            return redirect()->to('/transactions')->with('error', 'Jenis transaksi saham tidak dikenali.');
        }

        $input = [
            'transaction_date'      => (string) $this->request->getPost('transaction_date'),
            'securities_account_id' => (int) $this->request->getPost('securities_account_id'),
            'stock_id'              => (int) $this->request->getPost('stock_id'),
            'quantity'              => (int) $this->request->getPost('quantity'),
            'price'                 => $this->request->getPost('price'),
            'broker_fee'            => $this->request->getPost('broker_fee'),
            'tax'                   => $this->request->getPost('tax'),
            'levy'                  => $this->request->getPost('levy'),
            'notes'                 => $this->request->getPost('notes') ?: null,
        ];

        try {
            $transaction = $type === StockTransactionType::Buy
                ? service('stockTransactions')->buy($input)
                : service('stockTransactions')->sell($input);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/transactions/' . $slug);
        }

        $redirect = redirect()->to('/transactions')
            ->with('success', sprintf('%s %s berhasil dicatat beserta jurnalnya.', $type->label(), $transaction->transaction_number));

        return $this->warnIfCashWentNegative($redirect, $transaction->securities_account_id);
    }

    /**
     * Memperingatkan bila transaksi membuat saldo kas rekening menjadi negatif.
     *
     * Transaksi TIDAK dibatalkan. Aplikasi ini dipakai untuk pencatatan dan
     * transaksi kerap dimasukkan mundur, sehingga saldo bisa tampak negatif
     * hanya karena top up-nya belum sempat dicatat.
     */
    private function warnIfCashWentNegative(RedirectResponse $redirect, int $accountId): RedirectResponse
    {
        $balances = service('portfolio')->cashBalances();
        $balance  = \App\ValueObjects\Money::of($balances[$accountId] ?? '0');

        if (! $balance->isNegative()) {
            return $redirect;
        }

        return $redirect->with('warning', sprintf(
            'Saldo kas rekening ini sekarang %s. Transaksi tetap tercatat — '
            . 'periksa apakah ada top up atau penjualan yang belum dimasukkan.',
            fmt_rupiah($balance->toFloat())
        ));
    }

    /**
     * Tarif biaya tiap rekening, agar form dapat mengisi biayanya sendiri.
     *
     * @return array<int, array{buy:float, sell:float}>
     */
    private function feeRates(): array
    {
        $rows = db_connect()->query(
            'SELECT sa.id, s.buy_fee_percent, s.sell_fee_percent
             FROM securities_accounts sa JOIN securities s ON s.id = sa.securities_id
             WHERE sa.is_active = 1 AND sa.deleted_at IS NULL'
        )->getResultArray();

        $rates = [];

        foreach ($rows as $row) {
            $rates[(int) $row['id']] = [
                'buy'  => (float) $row['buy_fee_percent'],
                'sell' => (float) $row['sell_fee_percent'],
            ];
        }

        return $rates;
    }

    /**
     * Posisi saat ini per (rekening, saham), untuk preview form jual (§33).
     *
     * Dikirim sebagai data ke Alpine sehingga preview average cost dan estimasi
     * realized gain dapat dihitung tanpa request tambahan ke server.
     *
     * @return array<string, array{quantity:int, book_value:string, average_cost:string}>
     */
    private function positionMap(): array
    {
        $map = [];

        foreach ((new StockPositionModel())->held() as $position) {
            $map[$position->securities_account_id . ':' . $position->stock_id] = [
                'quantity'     => $position->quantity,
                'book_value'   => $position->bookValue()->toDecimalString(),
                'average_cost' => $position->averageCost()->toDecimalString(),
                'ticker'       => $position->ticker,
                'company_name' => $position->company_name,
            ];
        }

        return $map;
    }
}
