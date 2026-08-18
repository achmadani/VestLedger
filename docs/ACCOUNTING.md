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

Akun-akun di atas ditandai `is_system` di database dan didefinisikan sebagai
`App\Enums\AccountCode`. Service akuntansi **tidak pernah** menulis kode akun
sebagai string literal — semuanya lewat enum tersebut, sehingga salah ketik kode
menjadi error PHP, bukan jurnal yang diam-diam salah. Akun bertanda `is_system`
tidak dapat dihapus, dinonaktifkan, maupun diubah kode/tipe/saldo normalnya;
namanya masih boleh disesuaikan.

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

## Konvensi nilai pada transaksi kas

Seluruh transaksi kas memakai konvensi yang sama:

- **`amount`** adalah nilai **pokok** transaksi,
- **`fee`** adalah biaya yang menyertainya — selalu dibebankan ke akun 5100,
- **`net_amount`** adalah pergerakan kas yang sesungguhnya pada rekening utama.

| Transaksi | Kas berubah | Pokok dicatat di |
|---|---|---|
| Top Up | `+ (amount − fee)` | 3000 sebesar `amount` penuh |
| Withdrawal | `− (amount + fee)` | 3200 sebesar `amount` |
| Transfer | asal `− (amount + fee)`, tujuan `+ amount` | 1000, hanya berpindah dimensi |
| Biaya Administrasi | `− amount` | 5100 sebesar `amount` |

Top up mencatat modal disetor sebesar nilai **bruto** yang benar-benar disetorkan
pemilik; biaya yang dipotong pihak lain adalah beban, bukan pengurang setoran.

Transfer tanpa biaya tidak menyentuh satu pun akun pendapatan/beban dan total kas
global tidak berubah (§40.5). Biaya transfer, bila ada, adalah peristiwa ekonomi
tersendiri — uang benar-benar keluar — sehingga dicatat sebagai beban.

## Pembatalan transaksi

Tidak ada penghapusan (§40.8). Pembatalan menghasilkan **jurnal pembalik**:
setiap baris jurnal asli dicatat ulang pada sisi yang berlawanan. Membalik dengan
menukar sisi — bukan dengan mencatat nilai negatif — menjaga seluruh saldo
debit/kredit tetap positif sehingga Trial Balance tetap terbaca wajar.

Jurnal asli tidak diubah isinya; hanya statusnya menjadi `reversed`, dan jurnal
pembalik menyimpan rujukan `reverses_entry_id` ke jurnal aslinya.

**Batasan khusus transaksi saham:** hanya transaksi **terakhir** pada sebuah
posisi yang dapat dibatalkan. Ini bukan batasan teknis melainkan konsekuensi
akuntansi — average cost bersifat berurutan, sehingga membatalkan pembelian lama
akan mengubah average cost yang dipakai penjualan-penjualan sesudahnya, dan
realized gain/loss yang sudah terlanjur masuk laporan menjadi salah. Koreksi
dilakukan dari transaksi paling akhir mundur ke belakang.

## Presisi aritmetika

Seluruh nilai uang dihitung memakai `App\ValueObjects\Money`, yang menyimpan
nilai sebagai **bilangan bulat sen** — bukan float. Pada float, `0.1 + 0.2`
tidak sama dengan `0.3`, dan selisih satu sen membuat jurnal tidak balance.

`bcmath` sengaja tidak dipakai agar tidak ada ketergantungan ekstensi yang
mungkin tidak tersedia di shared hosting (§35).

Harga per lembar memakai `App\ValueObjects\Price` dengan presisi 4 desimal;
perkalian harga × jumlah lembar diturunkan ke 2 desimal dengan pembulatan
half-up, seluruhnya dalam aritmetika bilangan bulat.

## Pengaman berlapis

| Lapisan | Yang dijaga |
|---|---|
| `Money` / `Price` | Aritmetika eksak, tanpa galat pembulatan float |
| `JournalDraft` | Nilai negatif dibalik ke sisi lawan, bukan menjadi debit negatif |
| `JournalPoster` | Debit = kredit, minimal dua baris, dimensi wajib, periode terbuka |
| `JournalPoster` | **Menolak berjalan di luar database transaction** |
| `PositionService` | Menolak mengubah posisi di luar database transaction |
| CHECK constraint DB | `journal_lines`: satu baris hanya debit ATAU kredit, tidak negatif |
| CHECK constraint DB | `stock_positions`: quantity dan book_value tidak boleh negatif |

Lapisan terakhir penting: seandainya ada bug di kode aplikasi yang lolos seluruh
validasi, database sendiri yang menolak baris jurnal yang cacat.

## Penyajian laporan

Seluruh laporan keuangan dihitung dari `journal_lines` — tidak satu pun angkanya
berasal dari tabel transaksi atau tabel posisi. Dengan begitu laporan keuangan
tidak mungkin bertentangan dengan buku besar.

**Neraca.** Akun nominal belum ditutup ke laba ditahan, sehingga laba/rugi
berjalan disajikan sebagai baris ekuitas tersendiri. Persamaan
Aset = Kewajiban + Ekuitas karenanya selalu terpenuhi tanpa memerlukan jurnal
penutup.

**Arus Kas** memakai metode langsung. Setiap jurnal yang menyentuh akun kas
diklasifikasikan menurut akun lawannya: menyentuh 1100 berarti investasi,
menyentuh 3000/3200 berarti pendanaan, selainnya operasi. Transfer antar
sekuritas tidak muncul sama sekali — kedua sisinya akun kas yang sama sehingga
saling meniadakan, persis seperti seharusnya (§18).

**Posisi historis.** Laporan per tanggal lampau memakai posisi PADA TANGGAL ITU,
diturunkan dari dimensi akun 1100 di buku besar (nilai) dan dari transaksi
(jumlah lembar). Tabel `stock_positions` hanya menyimpan keadaan terkini dan
dipakai saat transaksi berjalan, bukan untuk pelaporan historis.

## Saldo awal

Dipakai sekali, saat aplikasi mulai digunakan oleh investor yang sudah memiliki
kas dan posisi saham sebelumnya (§19).

```
Dr Kas (1000)               per rekening sekuritas
Dr Portofolio Saham (1100)  per rekening + saham
    Cr Modal Disetor (3000)      nilai yang dimasukkan pengguna
    Cr Laba Ditahan (3100)       ANGKA PENYEIMBANG = aset − modal disetor
```

**Laba ditahan tidak dimasukkan pengguna.** Meminta pengguna mengetiknya sendiri
hanya akan melahirkan saldo awal yang tidak balance, padahal angka itu memang
turunan: pemilik tahu berapa asetnya dan berapa yang pernah ia setorkan, dan
selisihnya adalah akumulasi laba masa lalu. Bila modal melebihi aset, laba
ditahan menjadi negatif — itu akumulasi rugi, dan tetap sah.

Dua penjagaan:

1. Saldo awal harus bertanggal **sebelum seluruh transaksi**; jika tidak, ia
   bukan lagi saldo *awal*.
2. Saldo awal hanya dapat dihapus selama **belum ada transaksi sama sekali**.
   Begitu ada transaksi, average cost-nya sudah dibangun di atas posisi awal,
   dan menghapus dasarnya akan membuat realized gain/loss yang terlanjur
   dicatat menjadi salah. Penghapusan pun lewat jurnal pembalik, bukan delete.

Posisi dari saldo awal ikut diperhitungkan dalam seluruh laporan historis:
`PositionSnapshotRepository` menggabungkan kuantitas dari `stock_transactions`
**dan** dari `opening_balances`.

## Periode akuntansi

Setiap transaksi harus jatuh pada periode yang berstatus `open`. Dua aturan
urutan dijaga sistem agar buku tidak berlubang:

1. Sebuah periode hanya dapat **ditutup** bila semua periode sebelumnya sudah
   tertutup — tanpa ini, laba periode dihitung di atas periode yang isinya masih
   dapat berubah.
2. Hanya periode tertutup **paling akhir** yang dapat dibuka kembali — membuka
   periode lama akan mengubah saldo awal periode-periode sesudahnya yang sudah
   dinyatakan final.

Transaksi bertanggal pada periode tertutup ditolak. Koreksinya lewat jurnal
reversal di periode terbuka, bukan dengan mengubah data lama (§26, §40.8).

## Yang tidak dijurnal

- **Unrealized gain/loss** — hanya dihitung untuk pelaporan dari `market_prices`
  terbaru, dan tidak pernah masuk laba rugi periode berjalan (§13, §40.2).
- **Harga pasar** itu sendiri. Mencatat harga tidak menghasilkan satu pun baris
  jurnal dan tidak mengubah book cost historis (§14).

### Posisi tanpa harga pasar

Posisi yang belum memiliki harga **tidak** diperlakukan seolah market value-nya
sama dengan book value. Menyamakannya berarti mengklaim unrealized-nya nol,
padahal yang benar adalah *belum diketahui*.

Posisi seperti itu dilaporkan terpisah (`unpriced_count` dan
`unpriced_book_value`), ditandai dengan peringatan di setiap halaman portofolio,
dan dinilai pada book value saat menghitung net worth — dengan fakta itu
dinyatakan terbuka, bukan disembunyikan.

### Average cost gabungan per ticker

Halaman "Portofolio per Saham" menampilkan average cost gabungan lintas
sekuritas (`total book value / total quantity`). Angka ini **khusus untuk
pelaporan**. Dalam pencatatan akuntansi, book cost tiap sekuritas tetap terpisah
dan tidak pernah dicampur (§5) — penjualan di satu sekuritas selalu memakai
average cost sekuritas itu sendiri.
