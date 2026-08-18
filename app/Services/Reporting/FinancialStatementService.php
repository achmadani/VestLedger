<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Enums\BalanceSide;
use App\Models\AccountModel;
use App\Models\JournalLineModel;
use App\ValueObjects\Money;

/**
 * Laporan keuangan (§21).
 *
 * Seluruhnya dihitung dari journal_lines — ledger adalah source of truth (§28).
 * Tidak satu pun angka di sini berasal dari tabel transaksi atau tabel posisi,
 * sehingga laporan keuangan tidak akan pernah bertentangan dengan buku besar.
 */
class FinancialStatementService
{
    public function __construct(
        private JournalLineModel $lines,
        private AccountModel $accounts,
    ) {
    }

    /**
     * Neraca Saldo (§21.4).
     *
     * @return array{rows: list<array<string, mixed>>, total_debit: Money, total_credit: Money, balanced: bool}
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $rows        = [];
        $totalDebit  = Money::zero();
        $totalCredit = Money::zero();

        foreach ($this->lines->balancesByAccount($from, $to) as $row) {
            $debit  = Money::of((string) $row['total_debit']);
            $credit = Money::of((string) $row['total_credit']);
            $normal = BalanceSide::from($row['normal_balance']);

            // Saldo disajikan pada sisi normalnya. Akun bersaldo normal debit
            // yang kebetulan bersaldo kredit tetap tampil di kolom kredit,
            // supaya penyimpangan seperti itu terlihat alih-alih tersamar.
            $net = $debit->subtract($credit);

            $rows[] = [
                'code'           => $row['code'],
                'name'           => $row['name'],
                'type'           => AccountType::from($row['type']),
                'normal_balance' => $normal,
                'total_debit'    => $debit,
                'total_credit'   => $credit,
                'balance_debit'  => $net->isPositive() ? $net : Money::zero(),
                'balance_credit' => $net->isNegative() ? $net->abs() : Money::zero(),
            ];

            $totalDebit  = $totalDebit->add($net->isPositive() ? $net : Money::zero());
            $totalCredit = $totalCredit->add($net->isNegative() ? $net->abs() : Money::zero());
        }

        return [
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced'     => $totalDebit->equals($totalCredit),
        ];
    }

    /**
     * Laba Rugi (§21.2).
     *
     * Unrealized gain/loss TIDAK pernah muncul di sini — ia tidak dijurnal
     * sama sekali (§13, §40.2).
     *
     * @return array<string, mixed>
     */
    public function incomeStatement(string $from, string $to): array
    {
        $revenue  = [];
        $expenses = [];

        $totalRevenue = Money::zero();
        $totalExpense = Money::zero();

        foreach ($this->lines->balancesByAccount($from, $to) as $row) {
            $type = AccountType::from($row['type']);

            if ($type->isReal()) {
                continue;
            }

            $debit  = Money::of((string) $row['total_debit']);
            $credit = Money::of((string) $row['total_credit']);

            if ($type === AccountType::Revenue) {
                $amount = $credit->subtract($debit);

                if ($amount->isZero()) {
                    continue;
                }

                $revenue[]    = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];
                $totalRevenue = $totalRevenue->add($amount);

                continue;
            }

            $amount = $debit->subtract($credit);

            if ($amount->isZero()) {
                continue;
            }

            $expenses[]   = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];
            $totalExpense = $totalExpense->add($amount);
        }

        return [
            'from'          => $from,
            'to'            => $to,
            'revenue'       => $revenue,
            'expenses'      => $expenses,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_profit'    => $totalRevenue->subtract($totalExpense),
        ];
    }

    /**
     * Neraca (§21.1).
     *
     * Laba/rugi berjalan disajikan sebagai baris ekuitas tersendiri, karena
     * akun nominal belum ditutup ke laba ditahan. Dengan begitu persamaan
     * Aset = Kewajiban + Ekuitas selalu terpenuhi tanpa perlu jurnal penutup.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(string $asOf): array
    {
        $assets      = [];
        $liabilities = [];
        $equity      = [];

        $totalAssets      = Money::zero();
        $totalLiabilities = Money::zero();
        $totalEquity      = Money::zero();
        $profitOrLoss     = Money::zero();

        foreach ($this->lines->balancesByAccount(null, $asOf) as $row) {
            $type   = AccountType::from($row['type']);
            $debit  = Money::of((string) $row['total_debit']);
            $credit = Money::of((string) $row['total_credit']);

            if ($type->isNominal()) {
                // Pendapatan menambah laba, beban menguranginya.
                $profitOrLoss = $type === AccountType::Revenue
                    ? $profitOrLoss->add($credit->subtract($debit))
                    : $profitOrLoss->subtract($debit->subtract($credit));

                continue;
            }

            $amount = $type === AccountType::Asset
                ? $debit->subtract($credit)
                : $credit->subtract($debit);

            if ($amount->isZero()) {
                continue;
            }

            $entry = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];

            match ($type) {
                AccountType::Asset => [$assets[] = $entry, $totalAssets = $totalAssets->add($amount)],
                AccountType::Liability => [$liabilities[] = $entry, $totalLiabilities = $totalLiabilities->add($amount)],
                default => [$equity[] = $entry, $totalEquity = $totalEquity->add($amount)],
            };
        }

        $totalEquityWithProfit = $totalEquity->add($profitOrLoss);
        $totalRight            = $totalLiabilities->add($totalEquityWithProfit);

        return [
            'as_of'                   => $asOf,
            'assets'                  => $assets,
            'liabilities'             => $liabilities,
            'equity'                  => $equity,
            'profit_or_loss'          => $profitOrLoss,
            'total_assets'            => $totalAssets,
            'total_liabilities'       => $totalLiabilities,
            'total_equity'            => $totalEquityWithProfit,
            'total_liabilities_equity' => $totalRight,
            'balanced'                => $totalAssets->equals($totalRight),
        ];
    }

    /**
     * Arus Kas (§21.3), metode langsung.
     *
     * Setiap jurnal yang menyentuh akun kas diklasifikasikan berdasarkan akun
     * LAWANNYA dalam jurnal yang sama:
     *
     *   - menyentuh 1100 Portofolio Saham    -> investasi
     *   - menyentuh 3000 / 3200 (ekuitas)    -> pendanaan
     *   - selainnya                          -> operasi
     *
     * Transfer antar sekuritas tidak muncul: kedua sisinya adalah akun kas yang
     * sama sehingga saling meniadakan — persis seperti seharusnya, karena tidak
     * ada uang yang benar-benar masuk atau keluar (§18).
     *
     * @return array<string, mixed>
     */
    public function cashFlow(string $from, string $to): array
    {
        $cashAccountId = $this->accounts->idFor(AccountCode::Cash);
        $db            = db_connect();

        $beginning = $this->cashBalanceBefore($cashAccountId, $from);

        // Pergerakan kas diagregasi lebih dulu PER JURNAL di subquery, baru
        // digabungkan dengan daftar akun lawannya.
        //
        // Menggabungkan keduanya dalam satu JOIN akan menggandakan baris kas
        // sebanyak jumlah baris lawan pada jurnal yang sama, sehingga nilainya
        // ikut terkali — kesalahan yang tidak terlihat pada jurnal dua baris
        // dan baru muncul pada jurnal penjualan yang berbaris banyak.
        $rows = $db->query(
            'SELECT je.id AS entry_id, je.entry_date, je.description, cash.movement AS cash_movement,
                    (SELECT GROUP_CONCAT(DISTINCT a.code ORDER BY a.code)
                     FROM journal_lines ol
                     JOIN accounts a ON a.id = ol.account_id
                     WHERE ol.journal_entry_id = je.id AND ol.account_id <> ?) AS counterparts
             FROM (
                 SELECT journal_entry_id, SUM(debit) - SUM(credit) AS movement
                 FROM journal_lines
                 WHERE account_id = ?
                 GROUP BY journal_entry_id
             ) cash
             JOIN journal_entries je ON je.id = cash.journal_entry_id
             WHERE je.entry_date >= ? AND je.entry_date <= ?
             ORDER BY je.entry_date ASC, je.id ASC',
            [$cashAccountId, $cashAccountId, $from, $to]
        )->getResultArray();

        $sections = [
            'operating' => ['label' => 'Aktivitas Operasi', 'items' => [], 'total' => Money::zero()],
            'investing' => ['label' => 'Aktivitas Investasi', 'items' => [], 'total' => Money::zero()],
            'financing' => ['label' => 'Aktivitas Pendanaan', 'items' => [], 'total' => Money::zero()],
        ];

        foreach ($rows as $row) {
            $movement = Money::of((string) $row['cash_movement']);

            // Transfer internal: tidak ada akun lawan selain kas, dan nilainya
            // saling meniadakan.
            if ($movement->isZero()) {
                continue;
            }

            $counterparts = array_filter(explode(',', (string) ($row['counterparts'] ?? '')));
            $section      = $this->classify($counterparts);

            $sections[$section]['items'][] = [
                'date'        => $row['entry_date'],
                'description' => $row['description'],
                'amount'      => $movement,
            ];
            $sections[$section]['total'] = $sections[$section]['total']->add($movement);
        }

        $netChange = $sections['operating']['total']
            ->add($sections['investing']['total'])
            ->add($sections['financing']['total']);

        return [
            'from'      => $from,
            'to'        => $to,
            'beginning' => $beginning,
            'sections'  => $sections,
            'net_change' => $netChange,
            'ending'    => $beginning->add($netChange),
        ];
    }

    /**
     * @param list<string> $counterparts kode akun lawan
     */
    private function classify(array $counterparts): string
    {
        if (in_array(AccountCode::StockPortfolio->value, $counterparts, true)) {
            return 'investing';
        }

        foreach ([AccountCode::PaidInCapital->value, AccountCode::OwnerWithdrawal->value] as $code) {
            if (in_array($code, $counterparts, true)) {
                return 'financing';
            }
        }

        return 'operating';
    }

    private function cashBalanceBefore(int $cashAccountId, string $date): Money
    {
        $row = db_connect()->query(
            'SELECT SUM(jl.debit) - SUM(jl.credit) AS balance
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.entry_date < ?',
            [$cashAccountId, $date]
        )->getRowArray();

        return Money::of((string) ($row['balance'] ?? '0'));
    }
}
