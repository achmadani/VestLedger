# Changelog

Seluruh perubahan penting VestLedger dicatat di berkas ini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.1.0/),
dan penomoran versi mengikuti [Semantic Versioning](https://semver.org/lang/id/).

Nomor versi naik pada **setiap push** — dijaga oleh hook `.githooks/pre-push`.
Naikkan lewat `make release` (patch), atau `make release PART=minor` untuk phase baru.

> Catatan: entri untuk versi 0.1.0–0.5.0 disusun surut sebagai **rangkuman garis
> besar**, karena changelog ini baru dibuat pada v0.5.1. Rincian lengkapnya ada
> di pesan commit masing-masing.

## [Belum dirilis]

## [0.5.2] — 2026-08-18

### Diperbaiki
- `writable/build.json` tidak lagi di-commit. Sebuah berkas tidak mungkin memuat
  SHA commit-nya sendiri, sehingga versi yang di-commit selalu menunjuk commit
  sebelumnya. Kini berkas tersebut dihasilkan saat build/deploy — setelah
  `git pull` di server — sehingga menunjuk commit yang benar-benar ter-deploy.

## [0.5.1] — 2026-08-18

### Ditambahkan
- Nomor versi aplikasi, ditampilkan di sidebar dan halaman login beserta commit pendek.
- `make release` dan hook `pre-push` yang menolak push bila versi tidak naik.
- CHANGELOG.md (berkas ini).
- Peringatan saldo kas negatif: banner di dashboard dan halaman portofolio,
  proyeksi "Kas Setelah Transaksi" pada form beli/jual, serta flash setelah
  transaksi tersimpan.

### Diubah
- Pembelian melebihi saldo kas **tidak diblokir**. Aplikasi ini dipakai untuk
  pencatatan dan transaksi kerap dimasukkan mundur (backdate), sehingga saldo
  dapat tampak negatif hanya karena top up-nya belum sempat dicatat. Sistem
  menandainya dengan jelas, bukan menolaknya.

## [0.5.0] — 2026-08-18 — Phase 5: Portfolio

### Ditambahkan
- Tabel `market_prices`: satu harga penutupan per saham per tanggal, input ulang
  menimpa alih-alih menggandakan.
- `PortfolioService`: potret portofolio global, per sekuritas, dan per ticker,
  lengkap dengan market value, unrealized gain/loss, dan return persen.
- Halaman Portofolio Global / per Sekuritas / per Saham, dan input harga massal.
- Dashboard kini memakai data sungguhan (sebelumnya nol placeholder).

### Keamanan
- **Diperbaiki:** `session.savePath = null` di `.env` dibaca sebagai string
  `"null"`, sehingga berkas session tersimpan di `public/null/` — di dalam web
  root dan dapat diunduh lewat browser. 21 berkas sempat terbentuk, 7 di
  antaranya berisi sesi terautentikasi, dan semuanya ikut ter-commit sejak
  Phase 1. Baris tersebut dihapus, `public/null/` dikeluarkan dari git, dan
  `SecurityConfigTest` kini menjaganya. Berkas yang terlanjur ter-push masih ada
  di riwayat git.

### Catatan
- Posisi tanpa harga pasar tidak dianggap market value-nya sama dengan book
  value; ia dilaporkan terpisah karena yang benar adalah "belum diketahui",
  bukan "unrealized nol".

## [0.4.0] — 2026-08-18 — Phase 3+4: Transaction & Accounting Engine

Phase 3 dan 4 digabung agar tidak pernah ada keadaan transaksi tercatat tanpa
jurnal. Karena penjualan tidak dapat dijurnal tanpa average cost, gabungan ini
juga mencakup inti Phase 5: `stock_positions` dan weighted average cost.

### Ditambahkan
- Transaksi kas (top up, withdrawal, transfer antar sekuritas, biaya
  administrasi), transaksi saham (beli, jual), dan dividen — masing-masing
  menghasilkan jurnal otomatis.
- `JournalPoster` sebagai satu-satunya pintu masuk ke buku besar: memvalidasi
  debit = kredit, minimal dua baris, dimensi wajib, dan periode terbuka sebelum
  menyimpan.
- Pembatalan lewat jurnal pembalik; tidak ada penghapusan.
- Audit trail, buku besar dengan saldo berjalan, dan daftar transaksi gabungan.
- `Money` dan `Price`: aritmetika bilangan bulat, tanpa float dan tanpa bcmath.

### Keamanan / integritas
- `JournalPoster` dan `PositionService` menolak berjalan di luar database
  transaction.
- CHECK constraint database: satu baris jurnal hanya boleh mengisi debit **atau**
  kredit dan tidak boleh negatif; posisi saham tidak boleh negatif.

## [0.3.0] — 2026-08-18 — Phase 2: Master Data

### Ditambahkan
- Master sekuritas beserta rekening/RDN, master saham, Chart of Accounts
  hierarkis, dan periode akuntansi bulanan.
- Perlindungan akun inti: tidak dapat dihapus, dinonaktifkan, maupun diubah
  kode/tipe/saldo normalnya.
- Aturan urutan periode: hanya dapat ditutup bila periode sebelumnya sudah
  tertutup, dan hanya periode tertutup terakhir yang dapat dibuka kembali.

### Diperbaiki
- `transStart()`/`transComplete()` tidak melakukan rollback saat kegagalan hanya
  berupa validasi model, sehingga sekuritas sempat tersimpan tanpa rekening.
  Diganti transaksi eksplisit.

## [0.2.0] — 2026-08-18 — Phase 1: Foundation

### Ditambahkan
- CodeIgniter 4 dengan target PHP 8.2, autentikasi CodeIgniter Shield,
  dan otorisasi berbasis group (owner / accountant / viewer).
- Design system Tailwind CSS + DaisyUI + Alpine.js dengan pemilih tema,
  layout responsive, dan komponen UI reusable.
- Aset di-build saat development dan di-commit, sehingga produksi tidak
  memerlukan Node.js.

### Keamanan
- `security.csrfProtection` disetel ke `session`; mode `cookie` ditolak Shield
  karena rentan terhadap same-site attacker.
- Registrasi mandiri dimatikan; akun dibuat lewat CLI.

## [0.1.0] — 2026-08-18

- Commit awal repository.

[Belum dirilis]: https://github.com/achmadani/VestLedger/compare/main...HEAD
