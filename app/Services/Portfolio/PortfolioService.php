<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\AccountCode;
use App\Models\AccountModel;
use App\Models\JournalLineModel;
use App\Models\MarketPriceModel;
use App\Models\SecuritiesAccountModel;
use App\Models\StockPositionModel;
use App\Repositories\PositionSnapshotRepository;
use App\ValueObjects\Money;
use App\ValueObjects\Price;

/**
 * Perakit seluruh angka portofolio (§13, §14, §20, §22).
 *
 * Prinsip yang dipegang di sini:
 *
 *  1. **Unrealized gain/loss tidak pernah dijurnal.** Ia dihitung di sini murni
 *     untuk pelaporan, dan tidak masuk laba rugi periode berjalan (§13, §40.2).
 *
 *  2. **Posisi tanpa harga pasar tidak dianggap bernilai sama dengan book value.**
 *     Menyamakannya berarti mengklaim unrealized-nya nol, padahal yang benar
 *     adalah "belum diketahui". Posisi seperti itu dilaporkan terpisah agar
 *     pembaca tahu bagian mana dari portofolio yang belum dinilai ulang.
 *
 *  3. **Kas diambil dari buku besar**, bukan dari penjumlahan transaksi —
 *     ledger adalah source of truth (§28).
 */
class PortfolioService
{
    public function __construct(
        private StockPositionModel $positions,
        private MarketPriceModel $prices,
        private JournalLineModel $lines,
        private AccountModel $accounts,
        private SecuritiesAccountModel $securitiesAccounts,
        private PositionSnapshotRepository $snapshots,
    ) {
    }

    /**
     * Potret portofolio pada sebuah tanggal.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');

        $priceMap  = $this->prices->latestPrices($asOf);
        $positions = $this->buildPositions($priceMap, $asOf);
        $cash      = $this->cashByAccount($asOf);

        return [
            'as_of'         => $asOf,
            'positions'     => $positions,
            'by_securities' => $this->groupBySecurities($positions, $cash),
            'by_ticker'     => $this->groupByTicker($positions),
            'totals'        => $this->totals($positions, $cash, $asOf),
        ];
    }

    /**
     * Saldo kas per rekening, untuk dipakai form transaksi.
     *
     * @return array<int, string> [securities_account_id => saldo desimal]
     */
    public function cashBalances(?string $asOf = null): array
    {
        $balances = [];

        foreach ($this->cashByAccount($asOf ?? date('Y-m-d')) as $accountId => $money) {
            $balances[$accountId] = $money->toDecimalString();
        }

        return $balances;
    }

    /**
     * Rekening yang saldo kasnya negatif.
     *
     * Saldo kas negatif TIDAK diblokir sistem: aplikasi ini dipakai untuk
     * pencatatan, dan transaksi kerap dimasukkan mundur (backdate) sehingga
     * urutan pemasukan data tidak selalu sama dengan urutan kejadiannya.
     * Yang dilakukan sistem adalah menandainya secara mencolok, karena saldo
     * RDN yang benar-benar negatif hampir selalu berarti ada transaksi yang
     * belum dicatat.
     *
     * @return list<array{securities_account_id:int, securities_code:string, account_label:string, balance:\App\ValueObjects\Money}>
     */
    public function negativeCashAccounts(?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $cash   = $this->cashByAccount($asOf);
        $result = [];

        foreach ($this->securitiesAccounts->withSecurities() as $account) {
            $balance = $cash[$account->id] ?? Money::zero();

            if (! $balance->isNegative()) {
                continue;
            }

            $result[] = [
                'securities_account_id' => $account->id,
                'securities_code'       => (string) ($account->securities_code ?? ''),
                'account_label'         => $account->label,
                'balance'               => $balance,
            ];
        }

        return $result;
    }

    /**
     * Baris posisi lengkap dengan market value dan unrealized gain/loss.
     *
     * @param array<int, array{price:string, date:string}> $priceMap
     *
     * @return list<array<string, mixed>>
     */
    private function buildPositions(array $priceMap, string $asOf): array
    {
        $rows = [];

        // Posisi diturunkan dari buku besar pada tanggal laporan, BUKAN dibaca
        // dari tabel stock_positions yang hanya menyimpan keadaan terkini.
        // Tanpa ini, laporan per tanggal lampau akan menampilkan posisi hari ini
        // yang dinilai dengan harga tanggal itu — angka yang tidak pernah ada.
        foreach ($this->snapshots->asOf($asOf) as $position) {
            $bookValue   = $position['book_value'];
            $quantity    = $position['quantity'];
            $averageCost = Price::averageOf($bookValue, $quantity);
            $priced      = isset($priceMap[$position['stock_id']]);

            $marketPrice = $priced ? Price::of($priceMap[$position['stock_id']]['price']) : null;
            $marketValue = $marketPrice?->multiplyByQuantity($quantity);
            $unrealized  = $marketValue?->subtract($bookValue);

            $rows[] = [
                'securities_account_id' => $position['securities_account_id'],
                'securities_code'       => $position['securities_code'],
                'securities_name'       => $position['securities_name'],
                'account_label'         => $position['account_label'],
                'stock_id'              => $position['stock_id'],
                'ticker'                => $position['ticker'],
                'company_name'          => $position['company_name'],
                'sector'                => $position['sector'],
                'quantity'              => $quantity,
                'book_value'            => $bookValue,
                'average_cost'          => $averageCost,
                'has_price'             => $priced,
                'market_price'          => $marketPrice,
                'price_date'            => $priced ? $priceMap[$position['stock_id']]['date'] : null,
                'market_value'          => $marketValue,
                'unrealized'            => $unrealized,
                'return_pct'            => $this->returnPercent($bookValue, $unrealized),
            ];
        }

        return $rows;
    }

    /**
     * Return persen terhadap book value.
     *
     * Book value nol berarti tidak ada dasar pembanding — mengembalikan null,
     * bukan nol atau tak hingga.
     */
    private function returnPercent(Money $bookValue, ?Money $unrealized): ?float
    {
        if ($unrealized === null || $bookValue->isZero()) {
            return null;
        }

        return $unrealized->toFloat() / $bookValue->toFloat() * 100;
    }

    /**
     * Saldo kas per rekening sekuritas, diambil dari buku besar.
     *
     * @return array<int, Money>
     */
    private function cashByAccount(string $asOf): array
    {
        $raw      = $this->lines->cashBalanceByAccount($this->accounts->idFor(AccountCode::Cash), $asOf);
        $balances = [];

        foreach ($raw as $accountId => $amount) {
            $balances[$accountId] = Money::of($amount);
        }

        return $balances;
    }

    /**
     * Ringkasan per sekuritas (§20).
     *
     * Rekening yang punya kas tetapi belum punya posisi tetap ditampilkan —
     * kalau tidak, dana yang mengendap di sana akan tak terlihat.
     *
     * @param list<array<string, mixed>> $positions
     * @param array<int, Money>          $cash
     *
     * @return list<array<string, mixed>>
     */
    private function groupBySecurities(array $positions, array $cash): array
    {
        $grouped = [];

        foreach ($this->securitiesAccounts->withSecurities() as $account) {
            $grouped[$account->id] = [
                'securities_account_id' => $account->id,
                // Kolom hasil join dibaca lewat magic getter entity; properti
                // $attributes bersifat protected dan tidak dapat diakses dari luar.
                'securities_code'       => (string) ($account->securities_code ?? ''),
                'securities_name'       => (string) ($account->securities_name ?? ''),
                'account_label'         => $account->label,
                'cash'                  => $cash[$account->id] ?? Money::zero(),
                'holdings'              => 0,
                'book_value'            => Money::zero(),
                'market_value'          => Money::zero(),
                'unpriced_book_value'   => Money::zero(),
                'unrealized'            => Money::zero(),
            ];
        }

        foreach ($positions as $row) {
            $id = $row['securities_account_id'];

            if (! isset($grouped[$id])) {
                continue;
            }

            $grouped[$id]['holdings']++;
            $grouped[$id]['book_value'] = $grouped[$id]['book_value']->add($row['book_value']);

            if ($row['has_price']) {
                $grouped[$id]['market_value'] = $grouped[$id]['market_value']->add($row['market_value']);
                $grouped[$id]['unrealized']   = $grouped[$id]['unrealized']->add($row['unrealized']);

                continue;
            }

            $grouped[$id]['unpriced_book_value'] = $grouped[$id]['unpriced_book_value']->add($row['book_value']);
        }

        // Net worth per sekuritas: posisi tanpa harga dinilai pada book value-nya,
        // dan fakta itu ditandai lewat unpriced_book_value.
        foreach ($grouped as $id => $row) {
            $grouped[$id]['net_worth'] = $row['cash']
                ->add($row['market_value'])
                ->add($row['unpriced_book_value']);
        }

        return array_values($grouped);
    }

    /**
     * Total kepemilikan per ticker lintas seluruh sekuritas (§5, §22).
     *
     * @param list<array<string, mixed>> $positions
     *
     * @return list<array<string, mixed>>
     */
    private function groupByTicker(array $positions): array
    {
        $grouped = [];

        foreach ($positions as $row) {
            $key = $row['stock_id'];

            $grouped[$key] ??= [
                'stock_id'            => $row['stock_id'],
                'ticker'              => $row['ticker'],
                'company_name'        => $row['company_name'],
                'sector'              => $row['sector'],
                'quantity'            => 0,
                'book_value'          => Money::zero(),
                'market_value'        => Money::zero(),
                'unpriced_book_value' => Money::zero(),
                'unrealized'          => Money::zero(),
                'has_price'           => $row['has_price'],
                'market_price'        => $row['market_price'],
                'price_date'          => $row['price_date'],
                'breakdown'           => [],
            ];

            $grouped[$key]['quantity'] += $row['quantity'];
            $grouped[$key]['book_value'] = $grouped[$key]['book_value']->add($row['book_value']);

            if ($row['has_price']) {
                $grouped[$key]['market_value'] = $grouped[$key]['market_value']->add($row['market_value']);
                $grouped[$key]['unrealized']   = $grouped[$key]['unrealized']->add($row['unrealized']);
            } else {
                $grouped[$key]['unpriced_book_value'] = $grouped[$key]['unpriced_book_value']->add($row['book_value']);
            }

            // Rincian per sekuritas, persis seperti contoh §5.
            $grouped[$key]['breakdown'][] = [
                'securities_code' => $row['securities_code'],
                'account_label'   => $row['account_label'],
                'quantity'        => $row['quantity'],
                'book_value'      => $row['book_value'],
                'average_cost'    => $row['average_cost'],
            ];
        }

        // Average cost GLOBAL per ticker: book value gabungan dibagi quantity
        // gabungan. Angka ini untuk pelaporan saja — book cost antar sekuritas
        // tidak pernah dicampur dalam pencatatan akuntansi (§5).
        foreach ($grouped as $key => $row) {
            $grouped[$key]['average_cost'] = Price::averageOf($row['book_value'], $row['quantity']);
            $grouped[$key]['return_pct']   = $this->returnPercent($row['book_value'], $row['has_price'] ? $row['unrealized'] : null);
        }

        return array_values($grouped);
    }

    /**
     * Ringkasan global (§20).
     *
     * @param list<array<string, mixed>> $positions
     * @param array<int, Money>          $cash
     *
     * @return array<string, mixed>
     */
    private function totals(array $positions, array $cash, string $asOf): array
    {
        $totalCash = array_reduce(
            $cash,
            static fn (Money $carry, Money $amount): Money => $carry->add($amount),
            Money::zero()
        );

        $bookValue   = Money::zero();
        $marketValue = Money::zero();
        $unpriced    = Money::zero();
        $unrealized  = Money::zero();
        $unpricedCount = 0;

        foreach ($positions as $row) {
            $bookValue = $bookValue->add($row['book_value']);

            if ($row['has_price']) {
                $marketValue = $marketValue->add($row['market_value']);
                $unrealized  = $unrealized->add($row['unrealized']);

                continue;
            }

            $unpriced = $unpriced->add($row['book_value']);
            $unpricedCount++;
        }

        $ledger = $this->ledgerTotals($asOf);

        // Laba/rugi periode berjalan TIDAK memasukkan unrealized (§13, §40.2).
        $netProfit = $ledger['realized_gain']
            ->add($ledger['dividend_income'])
            ->subtract($ledger['realized_loss'])
            ->subtract($ledger['broker_fee'])
            ->subtract($ledger['admin_expense'])
            ->subtract($ledger['tax_levy']);

        return [
            'cash'                => $totalCash,
            'book_value'          => $bookValue,
            'market_value'        => $marketValue,
            'unpriced_book_value' => $unpriced,
            'unpriced_count'      => $unpricedCount,
            'unrealized'          => $unrealized,
            'unrealized_pct'      => $this->returnPercent($bookValue->subtract($unpriced), $unrealized),
            'net_worth'           => $totalCash->add($marketValue)->add($unpriced),
            'net_profit'          => $netProfit,
            'position_count'      => count($positions),
            'negative_cash'       => $this->negativeCashAccounts($asOf),
        ] + $ledger;
    }

    /**
     * Saldo akun-akun hasil usaha, diambil dari buku besar.
     *
     * @return array<string, Money>
     */
    private function ledgerTotals(string $asOf): array
    {
        $balances = [];

        foreach ($this->lines->balancesByAccount(null, $asOf) as $row) {
            $debit  = Money::of((string) $row['total_debit']);
            $credit = Money::of((string) $row['total_credit']);

            $balances[$row['code']] = $row['normal_balance'] === 'debit'
                ? $debit->subtract($credit)
                : $credit->subtract($debit);
        }

        $get = static fn (AccountCode $code): Money => $balances[$code->value] ?? Money::zero();

        return [
            'realized_gain'   => $get(AccountCode::RealizedGain),
            'realized_loss'   => $get(AccountCode::RealizedLoss),
            'realized_net'    => $get(AccountCode::RealizedGain)->subtract($get(AccountCode::RealizedLoss)),
            'dividend_income' => $get(AccountCode::DividendIncome),
            'broker_fee'      => $get(AccountCode::BrokerFee),
            'admin_expense'   => $get(AccountCode::AdministrativeExpense),
            'tax_levy'        => $get(AccountCode::TaxAndLevy),
            'paid_in_capital' => $get(AccountCode::PaidInCapital),
            'withdrawal'      => $get(AccountCode::OwnerWithdrawal),
        ];
    }
}
