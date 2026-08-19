# Deployment ke Shared Hosting

Target: **PHP 8.2, Apache, MySQL, Composer**.

Production **tidak** memerlukan Node.js runtime, Docker, Redis, supervisor,
queue worker, maupun websocket server. Aset frontend di-build saat development
dan hasilnya ikut di-commit, sehingga di server cukup menyajikan file statis.

---

## 1. Build aset di mesin lokal

```bash
make build
```

Menghasilkan `public/assets/css/app.css` (Tailwind + DaisyUI, minified) dan
`public/assets/js/alpine.min.js`. Kedua file ini di-commit ke repository —
**inilah alasan server tidak butuh Node.js**.

## 2. Upload project

Upload seluruh isi project **kecuali**: `node_modules/`, `.git/`, `tests/`,
`resources/`, `package.json`, `package-lock.json`, dan `.env` lokal.

Bila hosting mengizinkan SSH + Git, lebih baik `git clone` lalu `git pull`
untuk update berikutnya.

## 3. Install dependency Composer

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` membuang PHPUnit dan kawan-kawannya dari production.
`--optimize-autoloader` menghasilkan classmap statis sehingga autoload lebih cepat.

Jika hosting tidak menyediakan Composer, jalankan perintah di atas secara lokal
dengan `--no-dev` lalu upload folder `vendor/` hasilnya.

> ⚠️ `composer install --no-dev` **menghapus** PHPUnit dan dependensi
> pengembangan dari `vendor/` — bahkan dengan `--dry-run` pada Composer 2.10.
> Jangan menjalankannya di mesin development Anda; pulihkan dengan
> `composer install` bila terlanjur.

> Gunakan `composer config platform.php 8.2.0` (sudah disetel di repo ini) agar
> dependency selalu diresolusi untuk PHP 8.2 meskipun mesin development memakai
> PHP versi lebih tinggi.

## 4. Konfigurasi environment

Salin `env` menjadi `.env`, lalu setel minimal:

```ini
CI_ENVIRONMENT = production

app.baseURL = 'https://domain-anda.com/'
app.forceGlobalSecureRequests = true
app.indexPage = ''

security.csrfProtection = 'session'
security.tokenRandomize = true

session.driver   = 'CodeIgniter\Session\Handlers\FileHandler'
session.cookieName = 'vestledger_session'

cookie.secure   = true
cookie.httponly = true
cookie.samesite = 'Lax'

logger.threshold = 4
```

`CI_ENVIRONMENT = production` mematikan debug toolbar dan halaman error yang
menampilkan stack trace.

`security.csrfProtection` **harus** `session` — Shield menolak berjalan dengan
mode `cookie` karena rentan terhadap same-site attacker.

## 5. Konfigurasi database

```ini
database.default.hostname = localhost
database.default.database = nama_database
database.default.username = user_database
database.default.password = ********
database.default.DBDriver = MySQLi
database.default.port     = 3306
database.default.charset  = utf8mb4
database.default.DBCollat = utf8mb4_general_ci
```

Buat database dengan charset `utf8mb4` dan berikan user hak penuh atasnya.

## 6. Migration

```bash
php spark migrate --all
```

`--all` menjalankan migrasi seluruh namespace, termasuk migrasi Shield.
Bila hosting tidak menyediakan akses SSH, jalankan migrasi dari mesin lokal
sambil menunjuk `.env` ke database production, atau import dump SQL.

## 7. Seeding

```bash
php spark db:seed InitialSeeder
```

Seeder Chart of Accounts dan master sekuritas dibuat pada Phase 2. Pada Phase 1
belum ada seeder, jadi langkah ini dilewati.

Buat akun pengguna pertama:

```bash
php spark shield:user create -n bambang -e bambang@example.com -g owner
```

Perintah menanyakan kata sandi secara interaktif — **jangan** menuliskan kata
sandi sebagai argumen shell karena akan tersimpan di history.

## 8. Asset build

Sudah selesai pada langkah 1; tidak ada yang perlu dijalankan di server.
Pastikan `public/assets/css/app.css` dan `public/assets/js/alpine.min.js` ikut
ter-upload.

## 9. Document root

Arahkan document root domain ke folder **`public/`**, bukan ke root project.
Ini menjaga `app/`, `writable/`, `vendor/`, dan `.env` tetap di luar jangkauan web.

Bila hosting tidak mengizinkan mengubah document root (mis. terkunci di
`public_html`), pilihan paling aman:

- letakkan isi `public/` di `public_html/`,
- letakkan sisa project di folder sejajar yang **tidak** dapat diakses web
  (mis. `~/vestledger-app/`),
- sesuaikan `$pathsPath` di `public_html/index.php` agar menunjuk ke
  `app/Config/Paths.php` di lokasi baru.

Inilah tata letak yang dipakai deploy otomatis di §14, dan penyesuaian
`$pathsPath` itu tidak lagi dikerjakan manual: `.cpanel.yml` menuliskan
`paths.php` di sebelah `index.php`, dan `index.php` membacanya bila ada.

Sebagai lapisan tambahan, letakkan `.htaccess` berikut di root project bila
folder tersebut ternyata tetap dapat diakses web:

```apache
Deny from all
```

## 10. Writable directory

```bash
chmod -R 755 writable
```

Folder `writable/` harus dapat ditulis oleh proses web server (cache, logs,
session, uploads). Pada sebagian shared hosting diperlukan `775`. **Jangan**
menggunakan `777`.

Pastikan `writable/` tidak dapat diakses langsung dari web — otomatis terpenuhi
bila document root sudah menunjuk ke `public/`.

## 11. Cache & optimasi

```bash
php spark optimize
```

Perintah ini mengaktifkan config caching dan locale caching CI4.

> ⚠️ **Jangan menjalankannya di shared hosting.** Selain mengaktifkan cache,
> `spark optimize` memanggil `composer install --no-dev` (lihat
> `system/Commands/Utilities/Optimize.php`). Di hosting tanpa Composer perintah
> itu gagal dan membuat deployment ditandai merah; di hosting yang punya
> Composer ia justru **menulis ulang `vendor/`** yang di-upload manual.
> Karena itu deploy otomatis (§14) tidak menjalankannya — cukup menghapus cache
> lama, dan CI4 membangunnya sendiri pada request pertama karena
> `app/Config/Optimize.php` sudah menyalakan kedua cache.

Jalankan ulang **setiap kali** file konfigurasi berubah. Bila konfigurasi terasa
"tidak berubah" setelah edit, bersihkan cache:

```bash
php spark cache:clear
```

Aktifkan juga OPcache di panel hosting bila tersedia.

## 12. Pemeriksaan kesehatan

```bash
php spark vestledger:health
```

Memeriksa hal-hal yang, bila salah, membuat seluruh laporan keuangan tidak dapat
dipercaya — dan yang tidak akan terlihat dari tampilan biasa:

- akun inti lengkap, aktif, dan bertipe benar,
- total debit sama dengan total kredit,
- **setiap jurnal** balance satu per satu (total global bisa saja balance
  meskipun ada dua jurnal yang sama-sama salah dan saling menutupi),
- saldo akun 1100 sama dengan jumlah book value seluruh posisi,
- file session berada di luar web root,
- `CI_ENVIRONMENT` sudah `production`.

Bila posisi menyimpang dari buku besar:

```bash
php spark vestledger:rebuild-positions
```

Perintah itu membangun ulang tabel posisi dari transaksi dan **tidak menyentuh
buku besar sama sekali** — ledger tetap menjadi sumber kebenaran.

## 13. Pemeriksaan keamanan production

Checklist sebelum menyerahkan aplikasi:

- [ ] `CI_ENVIRONMENT = production` dan halaman error tidak menampilkan stack trace
- [ ] Membuka `https://domain-anda.com/app/Config/App.php` menghasilkan 404
- [ ] Membuka `https://domain-anda.com/writable/logs/` menghasilkan 403/404
- [ ] Membuka `https://domain-anda.com/.env` menghasilkan 403/404
- [ ] **`session.savePath` TIDAK di-set di `.env`.** Nilai `.env` selalu berupa
      string; menulis `null` di sana membuat file session tersimpan di dalam
      `public/` dan dapat diunduh lewat browser. Pastikan `writable/session/`
      terisi setelah ada yang login, dan `public/null/` tidak pernah terbentuk
- [ ] HTTPS aktif dan `app.forceGlobalSecureRequests = true`
- [ ] `cookie.secure = true`
- [ ] Route `/register` tidak dapat diakses
- [ ] Login gagal berulang kali terkena rate limit Shield
- [ ] Backup database terjadwal

---

## 14. Deploy otomatis setiap `git push`

Hosting yang dipakai tidak menyediakan SSH maupun terminal web, dan **menolak
mengeksekusi berkas `.sh`**. Yang tersedia hanya **cPanel Git™ Version Control**
(membaca `.cpanel.yml`) dan **API token cPanel**. Keduanya cukup untuk membuat
setiap push ke `main` langsung ter-deploy.

### Cara kerjanya

```
git push  ──►  GitHub Actions (.github/workflows/deploy.yml)
                     │
                     │  1. UAPI VersionControl/update            → cPanel `git pull`
                     │  2. UAPI VersionControlDeployment/create  → jalankan .cpanel.yml
                     │  3. polling VersionControlDeployment/retrieve → tunggu hasilnya
                     ▼
               .cpanel.yml — tiga task, seluruhnya perintah inline
                     │
                     ├── task 1  salin public/ ke document root + tulis paths.php
                     ├── task 2  build.json, direktori writable/, hapus cache lama
                     └── task 3  spark migrate --all + vestledger:health → deploy.log
```

Seluruh perintah ditulis **inline di `.cpanel.yml`**, dengan path absolut untuk
setiap biner: tidak ada satu pun berkas skrip yang dieksekusi, karena hosting
memblokirnya. Setiap task adalah satu rantai `&&` dalam satu shell, sebab cPanel
tidak menjamin variabel tetap hidup antar task.

Kode aplikasi **tidak** disalin ke document root. Yang berada di bawah web hanya
isi `public/`; `app/`, `vendor/`, `writable/`, dan `.env` tetap tinggal di
`/home/USER/repositories/vestledger`. Penyambungnya `paths.php` yang ditulis task 1
di document root dan dibaca `public/index.php` — bentuk konkret dari saran §9,
tanpa perlu menyunting `index.php` di server. Path repo tidak di-hardcode:
dideteksi dari `$(pwd)`, karena cPanel menjalankan task dari root repo.

### Tata letak di server

| Lokasi | Isi | Diperbarui oleh |
|---|---|---|
| `/home/USER/repositories/vestledger` | seluruh kode, hasil clone dari GitHub | `git pull` yang dijalankan cPanel |
| `/home/USER/repositories/vestledger/vendor` | dependency Composer | **manual**, lewat File Manager |
| `/home/USER/repositories/vestledger/.env` | konfigurasi production | **manual**, sekali |
| `/home/USER/public_html/SUBDOMAIN` | isi `public/` + `paths.php` | `.cpanel.yml` pada setiap deploy |

### Persiapan satu kali — di hosting

1. **Buat subdomain** dengan document root `/home/USER/public_html/SUBDOMAIN`.

2. **Git Version Control → Create → Clone a Repository:**

   | Kolom | Isi |
   |---|---|
   | Clone URL | `https://x-access-token:<TOKEN_GITHUB>@github.com/achmadani/VestLedger.git` |
   | Repository Path | `/home/USER/repositories/vestledger` |
   | Repository Name | `vestledger` |

   `<TOKEN_GITHUB>` adalah fine-grained personal access token dengan izin
   **Contents: Read-only** pada repository ini. Token ikut tersimpan di
   `.git/config` milik server; bila ia kedaluwarsa, `git pull` berhenti bekerja
   dan deploy gagal pada langkah pertama. Perbarui lewat File Manager
   (`.git/config`) atau clone ulang.

3. **Unggah `vendor/`** ke `/home/USER/repositories/vestledger/vendor`. Di mesin
   lokal:

   ```bash
   composer install --no-dev --optimize-autoloader   # lihat peringatan di §3
   zip -rq vendor.zip vendor
   ```

   Unggah `vendor.zip` lewat File Manager, extract di root repo, lalu pulihkan
   mesin lokal dengan `composer install`.

4. **Buat `.env`** di root repo (salin dari berkas `env`), isi sesuai §4 dan §5.

5. **Buat API token cPanel:** Security → Manage API Tokens → Create, beri nama
   `github-deploy`, salin tokennya (hanya ditampilkan sekali).

### Persiapan satu kali — di repository

Sunting **satu baris** di [`.cpanel.yml`](../.cpanel.yml), yaitu `DEPLOYPATH` pada
task pertama:

```yaml
- 'export DEPLOYPATH=/home/USERNAME/public_html/SUBDOMAIN &&
```

Itu satu-satunya path yang perlu diisi; sisanya terdeteksi sendiri. Lakukan di
mesin lokal lalu commit — **jangan** menyuntingnya lewat File Manager, karena
working tree yang kotor membuat `git pull` milik cPanel gagal.

### Persiapan satu kali — di GitHub

Settings → Secrets and variables → Actions:

| Secret | Contoh isi |
|---|---|
| `CPANEL_HOST` | `https://server123.hostingku.com` (tanpa garis miring di ujung) |
| `CPANEL_USER` | nama pengguna cPanel |
| `CPANEL_TOKEN` | token dari langkah 5 |
| `CPANEL_REPO_ROOT` | `/home/USER/repositories/vestledger` |

| Variable (opsional) | Gunanya |
|---|---|
| `CPANEL_PORT` | port API cPanel bila bukan `2083` |
| `SITE_URL` | bila diisi, workflow memeriksa situs membalas 200/302 setelah deploy |

Setelah itu tidak ada lagi langkah manual:

```bash
make release      # naikkan versi, commit, push  →  deploy berjalan sendiri
make deploy       # ulangi deploy tanpa push baru (butuh gh CLI)
make deploy-log   # lima jalannya workflow terakhir
```

Tombol **Deploy HEAD Commit** di cPanel tetap bekerja dan melakukan hal yang
persis sama — keduanya menjalankan `.cpanel.yml` yang sama.

### Yang perlu diingat

- **`vendor/` tidak pernah ikut ter-deploy.** Tidak ada Composer di server.
  Setiap kali `composer.lock` berubah, `vendor/` harus diunggah ulang; workflow
  memberi peringatan di log jalannya bila berkas itu ikut berubah dalam push.
- **`spark optimize` sengaja tidak dijalankan** — lihat peringatan di §11.
- **Migrasi berjalan otomatis** bila PHP CLI ≥ 8.2 tersedia di server. Bila tidak,
  task 3 tetap berhasil dan alasannya dicatat di log; migrasi lalu dijalankan
  dari mesin lokal sambil menunjuk `.env` ke database production (§6).
  Bila migrasi *gagal*, deployment ditandai gagal dan workflow menjadi merah —
  tetapi berkas publik sudah tersalin, jadi kode dan skema dapat sesaat tidak
  sinkron.
- **Log deploy ada di dua tempat:** log jalannya GitHub Actions (sisi pemicu) dan
  `writable/logs/deploy.log` di server (sisi eksekusi, dibaca lewat File Manager).
  Yang kedua memuat keluaran migrasi dan health check.
- **Berkas yang dihapus dari `public/`** tidak ikut terhapus di document root:
  task 1 menyalin, tidak menyinkronkan, supaya `cgi-bin/` dan `.well-known/`
  milik hosting tidak ikut hilang. Hapus manual bila memang perlu.

---

## Update aplikasi berikutnya

Sejak §14 aktif, tidak ada langkah manual:

```bash
make build                 # bila tampilan berubah — aset hasil build ikut di-commit
make release               # naikkan versi, commit, push → deploy otomatis
```

Kecuali satu hal: bila `composer.lock` berubah, unggah ulang `vendor/` ke server
(§14 langkah 3) sebelum atau segera setelah push.

Bila deploy otomatis belum dipasang, urutannya di cPanel adalah *Update from
Remote* → *Deploy HEAD Commit*, yang menjalankan `.cpanel.yml` yang sama —
persis langkah yang dahulu dikerjakan lewat SSH:

```bash
git pull
composer install --no-dev --optimize-autoloader   # hanya bila ada shell + Composer
php spark migrate --all
bash bin/write-build-info.sh
php spark vestledger:health
```
