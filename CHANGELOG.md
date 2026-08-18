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

## [0.9.0] — 2026-08-19

### Diperbaiki
- **Tombol keluar menghasilkan 404.** Shield hanya mendaftarkan logout sebagai
  GET, sedangkan navbar mengirim POST ber-CSRF. Rute POST ditambahkan; rute GET
  bawaan dibiarkan. Logout tetap lewat POST karena logout lewat GET dapat
  dipicu pihak lain hanya dengan menyisipkan `<img src=".../logout">`.
- **Mengubah master data selalu ditolak sebagai duplikat.** Aturan
  `is_unique[...,id,{id}]` mengganti `{id}` dari data yang divalidasi, bukan
  dari id yang dikirim ke `update()`. Menyimpan ulang sekuritas, saham, atau
  akun tanpa mengubah kodenya gagal dengan "kode sudah dipakai" — sejak Phase 2,
  dan tidak ada satu pun test yang menangkapnya.
- **Komponen UI mewarisi variabel halaman induknya.** `component()` meneruskan
  seluruh data view yang sedang aktif, sehingga prop opsional diam-diam terisi
  variabel bernama sama dari halaman lain. Komponen kini dirender dengan
  instance View tersendiri.
- Kolom tanggal baru pada master saham belum terdaftar di entity, sehingga
  tetap berupa string dan `->format()` gagal.

### Ditambahkan
- **Tarif biaya per sekuritas** (beli 0,15%, jual 0,25% sebagai bawaan). Tarif
  all-in dipecah menjadi fee broker, PPh final 0,1% (jual saja), dan levy bursa
  0,043%, karena ketiganya masuk akun berbeda. Form beli/jual mengisinya
  otomatis dan tetap dapat diubah manual.
- **Bea materai Rp10.000** per sekuritas per hari saat total nilai transaksi
  melebihi Rp10 juta, mengikuti Trade Confirmation harian. Perhitungannya
  menyesuaikan diri terhadap transaksi backdate maupun pembatalan.
- **Impor master saham** dari CSV data IDX: 964 emiten lengkap dengan sektor,
  subsektor, industri, subindustri, indeks, papan pencatatan, tanggal listing,
  jumlah saham, dan kapitalisasi pasar. Tersedia lewat CLI dan unggah web.
- **Pencarian saham ketik-cari** pada form beli/jual, menggantikan dropdown
  yang tidak lagi dapat dipakai dengan hampir seribu emiten. Pencarian
  dilakukan di server; daftar emiten tidak dikirim ke browser.
- **Urutan rekening sekuritas menurut frekuensi pemakaian**, yang paling sering
  dipakai di atas.
- **Login dengan akun Google** (OAuth 2.0 authorization code flow, tanpa
  dependency tambahan). Hanya berhasil untuk alamat email yang sudah terdaftar
  sebagai pengguna; pembuatan akun otomatis dimatikan.

## [0.8.0] — 2026-08-18 — Phase 9: Security Review, Performance, dan Deployment

### Ditambahkan
- Pengelolaan akun pengguna: pembuatan akun, perubahan peran, dan
  aktivasi/penonaktifan. Owner aktif terakhir tidak dapat dilucuti perannya.
- `php spark vestledger:health` — pemeriksaan integritas akuntansi dan
  konfigurasi keamanan untuk dijalankan setelah deployment.
- `php spark vestledger:rebuild-positions` — membangun ulang posisi dari
  transaksi tanpa menyentuh buku besar.
- `tests/feature/SecurityTest.php` — menyerang aplikasi lewat HTTP alih-alih
  memeriksa kode: XSS tersimpan, SQL injection lewat filter, CSRF, rate limit,
  dan penyamaran data sensitif.

### Keamanan
- **Halaman login tidak memiliki pembatasan laju.** Shield menyediakan filter
  `auth-rates` tetapi tidak memasangnya sendiri; `service('auth')->routes()`
  hanya mendaftarkan rute tanpa filter apa pun. Tanpa konfigurasi ini, halaman
  login menerima percobaan kata sandi sebanyak apa pun tanpa hambatan.
- **Kata sandi lemah diterima saat membuat pengguna.** `UserModel::save()` tidak
  memeriksa kekuatan kata sandi — aturan itu berada di
  `ValidationRules::getRegistrationRules()` yang hanya dipakai RegisterController
  bawaan, sementara registrasi di aplikasi ini dimatikan. Aturan tersebut kini
  dipanggil eksplisit; kata sandi `123` ditolak.
- Tanggal tidak valid pada query string membuat halaman error 500. Nilainya
  memang selalu terikat sebagai parameter sehingga tidak ada risiko injection,
  tetapi kini disaring lebih dulu di seluruh controller.

### Kinerja
- Metadata sekuritas dan saham dibaca sekali per request alih-alih diulang pada
  setiap potret portofolio. Laporan tahunan 190 ms → 164 ms pada volume 5 tahun
  transaksi aktif (340 transaksi, 730 baris jurnal).

## [0.7.0] — 2026-08-18 — Phase 7 & 8: Dashboard, Chart, dan Saldo Awal

### Ditambahkan
- Grafik perkembangan aset dan komposisi portofolio di dashboard, digambar
  sebagai SVG di sisi server tanpa library chart apa pun. Grafik memakai token
  warna DaisyUI sehingga ikut berubah saat tema diganti.
- Saldo awal (§19): kas per rekening, posisi saham dengan book value, dan modal
  disetor. Laba ditahan dihitung sebagai angka penyeimbang sehingga saldo awal
  dijamin balance. Penghapusan lewat jurnal pembalik, dan hanya selama belum ada
  transaksi.

### Diperbaiki
- **Posisi dari saldo awal tidak terlihat oleh laporan historis.** Kuantitas
  hanya dihitung dari `stock_transactions`, sehingga posisi awal hilang dari
  seluruh laporan meskipun book value-nya tercatat di buku besar.
- Test navigasi kini membaca konfigurasi menu alih-alih menyebut satu menu
  secara tetap; versi sebelumnya menjadi usang dua kali berturut-turut karena
  phase berikutnya mengaktifkan menu yang dirujuknya.

### Catatan
- Grafik menyajikan NILAI BUKU, bukan market value. Market value tiap akhir
  bulan memerlukan harga historis yang belum tentu diinput, dan memakai harga
  terbaru akan membuat grafik masa lalu berubah setiap kali harga hari ini
  diperbarui.

## [0.6.0] — 2026-08-18 — Phase 6: Reporting

### Ditambahkan
- Neraca, Laba Rugi, Arus Kas (metode langsung), dan Neraca Saldo.
- Laporan bulanan dengan perbandingan terhadap bulan sebelumnya, dan laporan
  tahunan dengan rincian per bulan.
- Laporan Realized Gain/Loss, Unrealized Gain/Loss, Dividen, dan Broker Fee.
- Tombol cetak pada setiap laporan.

### Diperbaiki
- **Laporan per tanggal lampau memakai posisi hari ini.** `portfolio?as_of=...`
  menilai posisi terkini dengan harga tanggal lampau — angka yang tidak pernah
  ada. Posisi historis kini diturunkan dari buku besar (dimensi akun 1100) dan
  dari transaksi.
- **Query arus kas menggandakan nilai.** `JOIN` ke baris lawan menggandakan
  baris kas sebanyak jumlah baris lawan pada jurnal yang sama, sehingga nilainya
  ikut terkali. Tidak terlihat pada jurnal dua baris, dan baru muncul pada
  jurnal penjualan yang berbaris banyak. Agregasi kini dilakukan di subquery.
- Test dashboard yang menjadi usang karena menu Neraca kini aktif; test kini
  menunjuk menu yang memang belum dibangun, dan nama pengguna di test dibuat
  unik agar tidak bentrok antar-run.

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
