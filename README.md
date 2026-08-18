# VestLedger

**Investment Portfolio & Accounting Management System** — aplikasi web untuk
mencatat aktivitas investasi saham lintas banyak sekuritas dengan pembukuan
double-entry yang benar secara akuntansi.

Dibangun dengan CodeIgniter 4 + Tailwind CSS + DaisyUI + Alpine.js, dan dirancang
agar dapat di-deploy ke **shared hosting PHP 8.2 tanpa Node.js runtime**.

---

## Status pembangunan

| Phase | Lingkup | Status |
|---|---|---|
| 1 | Foundation — CI4, autentikasi, design system, tema | ✅ Selesai |
| 2 | Master Data — sekuritas, saham, CoA, periode akuntansi | ⬜ |
| 3 | Transaction Engine — top up, withdrawal, transfer, beli, jual, dividen, fee | ⬜ |
| 4 | Accounting Engine — jurnal, buku besar, reversal, audit trail | ⬜ |
| 5 | Portfolio Engine — posisi, average cost, realized & unrealized G/L | ⬜ |
| 6 | Reporting — neraca, laba rugi, arus kas, trial balance, bulanan, tahunan | ⬜ |
| 7 | Dashboard & UI — chart, filter, penyempurnaan responsive | ⬜ |
| 8 | Opening Balance & Closing Period | ⬜ |
| 9 | Testing, Security Review & Deployment | ⬜ |

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
migrasi database. Setelah itu:

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
make dev        # Tailwind watch mode selama mengembangkan UI
make build      # build ulang CSS + salin Alpine.js ke public/assets
make test       # jalankan seluruh test
make migrate    # jalankan migrasi database
make fresh      # rollback seluruh migrasi lalu migrasi ulang (HATI-HATI: menghapus data)
```

---

## Menguji

```bash
make test
```

Test memakai database terpisah `vestledger_test` (dikonfigurasi di `.env` pada
grup `database.tests`) dan menjalankan migrasi sendiri, jadi data development
tidak tersentuh.

Cakupan test Phase 1:

- **`tests/unit/FormatHelperTest.php`** — format uang/kuantitas gaya Indonesia,
  konversi lot↔lembar (`100 lot = 10.000 lembar`), penanda arah gain/loss.
- **`tests/feature/AuthAccessTest.php`** — halaman aplikasi tidak bisa dibuka
  tanpa login, halaman login memakai layout VestLedger, form dilindungi CSRF,
  route registrasi publik tidak terdaftar.
- **`tests/feature/DashboardTest.php`** — otorisasi per group, sidebar
  menyembunyikan menu yang tidak diizinkan, menu yang belum dibangun tidak
  dirender sebagai link, layout merujuk aset hasil build.

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
│   └── AuthGroups.php     # group owner/accountant/viewer + matrix permission
├── Controllers/
├── Helpers/
│   ├── format_helper.php  # fmt_rupiah, fmt_signed, fmt_lot, amount_class, component()
│   └── asset_helper.php   # asset_url() dengan cache-busting berbasis mtime
├── Services/              # Accounting/ Portfolio/ Transaction/ Reporting/ (Phase 3+)
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

## Deployment

Lihat **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** untuk prosedur lengkap ke
shared hosting.
