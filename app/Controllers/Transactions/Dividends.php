<?php

declare(strict_types=1);

namespace App\Controllers\Transactions;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\Models\StockPositionModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Form pencatatan dividen (§15).
 */
class Dividends extends BaseController
{
    use HandlesBusinessRules;

    public function form(): string
    {
        $positions = [];

        foreach ((new StockPositionModel())->held() as $position) {
            $positions[$position->securities_account_id . ':' . $position->stock_id] = $position->quantity;
        }

        return view('transactions/dividend_form', [
            'pageTitle' => 'Dividen',
            'accounts'  => (new SecuritiesAccountModel())->options(),
            'stocks'    => (new StockModel())->options(),
            'positions' => $positions,
        ]);
    }

    public function store(): RedirectResponse
    {
        try {
            $transaction = service('dividendTransactions')->record([
                'transaction_date'      => (string) $this->request->getPost('transaction_date'),
                'securities_account_id' => (int) $this->request->getPost('securities_account_id'),
                'stock_id'              => (int) $this->request->getPost('stock_id'),
                'quantity_eligible'     => (int) $this->request->getPost('quantity_eligible'),
                'dividend_per_share'    => $this->request->getPost('dividend_per_share'),
                'tax'                   => $this->request->getPost('tax'),
                'notes'                 => $this->request->getPost('notes') ?: null,
            ]);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/transactions/dividend');
        }

        return redirect()->to('/transactions')
            ->with('success', sprintf('Dividen %s berhasil dicatat beserta jurnalnya.', $transaction->transaction_number));
    }
}
