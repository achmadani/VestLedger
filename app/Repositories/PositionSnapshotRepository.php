<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\AccountCode;
use App\Models\AccountModel;
use App\ValueObjects\Money;

/**
 * Posisi saham pada SEBUAH TANGGAL, bukan posisi hari ini.
 *
 * Tabel `stock_positions` hanya menyimpan keadaan terkini; ia dipakai saat
 * transaksi berjalan karena cepat. Untuk pelaporan historis — neraca per akhir
 * bulan, laporan bulanan, perbandingan antarperiode — keadaan terkini jelas
 * tidak cukup.
 *
 * Book value diambil dari BUKU BESAR (dimensi pada akun 1100), sehingga angka
 * portofolio historis tidak akan pernah bertentangan dengan neraca. Quantity
 * diambil dari transaksi, karena buku besar mencatat nilai, bukan jumlah lembar.
 */
class PositionSnapshotRepository
{
    public function __construct(private AccountModel $accounts)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function asOf(string $date): array
    {
        $db          = db_connect();
        $portfolioId = $this->accounts->idFor(AccountCode::StockPortfolio);

        $bookValues = [];

        $rows = $db->query(
            'SELECT jl.securities_account_id, jl.stock_id,
                    SUM(jl.debit) - SUM(jl.credit) AS book_value
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.entry_date <= ?
               AND jl.securities_account_id IS NOT NULL AND jl.stock_id IS NOT NULL
             GROUP BY jl.securities_account_id, jl.stock_id',
            [$portfolioId, $date]
        )->getResultArray();

        foreach ($rows as $row) {
            $bookValues[$row['securities_account_id'] . ':' . $row['stock_id']] = (string) $row['book_value'];
        }

        // Quantity berasal dari DUA sumber: saldo awal dan transaksi.
        //
        // Menghitung dari transaksi saja akan membuat posisi yang berasal dari
        // saldo awal hilang sama sekali dari seluruh laporan historis, meskipun
        // book value-nya tercatat rapi di buku besar.
        //
        // Transaksi yang sudah dibatalkan berstatus 'reversed' dan otomatis
        // tidak terhitung — sejalan dengan jurnal pembaliknya yang meniadakan
        // book value. Saldo awal yang dihapus barisnya ikut terhapus.
        $quantities = $db->query(
            "SELECT securities_account_id, stock_id, SUM(quantity) AS quantity
             FROM (
                 SELECT st.securities_account_id, st.stock_id,
                        CASE WHEN st.type = 'buy' THEN st.quantity ELSE -st.quantity END AS quantity
                 FROM stock_transactions st
                 WHERE st.status = 'posted' AND st.transaction_date <= ?

                 UNION ALL

                 SELECT ob.securities_account_id, ob.stock_id, ob.quantity
                 FROM opening_balances ob
                 WHERE ob.kind = 'stock' AND ob.as_of_date <= ?
                   AND ob.securities_account_id IS NOT NULL AND ob.stock_id IS NOT NULL
             ) movements
             GROUP BY securities_account_id, stock_id
             HAVING quantity <> 0",
            [$date, $date]
        )->getResultArray();

        if ($quantities === []) {
            return [];
        }

        // Metadata sekuritas & saham, satu query untuk semuanya.
        $meta = [];

        foreach ($db->query(
            'SELECT sa.id AS account_id, s.code AS securities_code, s.name AS securities_name, sa.label AS account_label
             FROM securities_accounts sa JOIN securities s ON s.id = sa.securities_id'
        )->getResultArray() as $row) {
            $meta['account'][(int) $row['account_id']] = $row;
        }

        foreach ($db->query('SELECT id, ticker, company_name, sector FROM stocks')->getResultArray() as $row) {
            $meta['stock'][(int) $row['id']] = $row;
        }

        $positions = [];

        foreach ($quantities as $row) {
            $accountId = (int) $row['securities_account_id'];
            $stockId   = (int) $row['stock_id'];
            $key       = $accountId . ':' . $stockId;

            $positions[] = [
                'securities_account_id' => $accountId,
                'securities_code'       => $meta['account'][$accountId]['securities_code'] ?? '',
                'securities_name'       => $meta['account'][$accountId]['securities_name'] ?? '',
                'account_label'         => $meta['account'][$accountId]['account_label'] ?? '',
                'stock_id'              => $stockId,
                'ticker'                => $meta['stock'][$stockId]['ticker'] ?? '',
                'company_name'          => $meta['stock'][$stockId]['company_name'] ?? '',
                'sector'                => $meta['stock'][$stockId]['sector'] ?? null,
                'quantity'              => (int) $row['quantity'],
                'book_value'            => Money::of($bookValues[$key] ?? '0'),
            ];
        }

        usort($positions, static fn (array $a, array $b): int => [$a['ticker'], $a['securities_code']] <=> [$b['ticker'], $b['securities_code']]);

        return $positions;
    }
}
