<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Riwayat transaksi gabungan dari tiga tabel transaksi (§22, §32).
 *
 * Spesifikasi (§28) meminta transaksi kas, saham, dan dividen disimpan pada
 * tabel terpisah karena bentuk datanya memang berbeda. Untuk daftar "Semua
 * Transaksi", ketiganya disatukan lewat UNION di sisi database — bukan dengan
 * mengambil seluruh isi tiap tabel lalu menggabungkannya di PHP, yang akan
 * memuat seluruh dataset ke memori (§34).
 */
class TransactionHistoryRepository
{
    /**
     * @param array{from?:string, to?:string, kind?:string, securities_account_id?:int, stock_id?:int, status?:string, q?:string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $perPage, int $page): array
    {
        $db = db_connect();

        [$sql, $params] = $this->buildUnion($filters);

        $countRow = $db->query('SELECT COUNT(*) AS total FROM (' . $sql . ') AS combined', $params)->getRowArray();
        $total    = (int) ($countRow['total'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);

        $rows = $db->query(
            'SELECT * FROM (' . $sql . ') AS combined ORDER BY transaction_date DESC, transaction_number DESC '
            . 'LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        )->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Menyusun UNION beserta parameter bind-nya.
     *
     * Seluruh nilai filter dikirim sebagai parameter terikat, tidak pernah
     * disisipkan langsung ke dalam SQL (§36).
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildUnion(array $filters): array
    {
        $parts  = [];
        $params = [];

        $kind = $filters['kind'] ?? '';

        if ($kind === '' || $kind === 'cash') {
            [$sql, $bind] = $this->cashSelect($filters);
            $parts[]      = $sql;
            $params       = array_merge($params, $bind);
        }

        if ($kind === '' || $kind === 'stock') {
            [$sql, $bind] = $this->stockSelect($filters);
            $parts[]      = $sql;
            $params       = array_merge($params, $bind);
        }

        if ($kind === '' || $kind === 'dividend') {
            [$sql, $bind] = $this->dividendSelect($filters);
            $parts[]      = $sql;
            $params       = array_merge($params, $bind);
        }

        if ($parts === []) {
            // Filter jenis yang tidak dikenali: kembalikan himpunan kosong,
            // bukan seluruh data.
            return ['SELECT NULL AS kind, NULL AS id, NULL AS transaction_number, NULL AS transaction_date,
                     NULL AS type_label, NULL AS securities_code, NULL AS ticker, NULL AS quantity,
                     NULL AS amount, NULL AS status, NULL AS journal_entry_id WHERE 1 = 0', []];
        }

        return [implode(' UNION ALL ', $parts), $params];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function cashSelect(array $filters): array
    {
        $sql = "SELECT 'cash' AS kind, ct.id, ct.transaction_number, ct.transaction_date,
                       ct.type AS type_label, s.code AS securities_code, NULL AS ticker,
                       NULL AS quantity, ct.amount, ct.status, ct.journal_entry_id
                FROM cash_transactions ct
                JOIN securities_accounts sa ON sa.id = ct.securities_account_id
                JOIN securities s ON s.id = sa.securities_id
                WHERE 1 = 1";

        $params = [];

        // Filter ticker tidak berlaku untuk transaksi kas: menyaring berdasarkan
        // saham harus mengeluarkan seluruh transaksi kas, bukan membiarkannya lolos.
        if (! empty($filters['stock_id'])) {
            $sql .= ' AND 1 = 0';
        }

        [$sql, $params] = $this->applyCommonFilters($sql, $params, $filters, 'ct');

        return [$sql, $params];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function stockSelect(array $filters): array
    {
        $sql = "SELECT 'stock' AS kind, st.id, st.transaction_number, st.transaction_date,
                       st.type AS type_label, s.code AS securities_code, sk.ticker,
                       st.quantity, st.net_amount AS amount, st.status, st.journal_entry_id
                FROM stock_transactions st
                JOIN securities_accounts sa ON sa.id = st.securities_account_id
                JOIN securities s ON s.id = sa.securities_id
                JOIN stocks sk ON sk.id = st.stock_id
                WHERE 1 = 1";

        $params = [];

        if (! empty($filters['stock_id'])) {
            $sql .= ' AND st.stock_id = ?';
            $params[] = (int) $filters['stock_id'];
        }

        return $this->applyCommonFilters($sql, $params, $filters, 'st');
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function dividendSelect(array $filters): array
    {
        $sql = "SELECT 'dividend' AS kind, dt.id, dt.transaction_number, dt.transaction_date,
                       'dividend' AS type_label, s.code AS securities_code, sk.ticker,
                       dt.quantity_eligible AS quantity, dt.net_dividend AS amount, dt.status, dt.journal_entry_id
                FROM dividend_transactions dt
                JOIN securities_accounts sa ON sa.id = dt.securities_account_id
                JOIN securities s ON s.id = sa.securities_id
                JOIN stocks sk ON sk.id = dt.stock_id
                WHERE 1 = 1";

        $params = [];

        if (! empty($filters['stock_id'])) {
            $sql .= ' AND dt.stock_id = ?';
            $params[] = (int) $filters['stock_id'];
        }

        return $this->applyCommonFilters($sql, $params, $filters, 'dt');
    }

    /**
     * @param list<mixed> $params
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function applyCommonFilters(string $sql, array $params, array $filters, string $alias): array
    {
        if (! empty($filters['from'])) {
            $sql .= ' AND ' . $alias . '.transaction_date >= ?';
            $params[] = $filters['from'];
        }

        if (! empty($filters['to'])) {
            $sql .= ' AND ' . $alias . '.transaction_date <= ?';
            $params[] = $filters['to'];
        }

        if (! empty($filters['securities_account_id'])) {
            $sql .= ' AND ' . $alias . '.securities_account_id = ?';
            $params[] = (int) $filters['securities_account_id'];
        }

        if (! empty($filters['status'])) {
            $sql .= ' AND ' . $alias . '.status = ?';
            $params[] = $filters['status'];
        }

        if (! empty($filters['q'])) {
            $sql .= ' AND ' . $alias . '.transaction_number LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        return [$sql, $params];
    }
}
