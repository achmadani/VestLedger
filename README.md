# VestLedger

**Investment Portfolio & Accounting Management System** — aplikasi web untuk
mencatat aktivitas investasi saham lintas banyak sekuritas dengan pembukuan
double-entry yang benar secara akuntansi.

Dibangun dengan CodeIgniter 4 + Tailwind CSS + DaisyUI + Alpine.js, dan dirancang
agar dapat di-deploy ke **shared hosting PHP 8.2 tanpa Node.js runtime**.

---

Riwayat perubahan ada di **[CHANGELOG.md](CHANGELOG.md)**.

## Status pembangunan

| Phase | Lingkup | Status |
|---|---|---|
| 1 | Foundation — CI4, autentikasi, design system, tema | ✅ Selesai |
| 2 | Master Data — sekuritas, saham, CoA, periode akuntansi | ✅ Selesai |
| 3+4 | Transaction & Accounting Engine — transaksi, jurnal, buku besar, reversal, audit trail, posisi & average cost | ✅ Selesai |
| 5 | Portfolio Engine — harga pasar, unrealized G/L, tampilan portofolio | ✅ Selesai |
| 6 | Reporting — neraca, laba rugi, arus kas, trial balance, bulanan, tahunan | ⬜ |
| 7 | Dashboard & UI — chart, filter, penyempurnaan responsive | ✅ Selesai |
| 8 | Opening Balance & Closing Period | ✅ Selesai |
| 9 | Testing, Security Review & Deployment | ✅ Selesai |

Menu untuk phase yang belum dibangun tampil sebagai placeholder non-aktif di
sidebar (dengan badge nomor phase), sehingga kerangka aplikasi terlihat utuh
tanpa menghasilkan link 404.

---

## Arsitektur

```
Presentation (Views + DaisyUI components)
        ↓
Controller            ← tipis: request/response saja
        ↓
Service Layer         ← app/Services/{Transaction,Accounting,Portfolio,Reporting}
        ↓
Domain / Business Logic
        ↓
Model / Repository    ← app/Models
        ↓
Database (MySQL)
```

Aturan yang ditegakkan sepanjang proyek:

- Tidak ada perhitungan akuntansi di Controller maupun View.
- Business logic tidak bergantung pada tampilan; mengganti tema DaisyUI tidak
  menyentuh satu baris pun logika.
- Alpine.js hanya untuk interaksi ringan (modal, dropdown, preview form,
  konversi lot↔lembar). Tidak ada SPA framework.

Aturan akuntansinya didokumentasikan terpisah dan mengikat seluruh phase
berikutnya: **[docs/ACCOUNTING.md](docs/ACCOUNTING.md)**.

---

## Prasyarat lokal

| Kebutuhan | Di mesin ini |
|---|---|
| PHP 8.2+ | `php83` (alias Homebrew PHP 8.3). **`php` default di mesin ini adalah PHP 5.6** |
| MySQL 8 | container Podman `mysql8-podman`, port **3308**, user `root` |
| Composer | `/opt/homebrew/bin/composer` |
| Node.js 20+ | via nvm — **hanya untuk build aset**, tidak dibutuhkan production |

> ⚠️ Karena `php` default adalah 5.6, semua perintah `composer`/`spark` harus
> dijalankan dengan PATH PHP 8.3 di depan. `Makefile` sudah menanganinya.

---

## Menjalankan aplikasi

```bash
make setup
```

Perintah itu menjalankan `composer install`, `npm install`, build aset, dan
migrasi database. Lalu isi master data awal:

```bash
make seed
```

`make seed` membuat Chart of Accounts inti, 5 sekuritas, 4 saham contoh, dan 12
periode akuntansi untuk tahun berjalan. Seluruhnya idempoten — aman dijalankan
berulang kali. Setelah itu:

```bash
make serve
```

Aplikasi berjalan di <http://localhost:8123>.

### Membuat akun pertama

Registrasi mandiri **dimatikan** — aplikasi ini hanya untuk pemilik portofolio.
Buat akun lewat CLI (perintah akan menanyakan kata sandi secara interaktif):

```bash
make user-create NAME=bambang EMAIL=bambang@example.com
```

Tanpa opsi group, user tidak mendapat permission apa pun. `make user-create`
sudah menambahkan group `owner` (akses penuh). Group lain yang tersedia:
`accountant` (input transaksi & koreksi, tanpa hak tutup periode) dan `viewer`
(read-only).

### Perintah lain

```bash
make version    # tampilkan versi aplikasi saat ini
make release    # naikkan versi, commit, lalu push (PART=patch|minor|major)
make dev        # Tailwind watch mode selama mengembangkan UI
make build      # build ulang CSS + salin Alpine.js ke public/assets
make seed       # isi master data awal (idempoten)
make health     # periksa integritas akuntansi & konfigurasi keamanan
make rebuild    # bangun ulang posisi dari transaksi (buku besar tidak disentuh)
make test       # jalankan seluruh test
make migrate    # jalankan migrasi database
make fresh      # rollback seluruh migrasi lalu migrasi ulang (HATI-HATI: menghapus data)
```

> ⚠️ **Class Tailwind baru butuh build ulang.** Utility yang belum pernah dipakai
> di `app/Views` tidak ada di bundle CSS sampai `make build` dijalankan — dan
> gejalanya menyesatkan: layout tampak "rusak" padahal markup-nya benar.
> `make serve` sudah otomatis build lebih dulu; gunakan `make dev` saat aktif
> mengubah tampilan.

---

## Menguji

```bash
make test
```

Test memakai database terpisah `vestledger_test` (dikonfigurasi di `.env` pada
grup `database.tests`) dan menjalankan migrasi sendiri, jadi data development
tidak tersentuh.

Cakupan test:

- **`tests/unit/FormatHelperTest.php`** — format uang/kuantitas gaya Indonesia,
  konversi lot↔lembar (`100 lot = 10.000 lembar`), penanda arah gain/loss.
- **`tests/feature/AuthAccessTest.php`** — halaman aplikasi tidak bisa dibuka
  tanpa login, halaman login memakai layout VestLedger, form dilindungi CSRF,
  route registrasi publik tidak terdaftar.
- **`tests/feature/DashboardTest.php`** — otorisasi per group, sidebar
  menyembunyikan menu yang tidak diizinkan, menu yang belum dibangun tidak
  dirender sebagai link, layout merujuk aset hasil build.
- **`tests/unit/AccountEnumsTest.php`** — saldo normal tiap tipe akun, akun
  kontra 3200, akun mana yang membawa dimensi sekuritas/saham.
- **`tests/database/ChartOfAccountsTest.php`** — perlindungan akun inti (tidak
  bisa dihapus, diubah kode/tipenya, atau dinonaktifkan), penjagaan siklus
  induk-anak, idempotensi seeder.
- **`tests/database/AccountingPeriodTest.php`** — batas tanggal periode termasuk
  tahun kabisat, urutan tutup/buka periode, penolakan posting ke periode tertutup.
- **`tests/database/MasterDataTest.php`** — sekuritas selalu punya rekening,
  rollback saat rekening gagal, normalisasi kode/ticker, masking nomor rekening.
- **`tests/feature/MasterDataAccessTest.php`** — otorisasi rute master data;
  POST di test menyertakan token CSRF agar penolakan yang diuji benar-benar
  berasal dari filter permission, bukan dari CSRF.
- **`tests/unit/MoneyTest.php`** — aritmetika uang eksak, pembagian proporsional
  book value, penjualan bertahap yang harus menghabiskan book value tanpa sisa.
- **`tests/database/CashTransactionTest.php`** — top up bukan pendapatan,
  withdrawal bukan beban, transfer tidak mengubah total kas global, penolakan
  transaksi ke periode tertutup beserta rollback-nya.
- **`tests/database/StockTransactionTest.php`** — contoh terhitung §12,
  kapitalisasi biaya beli, realized gain & loss, jual habis tanpa sisa book value,
  average cost terpisah per sekuritas, dan invariant **akun 1100 = total book
  value seluruh posisi**.
- **`tests/database/DividendAndJournalTest.php`** — dividen bruto vs pajak, dan
  pengaman JournalPoster (tidak balance, satu baris, dimensi hilang, dipanggil di
  luar database transaction).
- **`tests/database/ReversalTest.php`** — pembalikan mengembalikan posisi dan
  saldo, tidak menghapus apa pun, dan hanya transaksi terakhir pada satu posisi
  yang boleh dibatalkan.
- **`tests/feature/TransactionUiTest.php`** — alur beli→jual lewat HTTP, buku
  besar tetap balance, dan otorisasi tiap rute transaksi.
- **`tests/database/PortfolioTest.php`** — contoh terhitung §13, unrealized tidak
  pernah masuk laba periode berjalan, harga pasar tidak menghasilkan jurnal,
  harga terbaru pada/atau sebelum tanggal laporan, dan agregasi per ticker.
- **`tests/feature/PortfolioUiTest.php`** — halaman portofolio, input harga
  massal, dan otorisasi `price.manage`.
- **`tests/unit/SecurityConfigTest.php`** — penjaga konfigurasi keamanan; lihat
  catatan di bawah.
- **`tests/database/ReportingTest.php`** — neraca balance di berbagai tanggal,
  laba rugi cocok dengan buku besar dan tidak memuat unrealized, arus kas
  merekonsiliasi saldo awal ke saldo akhir, transfer internal tidak muncul di
  arus kas, saldo akhir bulan menjadi saldo awal bulan berikutnya, dan total
  tahunan sama dengan jumlah bulanannya.
- **`tests/feature/ReportUiTest.php`** — seluruh halaman laporan.
- **`tests/database/OpeningBalanceTest.php`** — contoh terhitung §19, laba
  ditahan sebagai angka penyeimbang, penolakan saldo awal yang bertanggal
  setelah transaksi, dan penghapusan lewat jurnal pembalik.
- **`tests/feature/OpeningBalanceUiTest.php`** — form saldo awal, otorisasi
  `opening.manage`, dan grafik dashboard yang dirender sebagai SVG tanpa library.
- **`tests/feature/SecurityTest.php`** — menyerang aplikasi lewat HTTP: XSS
  tersimpan, SQL injection lewat filter, permintaan tanpa token CSRF, pembatasan
  laju login, penyamaran data sensitif, dan pemeriksaan menyeluruh bahwa setiap
  rute POST berada di balik filter permission.
- **`tests/feature/UserManagementTest.php`** — pembuatan akun, kekuatan kata
  sandi, dan penjagaan agar owner aktif terakhir tidak dapat dilucuti.

> **Catatan tentang test harness:** `FeatureTestTrait` CI4 memodifikasi body
> respons (atribut `@click` Alpine dihilangkan dan `&` menjadi `&amp;`).
> Output server sungguhan **tidak** terpengaruh — ini sudah diverifikasi langsung.
> Karena itu, jangan menulis assertion feature-test terhadap atribut Alpine
> `@event`; uji perilaku interaktif lewat browser.

---

## Struktur folder

```
app/
├── Config/
│   ├── Investment.php     # parameter domain: lot size, presisi angka, daftar tema
│   ├── Navigation.php     # struktur menu sebagai data (bukan HTML)
│   ├── Auth.php           # Shield: registrasi mati, redirect, view kustom
│   ├── AuthGroups.php     # group owner/accountant/viewer + matrix permission
│   └── Services.php       # factory service layer
├── Controllers/
├── Entities/              # Security, SecuritiesAccount, Stock, Account, AccountingPeriod
├── Enums/                 # AccountType, BalanceSide, AccountCode, PeriodStatus
├── Exceptions/            # BusinessRuleException
├── Helpers/
│   ├── format_helper.php  # fmt_rupiah, fmt_signed, fmt_lot, amount_class, component()
│   └── asset_helper.php   # asset_url() dengan cache-busting berbasis mtime
├── Models/
├── Repositories/          # TransactionHistoryRepository (UNION tiga tabel transaksi)
├── Services/
│   ├── Reporting/         # FinancialStatementService, PeriodicReportService,
│   │                      # InvestmentReportService
│   ├── Accounting/        # JournalPoster, ChartOfAccountsService, AccountingPeriodService,
│   │                      # DocumentNumberService, AuditLogger
│   ├── MasterData/        # SecurityService, StockService
│   ├── Portfolio/         # PositionService (weighted average cost, rebuild)
│   ├── Transaction/       # Cash/Stock/Dividend TransactionService, ReversalService
│   └── Reporting/         # (Phase 6)
├── ValueObjects/          # Money, Price, JournalDraft, JournalLineDraft
└── Views/
    ├── layouts/           # app.php (terautentikasi), auth.php (tamu)
    ├── components/        # navbar, sidebar, card, stat, table, modal, pager, form/*
    ├── auth/
    └── dashboard/
docs/ACCOUNTING.md         # aturan akuntansi yang mengikat seluruh phase
resources/css/app.css      # sumber Tailwind + konfigurasi tema DaisyUI
public/assets/             # hasil build (di-commit, agar production tanpa Node.js)
```

### Komponen UI reusable

Dipanggil dengan helper `component()`:

```php
<?= component('stat', ['label' => 'Total Kas', 'value' => fmt_rupiah($cash)]) ?>
<?= component('form/money', ['name' => 'amount', 'label' => 'Nominal']) ?>
<?= component('form/quantity', ['nameShares' => 'quantity']) ?>
```

`form/money` mengirim **angka mentah** lewat hidden input, jadi server tidak
pernah perlu mem-parsing format tampilan — mencegah salah baca nominal.
`form/quantity` menyinkronkan lot↔lembar dan mengirim **lembar** sebagai nilai utama.

### Mengganti tema

Tema DaisyUI dipilih pengguna dari navbar dan disimpan di `localStorage`.
Daftar tema didefinisikan di dua tempat yang harus sinkron:

1. `resources/css/app.css` → blok `@plugin "daisyui" { themes: ... }`
2. `app/Config/Investment.php` → properti `$themes`

Setelah mengubahnya, jalankan `make build`.

---

## Versi aplikasi

Nomor versi ada di berkas `VERSION` (di-commit) dan ditampilkan di sidebar serta
halaman login. Commit pendek diambil dari `writable/build.json`, yang
**tidak** di-commit melainkan dihasilkan saat build/deploy.

Alasannya: sebuah berkas tidak mungkin memuat SHA commit-nya sendiri — kalau
ikut di-commit, ia akan selalu menunjuk commit sebelumnya. Dengan dihasilkan
setelah `git pull` di server, ia menunjuk commit yang benar-benar ter-deploy.

Keduanya berupa berkas, bukan pemanggilan `git` saat runtime: server produksi
sering tidak memiliki direktori `.git`, dan memanggil proses eksternal pada
setiap request tidak pantas. Bila `build.json` tidak ada, UI cukup menampilkan
nomor versinya saja.

**Versi wajib naik pada setiap push.** Hook `.githooks/pre-push` menolak push
bila `VERSION` sama dengan yang sudah ada di remote. Aktifkan hook-nya sekali
dengan `make hooks` (sudah termasuk dalam `make setup`), lalu gunakan:

```bash
make release                 # naikkan patch, commit, push
make release PART=minor      # untuk phase baru
```

## Catatan keamanan yang pernah terjadi

`session.savePath = null` di `.env` **tidak** berarti "kosong". Nilai `.env`
selalu dibaca sebagai string, sehingga CI4 memakai direktori relatif bernama
`null` di bawah direktori kerja — yaitu `public/`. Akibatnya file session berada
**di dalam web root** dan dapat diunduh langsung lewat browser.

Kesalahan ini pernah aktif di proyek ini dan file session sempat ikut ter-commit.
Perbaikannya: baris tersebut dihapus dari `.env` sehingga berlaku default
`WRITEPATH . 'session'`. `tests/unit/SecurityConfigTest.php` sekarang menjaga
agar `savePath` tidak pernah lagi berada di bawah `public/`.

## Deployment

Lihat **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** untuk prosedur lengkap ke
shared hosting.
