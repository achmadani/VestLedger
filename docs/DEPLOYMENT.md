# Deployment ke Shared Hosting

Target: **PHP 8.2, Apache, MySQL, Composer**.

Production **tidak** memerlukan Node.js runtime, Docker, Redis, supervisor,
queue worker, maupun websocket server. Aset frontend di-build saat development
dan hasilnya ikut di-commit, sehingga di server cukup menyajikan file statis.

### Ekstensi PHP

| Ekstensi | Status |
|---|---|
| `intl`, `mbstring` | **wajib** — dituntut CodeIgniter 4 |
| `mysqli` (atau `nd_mysqli`) | **wajib** — driver database |
| `zlib` | dibutuhkan hanya bila berkas XLSX yang diimpor terkompresi |
| `zip` | **tidak diperlukan** |

Impor harga pasar (§14) membaca XLSX **tanpa** `ext-zip`: hosting ini tidak
memilikinya, dan usaha menyalakannya di cPanel berakhir dengan peringatan
*"pdo_mysql, nd_mysqli skipped as conflicting"*. Karena XLSX pada dasarnya ZIP
berisi XML, kontainernya dibaca sendiri oleh `App\Libraries\ZipFileReader`.
Bila `ext-zip` kebetulan ada, ia dipakai; bila tidak, hasilnya sama persis.

> ⚠️ **Jangan mengubah pilihan ekstensi di cPanel hanya demi impor XLSX.**
> Menyalakan `zip` lewat "Select PHP Version" dapat mematikan driver MySQL yang
> sedang dipakai — peringatan *skipped as conflicting* itu menyangkut
> `pdo_mysql` dan `nd_mysqli`, dan aplikasi berhenti jalan tanpa keduanya.

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
- gunakan salinan `index.php` yang me-`require` `app/Config/Paths.php` lewat path
  absolut ke lokasi baru.

Inilah persis yang dilakukan deploy otomatis (§14): berkas
[`deploy/index-docroot.php`](../deploy/index-docroot.php) adalah salinan
`index.php` dengan path absolut, dan `.cpanel.yml` menaruhnya di document root
lalu mengisi path repo yang sebenarnya. Document root subdomain **terpisah** dari
repo, jadi `app/`, `vendor/`, dan `.env` tetap di luar web root.

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
> Karena itu deploy otomatis (§14) tidak menjalankannya. Cache lama dihapus
> aplikasi sendiri setiap kali `VERSION` berubah
> (`app/Libraries/DeploymentRefresh.php`), dan CI4 membangunnya kembali pada
> request berikutnya karena `app/Config/Optimize.php` menyalakan kedua cache.

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

> Untuk menerapkan mekanisme ini pada **proyek baru**, ada panduan agnostik
> yang bisa dipakai ulang: [CPANEL-DEPLOY-PLAYBOOK.md](CPANEL-DEPLOY-PLAYBOOK.md).


Hosting tidak menyediakan SSH maupun terminal, tetapi menyediakan **cPanel Git™
Version Control** (yang membaca `.cpanel.yml`) dan **API token cPanel**. Dua hal
itu cukup untuk membuat setiap push ke `main` langsung ter-deploy: GitHub Actions
menirukan persis dua tombol yang biasanya diklik manual.

### Cara kerjanya

```
git push  ──►  GitHub Actions (.github/workflows/deploy.yml)
                     │
                     │  1. VersionControl/update            = tombol "Update from Remote"
                     │     lalu memastikan commit di server == commit yang di-push
                     │  2. VersionControlDeployment/create  = tombol "Deploy HEAD Commit"
                     │  3. polling .../retrieve             → tunggu sampai selesai
                     ▼
               .cpanel.yml — SATU task, satu baris
                     │
                     ├── cp -Rf public/. → document root subdomain
                     └── cp deploy/index-docroot.php → document root/index.php,
                         lalu sed mengganti __APPROOT__ dengan path repo ($(pwd))
```

Kode aplikasi **tidak** disalin ke document root. Yang berada di bawah web hanya
isi `public/`; `app/`, `vendor/`, `writable/`, dan `.env` tetap tinggal di
`/home/USER/repositories/vestledger`. Penyambungnya `index.php` di document root:
ia bukan `public/index.php` biasa, melainkan salinan
[`deploy/index-docroot.php`](../deploy/index-docroot.php) yang me-`require`
`app/Config/Paths.php` lewat **path absolut**. Path itu tidak di-hardcode —
placeholder `__APPROOT__` diganti `sed` dengan `$(pwd)` saat deploy, karena
cPanel menjalankan task dari root repo.

Mekanisme ini **identik** dengan proyek lain di akun hosting yang sama yang sudah
terbukti berjalan; sengaja dibuat sesederhana mungkin.

> ⚠️ **Task wajib ditulis dalam satu baris.** Task yang dipecah menjadi beberapa
> baris — bentuk yang sah menurut YAML, dan terbaca oleh parser mana pun —
> membuat cPanel **menonaktifkan tombol Deploy tanpa pesan kesalahan apa pun**.
> Gejalanya menyesatkan: tombol disable terlihat seperti masalah izin akun.

> ⚠️ **Document root subdomain HARUS terpisah dari repo.** Arahkan ke
> `/home/USER/public_html/SUBDOMAIN`, **jangan** ke folder `public/` di dalam
> repo. Bila diarahkan ke dalam repo, cPanel menulis handler PHP ke
> `public/.htaccess` (berkas yang di-commit) begitu versi PHP disetel di MultiPHP
> Manager — satu perubahan itu membuat working tree kotor dan memblokir seluruh
> deploy berikutnya, tanpa pernah terlihat sebagai berkas asing.

### Tata letak di server

| Lokasi | Isi | Diperbarui oleh |
|---|---|---|
| `/home/USER/repositories/vestledger` | seluruh kode, hasil clone dari GitHub | `git pull` yang dijalankan cPanel |
| `/home/USER/repositories/vestledger/vendor` | dependency Composer | **manual**, lewat File Manager |
| `/home/USER/repositories/vestledger/.env` | konfigurasi production | **manual**, sekali |
| `/home/USER/public_html/SUBDOMAIN` | isi `public/` + `index.php` docroot | `.cpanel.yml` pada setiap deploy |

### Persiapan satu kali — di hosting

1. **Buat subdomain** dengan document root `/home/USER/public_html/SUBDOMAIN`.

2. **Git Version Control → Create → Clone a Repository:**

   | Kolom | Isi |
   |---|---|
   | Clone URL | `https://x-access-token:<TOKEN_GITHUB>@github.com/achmadani/VestLedger.git` |
   | Repository Path | `/home/USER/repositories/vestledger` |

   `<TOKEN_GITHUB>` adalah fine-grained PAT dengan izin **Contents: Read-only**.
   Token tersimpan di `.git/config` milik server; bila kedaluwarsa, pull berhenti
   bekerja dan workflow gagal pada langkah pertama.

3. **Unggah `vendor/`** ke `/home/USER/repositories/vestledger/vendor` (hasil
   `composer install --no-dev --optimize-autoloader` di lokal; lihat §3). Hapus
   arsipnya setelah di-extract — berkas sisa membuat working tree kotor, dan
   cPanel menolak melakukan pull pada working tree yang kotor.

4. **Buat `.env`** di root repository, isi sesuai §4 dan §5.

5. **Buat API token cPanel:** Security → Manage API Tokens → Create.

6. **Deploy pertama** dari cPanel: Git Version Control → Manage → *Pull or
   Deploy* → **Deploy HEAD Commit**, lalu baca `writable/logs/deploy.log` lewat
   File Manager. Bila tombolnya disable, lihat peringatan satu-baris di atas.

### Persiapan satu kali — di repository

Sunting **satu baris** di [`.cpanel.yml`](../.cpanel.yml), yaitu `DEPLOYPATH` pada
task pertama. Lakukan di mesin lokal lalu commit — jangan lewat File Manager,
karena working tree yang kotor membuat pull gagal.

### Persiapan satu kali — di GitHub

Settings → Secrets and variables → Actions:

| Secret | Contoh isi |
|---|---|
| `CPANEL_HOST` | `https://jurnal.sinau.biz.id` (port 2083 dipakai otomatis) |
| `CPANEL_USER` | nama pengguna cPanel |
| `CPANEL_TOKEN` | token dari langkah 5 |
| `CPANEL_REPO_ROOT` | `/home/USER/repositories/vestledger` |

| Variable (opsional) | Gunanya |
|---|---|
| `CPANEL_PORT` | port API cPanel bila bukan `2083` |
| `SITE_URL` | bila diisi, workflow memeriksa situs membalas 200/302 setelah deploy |

Periksa dengan `gh secret list` **dari dalam folder proyek** — `gh` memilih
repository berdasarkan direktori kerja, dan secret yang mendarat di repository
lain adalah kegagalan yang sunyi.

Setelah itu:

```bash
make release      # naikkan versi, commit, push  →  deploy berjalan sendiri
make deploy       # ulangi deploy tanpa push baru
make deploy-log   # lima jalannya workflow terakhir
```

### Bila tombol "Deploy HEAD Commit" tidak dapat diklik

cPanel menampilkan dua syarat, tanpa memberi tahu yang mana yang tidak
terpenuhi:

> 1. A valid `.cpanel.yml` file exists.
> 2. No uncommitted changes exist on the checked-out branch.

**Syarat 1** dapat diperiksa dari mesin lokal, memakai parser yang sama dengan
cPanel (Perl):

```bash
perl -MYAML::Syck -e 'my $d = YAML::Syck::LoadFile(".cpanel.yml"); printf "%d task\n", scalar @{$d->{deployment}{tasks}};'
```

Selain harus terbaca, ingat aturan satu-baris di atas.

**Syarat 2** adalah penyebab yang jauh lebih sering, dan pesannya tidak menyebut
berkas mana. Yang membuat working tree di server menjadi kotor, berurutan dari
yang paling sering:

- **arsip sisa extract** (`vendor.zip` dan kawan-kawan) serta `__MACOSX/` yang
  ikut terbawa bila zip dibuat di macOS. Keduanya kini masuk `.gitignore`,
  jadi lakukan pull sekali agar aturan itu berlaku di server;
- **perubahan mode berkas.** Menjalankan "Fix Permissions" atau chmod massal di
  File Manager mengubah lima berkas yang di-commit sebagai executable menjadi
  644, dan git membaca itu sebagai perubahan:

  ```
  spark    builds    .githooks/pre-push    bin/bump-version.sh    bin/write-build-info.sh
  ```

  Kembalikan ke **0755** lewat File Manager;
- **menyunting berkas yang di-commit lewat File Manager** — termasuk
  `.cpanel.yml` sendiri. Ini yang paling merepotkan: tanpa shell tidak ada
  `git checkout` untuk memulihkannya. Bila terjadi, cara terbersih adalah
  menghapus repository di Git Version Control lalu clone ulang, kemudian
  mengunggah kembali `vendor/` dan `.env`. Karena itu **seluruh penyesuaian
  dilakukan di mesin lokal lalu di-commit**, tidak pernah langsung di server.

`.env` dan `vendor/` sendiri aman disimpan di dalam folder repository: keduanya
diabaikan git, sehingga tidak pernah membuat working tree kotor.

### Yang perlu diingat

- **Workflow memeriksa commit hasil pull sama dengan commit yang di-push.** Pull
  yang "berhasil" tetapi berhenti di commit lama adalah kegagalan yang paling
  mudah luput: situs tetap melayani versi lama tanpa satu pun pesan error.
- **`vendor/` tidak pernah ikut ter-deploy.** Tidak ada Composer di server.
  Setiap kali `composer.lock` berubah, unggah ulang `vendor/`; workflow memberi
  peringatan di log jalannya bila berkas itu ikut berubah dalam push.
- **Migrasi TIDAK berjalan otomatis.** `.cpanel.yml` sengaja tidak menjalankan
  `spark` — hanya menyalin berkas — agar sesederhana dan seandal mungkin. Migrasi
  skema dijalankan terpisah: taruh SQL-nya di `deploy/*.sql` dan impor lewat
  phpMyAdmin, atau jalankan `spark migrate --all` dari mesin lokal dengan `.env`
  menunjuk database production via Remote MySQL (§6). **Deploy yang membawa
  migrasi baru harus disertai salah satu langkah itu.**
- **`spark optimize` tidak dijalankan** — lihat peringatan di §11. Cache CI4 yang
  basi dihapus aplikasi sendiri pada request pertama setelah `VERSION` berubah
  ([`app/Libraries/DeploymentRefresh.php`](../app/Libraries/DeploymentRefresh.php)),
  sekaligus menulis `writable/build.json`. Selama `VERSION` naik setiap push
  (dijaga hook pre-push), cache tidak pernah menampilkan kode lama.
- **Berkas yang dihapus dari `public/`** tidak ikut terhapus di document root:
  deploy menyalin, tidak menyinkronkan, supaya `cgi-bin/` dan `.well-known/`
  milik hosting tidak ikut hilang. Hapus manual bila memang perlu.

### Bila working tree di server terlanjur kotor

Tanpa shell, tidak ada `git checkout` untuk memulihkan berkas yang berubah di
server, dan menyuntingnya lewat File Manager hanya menambah perubahan. Bila
tombol Deploy tetap disable padahal `.cpanel.yml` valid dan tidak ada arsip sisa,
cara terbersih adalah **memulai ulang clone**:

1. Git Version Control → **Manage** repo → **Remove** (ini hanya menghapus folder
   repo, bukan database maupun document root subdomain).
2. **Clone** ulang dari URL yang sama (§14 langkah 2).
3. Unggah kembali `vendor/` dan `.env` (§14 langkah 3–4).
4. Pastikan document root subdomain menunjuk `public_html/SUBDOMAIN` yang
   **terpisah** (lihat peringatan di atas), bukan ke dalam repo.
5. **Update from Remote** → **Deploy HEAD Commit**.

Clone yang segar selalu bersih, sehingga Deploy langsung menyala.

---

## Update aplikasi berikutnya

Sejak §14 aktif, tidak ada langkah manual:

```bash
make build                 # bila tampilan berubah — aset hasil build ikut di-commit
make release               # naikkan versi, commit, push → deploy otomatis
```

Kecuali satu hal: bila `composer.lock` berubah, unggah ulang `vendor/` ke server
(§14 langkah 3) sebelum atau segera setelah push.

Padanan manualnya di cPanel adalah *Update from Remote* → *Deploy HEAD Commit*,
yang menjalankan `.cpanel.yml` yang sama.
