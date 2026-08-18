<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\ValueObjects\Money;

/**
 * Laporan investasi (§22): realized gain/loss, dividen, dan broker fee.
 *
 * Berbeda dari laporan keuangan yang bersumber dari buku besar, laporan ini
 * bersumber dari tabel transaksi — karena yang ditanyakan adalah rincian per
 * transaksi (saham apa, berapa lembar, average cost berapa), bukan saldo akun.
 */
class InvestmentReportService
{
    /**
     * Rincian realized gain/loss per transaksi jual (§22).
     *
     * @return array<string, mixed>
     */
    public function realized(string $from, string $to, ?int $stockId = null, ?int $accountId = null): array
    {
        $builder = db_connect()->table('stock_transactions st')
            ->select('st.*, s.code AS securities_code, sa.label AS account_label, sk.ticker, sk.company_name')
            ->join('securities_accounts sa', 'sa.id = st.securities_account_id')
            ->join('securities s', 's.id = sa.securities_id')
            ->join('stocks sk', 'sk.id = st.stock_id')
            ->where('st.type', 'sell')
            ->where('st.status', 'posted')
            ->where('st.transaction_date >=', $from)
            ->where('st.transaction_date <=', $to);

        if ($stockId !== null && $stockId > 0) {
            $builder->where('st.stock_id', $stockId);
        }

        if ($accountId !== null && $accountId > 0) {
            $builder->where('st.securities_account_id', $accountId);
        }

        $rows = $builder->orderBy('st.transaction_date', 'asc')->orderBy('st.id', 'asc')->get()->getResultArray();

        $totalGross    = Money::zero();
        $totalBookSold = Money::zero();
        $totalCharges  = Money::zero();
        $totalGainNet  = Money::zero();

        foreach ($rows as &$row) {
            $gross   = Money::of((string) $row['gross_amount']);
            $book    = Money::of((string) $row['book_value_sold']);
            $charges = Money::of((string) $row['broker_fee'])
                ->add(Money::of((string) $row['tax']))
                ->add(Money::of((string) $row['levy']));

            $row['gross_money']    = $gross;
            $row['book_money']     = $book;
            $row['charges_money']  = $charges;
            $row['gain_gross']     = Money::of((string) $row['realized_gain_gross']);
            $row['gain_net']       = Money::of((string) $row['realized_gain_net']);

            $totalGross    = $totalGross->add($gross);
            $totalBookSold = $totalBookSold->add($book);
            $totalCharges  = $totalCharges->add($charges);
            $totalGainNet  = $totalGainNet->add($row['gain_net']);
        }

        unset($row);

        return [
            'rows'            => $rows,
            'total_gross'     => $totalGross,
            'total_book_sold' => $totalBookSold,
            'total_charges'   => $totalCharges,
            'total_gain_net'  => $totalGainNet,
        ];
    }

    /**
     * Laporan dividen (§22).
     *
     * @return array<string, mixed>
     */
    public function dividends(string $from, string $to): array
    {
        $rows = db_connect()->table('dividend_transactions dt')
            ->select('dt.*, s.code AS securities_code, sk.ticker, sk.company_name')
            ->join('securities_accounts sa', 'sa.id = dt.securities_account_id')
            ->join('securities s', 's.id = sa.securities_id')
            ->join('stocks sk', 'sk.id = dt.stock_id')
            ->where('dt.status', 'posted')
            ->where('dt.transaction_date >=', $from)
            ->where('dt.transaction_date <=', $to)
            ->orderBy('dt.transaction_date', 'asc')
            ->get()->getResultArray();

        $gross = Money::zero();
        $tax   = Money::zero();
        $net   = Money::zero();

        foreach ($rows as $row) {
            $gross = $gross->add(Money::of((string) $row['gross_dividend']));
            $tax   = $tax->add(Money::of((string) $row['tax']));
            $net   = $net->add(Money::of((string) $row['net_dividend']));
        }

        return ['rows' => $rows, 'total_gross' => $gross, 'total_tax' => $tax, 'total_net' => $net];
    }

    /**
     * Laporan broker fee (§22).
     *
     * Fee PEMBELIAN ikut ditampilkan meskipun tidak menjadi beban — ia
     * dikapitalisasi ke book cost. Menyembunyikannya akan membuat total biaya
     * transaksi yang benar-benar dibayar tampak lebih kecil daripada kenyataan.
     *
     * @return array<string, mixed>
     */
    public function brokerFees(string $from, string $to): array
    {
        $rows = db_connect()->table('stock_transactions st')
            ->select("st.transaction_date, st.transaction_number, st.type,
                      s.code AS securities_code, sk.ticker,
                      st.broker_fee, st.tax, st.levy,
                      (st.broker_fee + st.tax + st.levy) AS total_charges")
            ->join('securities_accounts sa', 'sa.id = st.securities_account_id')
            ->join('securities s', 's.id = sa.securities_id')
            ->join('stocks sk', 'sk.id = st.stock_id')
            ->where('st.status', 'posted')
            ->where('st.transaction_date >=', $from)
            ->where('st.transaction_date <=', $to)
            ->where('(st.broker_fee + st.tax + st.levy) >', 0)
            ->orderBy('st.transaction_date', 'asc')
            ->get()->getResultArray();

        $capitalised = Money::zero();  // fee beli -> masuk book cost
        $expensed    = Money::zero();  // fee jual -> menjadi beban

        foreach ($rows as $row) {
            $charges = Money::of((string) $row['total_charges']);

            if ($row['type'] === 'buy') {
                $capitalised = $capitalised->add($charges);

                continue;
            }

            $expensed = $expensed->add($charges);
        }

        return [
            'rows'        => $rows,
            'capitalised' => $capitalised,
            'expensed'    => $expensed,
            'total'       => $capitalised->add($expensed),
        ];
    }
}
