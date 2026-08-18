<?php

declare(strict_types=1);

namespace App\Controllers\Transactions;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesBusinessRules;
use App\Enums\CashTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\SecuritiesAccountModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Form transaksi kas: top up, withdrawal, transfer, biaya administrasi
 * (§16, §17, §18).
 */
class Cash extends BaseController
{
    use HandlesBusinessRules;

    private const SLUGS = [
        'top-up'     => CashTransactionType::TopUp,
        'withdrawal' => CashTransactionType::Withdrawal,
        'transfer'   => CashTransactionType::Transfer,
        'fee'        => CashTransactionType::AdminFee,
    ];

    public function form(string $slug): string|RedirectResponse
    {
        $type = self::SLUGS[$slug] ?? null;

        if ($type === null) {
            return redirect()->to('/transactions')->with('error', 'Jenis transaksi kas tidak dikenali.');
        }

        return view('transactions/cash_form', [
            'pageTitle' => $type->label(),
            'slug'      => $slug,
            'type'      => $type,
            'accounts'  => (new SecuritiesAccountModel())->options(),
        ]);
    }

    public function store(string $slug): RedirectResponse
    {
        $type = self::SLUGS[$slug] ?? null;

        if ($type === null) {
            return redirect()->to('/transactions')->with('error', 'Jenis transaksi kas tidak dikenali.');
        }

        $input = [
            'transaction_date'       => (string) $this->request->getPost('transaction_date'),
            'securities_account_id'  => (int) $this->request->getPost('securities_account_id'),
            'counterpart_account_id' => (int) $this->request->getPost('counterpart_account_id'),
            'amount'                 => $this->request->getPost('amount'),
            'fee'                    => $this->request->getPost('fee'),
            'notes'                  => $this->request->getPost('notes') ?: null,
        ];

        try {
            $transaction = match ($type) {
                CashTransactionType::TopUp      => service('cashTransactions')->topUp($input),
                CashTransactionType::Withdrawal => service('cashTransactions')->withdraw($input),
                CashTransactionType::Transfer   => service('cashTransactions')->transfer($input),
                CashTransactionType::AdminFee   => service('cashTransactions')->adminFee($input),
            };
        } catch (BusinessRuleException $e) {
            return $this->redirectWithRuleError($e, '/transactions/' . $slug);
        }

        return redirect()->to('/transactions')
            ->with('success', sprintf('%s %s berhasil dicatat beserta jurnalnya.', $type->label(), $transaction->transaction_number));
    }
}
