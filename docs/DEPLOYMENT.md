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

Jalankan ulang **setiap kali** file konfigurasi berubah. Bila konfigurasi terasa
"tidak berubah" setelah edit, bersihkan cache:

```bash
php spark cache:clear
```

Aktifkan juga OPcache di panel hosting bila tersedia.

## 12. Pemeriksaan keamanan production

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

## Update aplikasi berikutnya

```bash
# lokal
make build && git commit -am "..." && git push

# server
git pull
composer install --no-dev --optimize-autoloader
php spark migrate --all
bash bin/write-build-info.sh   # catat commit yang ter-deploy, tampil di sidebar
php spark optimize
```
