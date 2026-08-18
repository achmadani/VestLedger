<?php

declare(strict_types=1);

namespace App\Controllers\Accounting;

use App\Controllers\BaseController;
use App\Controllers\Concerns\FiltersRequestInput;
use App\Enums\BalanceSide;
use App\Models\AccountModel;
use App\Models\JournalLineModel;
use App\Models\SecuritiesAccountModel;
use App\Models\StockModel;
use App\ValueObjects\Money;

/**
 * Buku besar dengan filter tanggal, akun, sekuritas, dan ticker (§21.5).
 */
class Ledger extends BaseController
{
    use FiltersRequestInput;

    public function index(): string
    {
        $filters = [
            'account_id'            => $this->idInput('account_id'),
            'securities_account_id' => $this->idInput('securities_account_id'),
            'stock_id'              => $this->idInput('stock_id'),
            'from'                  => $this->dateInput('from', ''),
            'to'                    => $this->dateInput('to', ''),
        ];

        $accounts = new AccountModel();
        $rows     = [];
        $account  = $filters['account_id'] > 0 ? $accounts->find($filters['account_id']) : null;

        // Saldo berjalan hanya bermakna bila dibatasi pada SATU akun; menjumlahkan
        // baris lintas akun yang berbeda saldo normalnya tidak menghasilkan angka apa pun.
        if ($account !== null) {
            $lines   = (new JournalLineModel())->ledgerQuery($filters)->get()->getResultArray();
            $running = Money::zero();
            $normal  = BalanceSide::from($account->normal_balance);

            foreach ($lines as $line) {
                $debit  = Money::of((string) $line['debit']);
                $credit = Money::of((string) $line['credit']);

                $movement = $normal === BalanceSide::Debit
                    ? $debit->subtract($credit)
                    : $credit->subtract($debit);

                $running       = $running->add($movement);
                $line['running'] = $running->toDecimalString();
                $rows[]          = $line;
            }
        }

        return view('accounting/ledger/index', [
            'pageTitle' => 'Buku Besar',
            'account'   => $account,
            'rows'      => $rows,
            'filters'   => $filters,
            'accountOptions'   => $accounts->postableOptions(),
            'securitiesOptions' => (new SecuritiesAccountModel())->options(),
            'stockOptions'      => (new StockModel())->options(),
        ]);
    }
}
