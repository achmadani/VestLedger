<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Enums\BalanceSide;
use App\Models\AccountModel;
use App\Models\JournalLineModel;
use App\Models\SecuritiesAccountModel;
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
        private SecuritiesAccountModel $securitiesAccounts,
    ) {
    }

    /**
     * Laba Rugi dipecah per rekening sekuritas (§21.6).
     *
     * Angka realisasi diambil dari buku besar, memakai dimensi securities_account_id
     * yang melekat di setiap baris jurnal — jadi rinciannya SELALU berjumlah sama
     * dengan Laba Rugi global untuk rentang yang sama. Baris jurnal nominal yang
     * dimensinya kosong dikumpulkan pada baris "Tanpa sekuritas", bukan dibuang:
     * rincian yang diam-diam tidak menjumlah adalah rincian yang menyesatkan.
     *
     * Unrealized SENGAJA tidak dijumlahkan ke dalam laba: ia tidak pernah masuk
     * buku besar (§13, §14) dan sifatnya potret pada satu tanggal, bukan hasil
     * sepanjang periode. Ia disajikan sebagai kolom terpisah, dinilai pada $to.
     *
     * @return array{from:string, to:string, rows:list<array<string, mixed>>,
     *               totals:array<string, mixed>, has_unattributed:bool}
     */
    public function profitBySecurities(string $from, string $to, ?array $unrealizedByAccount = null): array
    {
        $rows = [];

        foreach ($this->securitiesAccounts->withSecurities() as $account) {
            $rows[$account->id] = $this->emptyProfitRow(
                $account->id,
                trim(((string) ($account->securities_code ?? '')) !== ''
                    ? $account->securities_code . ' — ' . $account->label
                    : $account->label),
                (string) ($account->securities_name ?? ''),
            );
        }

        foreach ($this->lines->nominalBalancesBySecurities($from, $to) as $line) {
            $id = $line['securities_account_id'] === null ? 0 : (int) $line['securities_account_id'];

            $rows[$id] ??= $this->emptyProfitRow(null, 'Tanpa sekuritas', 'Baris jurnal tanpa dimensi rekening');

            $debit  = Money::of((string) $line['total_debit']);
            $credit = Money::of((string) $line['total_credit']);
            $type   = AccountType::from($line['type']);

            // Saldo dihitung menurut sisi normal akunnya, sehingga pembalikan
            // (yang mencatat di sisi berlawanan) otomatis menguranginya.
            $amount = $type === AccountType::Revenue
                ? $credit->subtract($debit)
                : $debit->subtract($credit);

            if ($type === AccountType::Revenue) {
                $rows[$id]['revenue'] = $rows[$id]['revenue']->add($amount);
            } else {
                $rows[$id]['expense'] = $rows[$id]['expense']->add($amount);
            }

            $bucket = match ($line['code']) {
                AccountCode::RealizedGain->value          => 'realized_gain',
                AccountCode::RealizedLoss->value          => 'realized_loss',
                AccountCode::DividendIncome->value        => 'dividend',
                AccountCode::BrokerFee->value             => 'broker_fee',
                AccountCode::TaxAndLevy->value            => 'tax_levy',
                AccountCode::AdministrativeExpense->value => 'admin_expense',
                default                                   => null,
            };

            if ($bucket !== null) {
                $rows[$id][$bucket] = $rows[$id][$bucket]->add($amount);
            }
        }

        foreach ($rows as $id => $row) {
            $rows[$id]['realized_net'] = $row['realized_gain']->subtract($row['realized_loss']);
            $rows[$id]['net_profit']   = $row['revenue']->subtract($row['expense']);
            $rows[$id]['unrealized']   = $unrealizedByAccount[$id] ?? Money::zero();
        }

        // Rekening yang tidak bergerak sepanjang periode dan tidak menyimpan
        // posisi apa pun hanya menambah baris kosong.
        $rows = array_filter(
            $rows,
            static fn (array $row): bool => ! $row['revenue']->isZero()
                || ! $row['expense']->isZero()
                || ! $row['unrealized']->isZero(),
        );

        $unattributed = isset($rows[0]);

        // "Tanpa sekuritas" selalu di bawah: ia bukan rekening, melainkan sisa.
        uasort($rows, static fn (array $a, array $b): int => ($a['securities_account_id'] === null ? 1 : 0)
            <=> ($b['securities_account_id'] === null ? 1 : 0));

        return [
            'from'             => $from,
            'to'               => $to,
            'rows'             => array_values($rows),
            'totals'           => $this->sumProfitRows($rows),
            'has_unattributed' => $unattributed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProfitRow(?int $accountId, string $label, string $securitiesName): array
    {
        return [
            'securities_account_id' => $accountId,
            'label'                 => $label,
            'securities_name'       => $securitiesName,
            'realized_gain'         => Money::zero(),
            'realized_loss'         => Money::zero(),
            'realized_net'          => Money::zero(),
            'dividend'              => Money::zero(),
            'broker_fee'            => Money::zero(),
            'tax_levy'              => Money::zero(),
            'admin_expense'         => Money::zero(),
            'revenue'               => Money::zero(),
            'expense'               => Money::zero(),
            'net_profit'            => Money::zero(),
            'unrealized'            => Money::zero(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, Money>
     */
    private function sumProfitRows(array $rows): array
    {
        $keys   = ['realized_gain', 'realized_loss', 'realized_net', 'dividend', 'broker_fee',
            'tax_levy', 'admin_expense', 'revenue', 'expense', 'net_profit', 'unrealized'];
        $totals = array_fill_keys($keys, Money::zero());

        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $totals[$key] = $totals[$key]->add($row[$key]);
            }
        }

        return $totals;
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
    public function incomeStatement(string $from, string $to, ?int $securitiesAccountId = null): array
    {
        $revenue  = [];
        $expenses = [];

        $totalRevenue = Money::zero();
        $totalExpense = Money::zero();

        foreach ($this->lines->balancesByAccount($from, $to, $securitiesAccountId) as $row) {
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
            'from'                  => $from,
            'to'                    => $to,
            'securities_account_id' => $securitiesAccountId,
            'revenue'               => $revenue,
            'expenses'              => $expenses,
            'total_revenue'         => $totalRevenue,
            'total_expense'         => $totalExpense,
            'net_profit'            => $totalRevenue->subtract($totalExpense),
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
     * Saldo kas dan book value portofolio pada akhir setiap bulan (§31 chart).
     *
     * Dihitung dengan SATU query yang mengelompokkan mutasi per bulan, lalu
     * diakumulasikan di PHP — bukan 12 query terpisah, dan bukan pula 12
     * pemanggilan potret portofolio yang masing-masing menembak database
     * berkali-kali (§34).
     *
     * Yang disajikan adalah NILAI BUKU, bukan market value: menghitung market
     * value tiap akhir bulan memerlukan harga historis tiap saham yang belum
     * tentu diinput, dan diam-diam memakai harga terbaru akan membuat grafik
     * masa lalu berubah setiap kali harga hari ini diperbarui.
     *
     * @return list<array{month:int, label:string, cash:Money, portfolio:Money, total:Money}>
     */
    public function monthlyAssetSeries(int $year): array
    {
        $cashId      = $this->accounts->idFor(AccountCode::Cash);
        $portfolioId = $this->accounts->idFor(AccountCode::StockPortfolio);

        // Saldo yang sudah terbentuk SEBELUM tahun ini menjadi titik awal.
        $opening = db_connect()->query(
            'SELECT jl.account_id, SUM(jl.debit) - SUM(jl.credit) AS balance
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id IN (?, ?) AND je.entry_date < ?
             GROUP BY jl.account_id',
            [$cashId, $portfolioId, $year . '-01-01']
        )->getResultArray();

        $running = [$cashId => Money::zero(), $portfolioId => Money::zero()];

        foreach ($opening as $row) {
            $running[(int) $row['account_id']] = Money::of((string) $row['balance']);
        }

        $movements = db_connect()->query(
            'SELECT MONTH(je.entry_date) AS m, jl.account_id,
                    SUM(jl.debit) - SUM(jl.credit) AS movement
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id IN (?, ?) AND je.entry_date >= ? AND je.entry_date <= ?
             GROUP BY MONTH(je.entry_date), jl.account_id',
            [$cashId, $portfolioId, $year . '-01-01', $year . '-12-31']
        )->getResultArray();

        $byMonth = [];

        foreach ($movements as $row) {
            $byMonth[(int) $row['m']][(int) $row['account_id']] = Money::of((string) $row['movement']);
        }

        $names = [
            1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
            'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
        ];

        $series = [];

        for ($month = 1; $month <= 12; $month++) {
            foreach ([$cashId, $portfolioId] as $accountId) {
                if (isset($byMonth[$month][$accountId])) {
                    $running[$accountId] = $running[$accountId]->add($byMonth[$month][$accountId]);
                }
            }

            $series[] = [
                'month'     => $month,
                'label'     => $names[$month],
                'cash'      => $running[$cashId],
                'portfolio' => $running[$portfolioId],
                'total'     => $running[$cashId]->add($running[$portfolioId]),
            ];
        }

        return $series;
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
