# Aturan Akuntansi VestLedger

Dokumen ini adalah **sumber kebenaran** untuk seluruh perlakuan akuntansi.
Setiap service pada Phase 3–8 harus mengikuti dokumen ini, bukan sebaliknya.

## Prinsip dasar

1. **Ledger adalah source of truth.** Seluruh laporan keuangan dihitung dari
   `journal_entries` + `journal_lines`. Tabel posisi portofolio adalah *calculated
   state* yang dapat dibangun ulang dari transaksi.
2. **Setiap transaksi keuangan menghasilkan jurnal yang balance.** Jurnal
   di-generate → divalidasi (Σ debit = Σ kredit) → baru di-commit, seluruhnya
   dalam satu database transaction.
3. **Tidak ada hard delete** untuk transaksi yang sudah posted. Koreksi memakai
   *void*, *reversal*, atau *correction entry*.
4. **Realized dan unrealized tidak pernah dicampur.** Unrealized gain/loss tidak
   pernah masuk laba rugi periode berjalan dan tidak pernah dijurnal.

## Presisi angka

| Besaran | Tipe | Alasan |
|---|---|---|
| Nilai uang (amount, book value, jurnal) | `DECIMAL(20,2)` | Presisi cukup agar pemecahan book value saat jual sebagian tidak menimbulkan selisih neraca |
| Harga per lembar | `DECIMAL(20,4)` | Mengakomodasi harga pecahan hasil corporate action |
| Quantity saham | `BIGINT` (lembar) | Lembar adalah unit utama akuntansi & portofolio (§7) |
| Average cost | **tidak disimpan** | Selalu diturunkan `book_value / quantity` |

> **Average cost sengaja tidak disimpan.** Jika average cost dibulatkan lalu
> dikalikan ulang saat penjualan, sisa book value akan mengambang beberapa rupiah
> dan neraca berhenti balance. Yang disimpan adalah `quantity` dan `book_value`;
> average cost adalah nilai turunan untuk tampilan.

Konsekuensinya, **book value yang dilepas saat jual sebagian** dihitung sebagai
proporsi, bukan `qty × avg_cost`:

```
book_value_sold = round(book_value_current × qty_sold / qty_current, 2)
```

dan pada penjualan **seluruh** posisi, `book_value_sold` = seluruh sisa
`book_value` — sehingga posisi selalu tuntas di angka nol, tanpa residu.

## Chart of Accounts

| Kode | Nama | Tipe | Catatan |
|---|---|---|---|
| 1000 | Cash / Bank / RDN | Asset | Dimensi `securities_account_id` membedakan kas per sekuritas |
| 1100 | Stock Portfolio | Asset | Book value saham, dimensi `securities_account_id` + `stock_id` |
| 3000 | Paid-in Capital | Equity | Akumulasi **bruto** seluruh top up |
| 3100 | Retained Earnings | Equity | |
| 3200 | Owner Withdrawal | Contra-Equity | Akumulasi **bruto** seluruh withdrawal |
| 4000 | Realized Gain | Revenue | |
| 4001 | Realized Loss | Expense | Lawan dari 4000 bila transaksi rugi |
| 4100 | Dividend Income | Revenue | |
| 5000 | Broker Fee | Expense | Fee jual + fee administrasi (fee beli **tidak** ke sini) |
| 5100 | Administrative Expense | Expense | |
| 5200 | Tax / Levy | Expense | Pajak & levy sisi jual serta pajak dividen |

Kas **tidak** dipecah menjadi akun terpisah per sekuritas. Sebagai gantinya setiap
baris jurnal membawa dimensi `securities_account_id` (dan `stock_id` bila relevan),
sehingga buku besar bisa difilter per sekuritas dan per ticker (§21.5) tanpa
membuat CoA membengkak setiap kali ada sekuritas baru.

## Keputusan yang diambil atas ambiguitas spesifikasi

Tiga hal berikut tidak ditentukan secara tunggal oleh spesifikasi dan telah
dikonfirmasi oleh pemilik sistem.

### 1. Fee & tax pada transaksi JUAL dicatat sebagai beban terpisah

Spesifikasi §11 Step 3 dan contoh jurnalnya tidak konsisten: jika `Realized G/L`
sudah dikurangi fee (Step 3) tetapi fee juga didebit sebagai beban (contoh jurnal),
maka jurnal selisih sebesar fee dan tidak balance. Pajak/levy juga tidak memiliki
baris jurnal sama sekali di contoh tersebut.

**Yang dipakai:**

```
Dr Cash (1000)              net proceeds = gross − fee − tax
Dr Broker Fee (5000)        fee jual
Dr Tax / Levy (5200)        pajak & levy jual
    Cr Stock Portfolio (1100)   book value sold
    Cr Realized Gain (4000)     gross − book value sold     ← bila untung
```

Bila `gross − book value sold` negatif, sisi kredit 4000 diganti debit
`Realized Loss (4001)` sebesar nilai absolutnya.

Bukti balance: `(gross − fee − tax) + fee + tax = gross`, dan
`book value sold + (gross − book value sold) = gross`. ✅

**Realized G/L versi laporan** (§11 Step 3) tetap disajikan apa adanya:

```
Realized G/L (net) = gross − book value sold − fee − tax
```

Angka ini adalah metrik pelaporan, bukan saldo akun. Laba bersih identik pada
kedua cara pandang; bedanya, dengan pendekatan ini biaya transaksi jual muncul
sebagai baris beban tersendiri di Laba Rugi (§21.2) dan tidak tersembunyi di
dalam angka gain.

### 2. Withdrawal memakai akun kontra-equity 3200

```
Dr Owner Withdrawal (3200)
    Cr Cash (1000)
```

Withdrawal **bukan beban** (§40.4). Dengan akun kontra-equity, saldo 3000 tetap
mencatat total modal masuk bruto dan 3200 mencatat total penarikan bruto —
keduanya diminta terpisah oleh Laporan Tahunan (§24). Ekuitas bersih
= 3000 − 3200 + 3100 + laba periode berjalan.

### 3. Seluruh biaya pembelian dikapitalisasi ke book cost

```
book cost = (qty × harga) + broker fee + tax + levy
```

§10 hanya menyebut broker fee, namun levy/pajak sisi beli berkarakter sama:
biaya perolehan. Mengkapitalisasi semuanya membuat perlakuan biaya beli seragam,
dan biaya tersebut otomatis terserap ke realized gain/loss saat saham dijual.

Konsekuensi: akun **Broker Fee (5000) tidak pernah terisi dari transaksi beli**.
Isinya hanya fee jual dan biaya administrasi.

## Ringkasan jurnal per jenis transaksi

| Transaksi | Debit | Kredit |
|---|---|---|
| Top Up | 1000 Cash | 3000 Paid-in Capital |
| Withdrawal | 3200 Owner Withdrawal | 1000 Cash |
| Transfer antar sekuritas | 1000 Cash (tujuan) | 1000 Cash (asal) |
| Beli | 1100 Stock Portfolio (termasuk seluruh biaya) | 1000 Cash |
| Jual (untung) | 1000 Cash, 5000 Fee, 5200 Tax | 1100 Stock Portfolio, 4000 Realized Gain |
| Jual (rugi) | 1000 Cash, 5000 Fee, 5200 Tax, 4001 Realized Loss | 1100 Stock Portfolio |
| Dividen tanpa pajak | 1000 Cash | 4100 Dividend Income |
| Dividen dengan pajak | 1000 Cash (net), 5200 Tax | 4100 Dividend Income (gross) |
| Biaya administrasi | 5100 Administrative Expense | 1000 Cash |

Transfer antar sekuritas hanya memindahkan dimensi `securities_account_id` pada
akun yang sama, sehingga **total kas global tidak berubah** dan tidak ada
revenue/expense yang tersentuh (§40.5).

## Yang tidak dijurnal

- **Unrealized gain/loss** — hanya dihitung untuk pelaporan dari
  `market_prices` terbaru. Harga pasar tidak pernah mengubah book cost historis (§14).
- **Perubahan harga pasar** itu sendiri.
