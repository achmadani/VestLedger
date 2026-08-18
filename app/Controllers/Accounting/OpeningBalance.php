<?php

declare(strict_types=1);

namespace App\Controllers\Accounting;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Saldo awal (§19).
 */
class OpeningBalance extends BaseController
{
    use HandlesBusinessRules;

    public function index(): string
    {
        return view('accounting/opening_balance/index', [
            'pageTitle' => 'Saldo Awal',
            'current'   => service('openingBalance')->current(),
            'accounts'  => (new SecuritiesAccountModel())->options(),
            'stocks'    => (new StockModel())->options(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $positions = [];

        foreach ((array) ($this->request->getPost('positions') ?? []) as $row) {
            if ((int) ($row['quantity'] ?? 0) <= 0) {
                continue;
            }

            $positions[] = [
                'securities_account_id' => (int) ($row['securities_account_id'] ?? 0),
                'stock_id'              => (int) ($row['stock_id'] ?? 0),
                'quantity'              => (int) ($row['quantity'] ?? 0),
                'book_value'            => $row['book_value'] ?? 0,
            ];
        }

        try {
            service('openingBalance')->save([
                'as_of_date'      => (string) $this->request->getPost('as_of_date'),
                'cash'            => (array) ($this->request->getPost('cash') ?? []),
                'positions'       => $positions,
                'paid_in_capital' => $this->request->getPost('paid_in_capital'),
            ]);
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/accounting/opening-balance');
        }

        return redirect()->to('/accounting/opening-balance')
            ->with('success', 'Saldo awal tersimpan beserta jurnalnya. Neraca sudah balance sejak tanggal tersebut.');
    }

    public function reset(): RedirectResponse
    {
        try {
            service('openingBalance')->reset();
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/accounting/opening-balance');
        }

        return redirect()->to('/accounting/opening-balance')
            ->with('success', 'Saldo awal dihapus lewat jurnal pembalik. Jurnal aslinya tetap tersimpan.');
    }
}
