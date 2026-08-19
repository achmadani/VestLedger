# Playbook: Deploy CodeIgniter 4 ke cPanel (tanpa SSH) via Git Version Control

Panduan ini **terbukti jalan** dan ditulis agar bisa diterapkan ulang pada
proyek CI4 lain di hosting cPanel yang **tidak menyediakan SSH/terminal**
(mis. Biznet). Dibuat setelah beberapa pendekatan yang salah memakan waktu; tiap
larangan di sini punya sebab konkret, bukan gaya.

> **Untuk agent/asisten yang membaca ini:** ikuti bentuk yang ditulis di sini
> **apa adanya**. Jangan "memperbaiki" jadi lebih pintar — mekanisme paling
> sederhana justru yang paling andal di lingkungan ini. Bagian
> [Kesalahan yang sudah terbukti gagal](#kesalahan-yang-sudah-terbukti-gagal)
> adalah daftar hal yang **sudah dicoba dan gagal** — jangan diulang.

---

## Model mental (baca ini dulu)

Hosting target punya keterbatasan yang menentukan seluruh desain:

| Kemampuan | Ada? | Konsekuensi |
|---|---|---|
| SSH / terminal / web terminal | ❌ | Tidak ada satu pun perintah yang bisa diketik di server |
| Eksekusi berkas `.sh` | ❌ | Seluruh logika deploy harus **inline di `.cpanel.yml`** |
| Composer di server | ❌ | `vendor/` diunggah manual, sekali |
| cPanel Git™ Version Control | ✅ | Bisa **clone**, **pull** (Update from Remote), **deploy** (`.cpanel.yml`) |
| API token cPanel (port 2083) | ✅ | Pull & deploy bisa dipicu dari GitHub Actions |
| Cron Job | kadang | Bisa dipakai untuk migrasi, bila diizinkan |

**Ide inti:** cPanel meng-clone repo ke `/home/USER/repositories/<proyek>`. Saat
"Deploy HEAD Commit" ditekan, cPanel menjalankan task di `.cpanel.yml` **dari
root repo**. Satu-satunya tugas task itu: **menyalin isi `public/` ke document
root subdomain**, dan menaruh `index.php` yang menunjuk balik ke root repo —
sehingga `app/`, `vendor/`, `.env` tetap di luar web root.

Deploy **bukan** `git pull`. Urutannya selalu dua langkah:
1. **Update from Remote** — cPanel `git pull` (memindahkan HEAD repo).
2. **Deploy HEAD Commit** — menjalankan `.cpanel.yml` (menyalin berkas).

---

## Prasyarat di repo

Struktur standar CI4 (appstarter). Yang harus benar:

- `public/index.php` **dibiarkan bentuk standar** — dipakai untuk dev lokal.
- Aset frontend hasil build **ikut di-commit** (`public/assets/...`), supaya
  server tidak butuh Node.js.
- `.env` **tidak** di-commit (`.gitignore` bawaan CI4 sudah benar).
- `vendor/` **tidak** di-commit.

---

## Langkah 1 — Berkas di repo (dibuat sekali, di-commit)

### `deploy/index-docroot.php`

Salinan `public/index.php` dengan **satu** perbedaan: `require` memakai path
**absolut** ke root repo lewat placeholder `__APPROOT__`.

```php
<?php

// Front controller untuk DOCUMENT ROOT (terpisah dari root repo).
// __APPROOT__ diganti path repo oleh .cpanel.yml saat deploy. Jangan edit di server.

use CodeIgniter\Boot;
use Config\Paths;

$minPhpVersion = '8.2';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo "PHP $minPhpVersion+ dibutuhkan. Versi sekarang: " . PHP_VERSION;
    exit(1);
}

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

// Root aplikasi (repo) di LUAR document root — path absolut disisipkan saat deploy.
require '__APPROOT__/app/Config/Paths.php';

$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
```

### `.cpanel.yml`

**Satu task, satu baris.** Ganti hanya `DEPLOYPATH` sesuai subdomain.

```yaml
---
deployment:
  tasks:
    - 'export DEPLOYPATH=/home/USER/public_html/SUBDOMAIN && /bin/mkdir -p "$DEPLOYPATH" && /bin/cp -Rf public/. "$DEPLOYPATH"/ && /bin/cp -f deploy/index-docroot.php "$DEPLOYPATH"/index.php && /bin/sed -i "s#__APPROOT__#$(pwd)#g" "$DEPLOYPATH"/index.php'
```

Yang dilakukan task itu, berurutan dalam satu shell:
1. `mkdir -p` document root (bila belum ada);
2. `cp -Rf public/.` — salin **isi** `public/` (termasuk `.htaccess`) ke docroot;
3. `cp -f deploy/index-docroot.php` → `index.php` docroot (menimpa yang barusan
   tersalin dari `public/index.php`);
4. `sed` mengganti `__APPROOT__` dengan `$(pwd)` = path repo sebenarnya.

### Tambahan `.gitignore`

Cegah working tree server jadi kotor oleh berkas yang muncul di sana:

```gitignore
# Arsip sisa upload vendor lewat File Manager (+ metadata macOS)
/*.zip
/*.tar.gz
/*.tgz
/__MACOSX/

# Dibuat sendiri oleh PHP/cPanel di dalam folder yang dilayani
error_log
.user.ini
```

---

## Langkah 2 — Persiapan di cPanel (sekali)

1. **Buat subdomain**, catat document root: `/home/USER/public_html/SUBDOMAIN`.
   > ⚠️ **Document root WAJIB terpisah dari repo.** Jangan arahkan ke `public/`
   > di dalam folder repo — lihat [kesalahan #2](#kesalahan-yang-sudah-terbukti-gagal).

2. **Git Version Control → Create → Clone a Repository:**
   - Clone URL: `https://x-access-token:<PAT>@github.com/OWNER/REPO.git`
     (PAT fine-grained, izin **Contents: Read-only**).
   - Repository Path: `/home/USER/repositories/<proyek>`.

3. **Unggah `vendor/`** ke dalam folder repo. Di lokal:
   ```bash
   composer install --no-dev --optimize-autoloader
   zip -rq vendor.zip vendor
   ```
   Unggah `vendor.zip` lewat File Manager, **extract, lalu HAPUS arsipnya**
   (arsip sisa membuat tree kotor).

4. **Buat `.env`** di root repo (`CI_ENVIRONMENT = production`, `app.baseURL`,
   kredensial DB, `encryption.key`).

5. **Import skema database** lewat phpMyAdmin (lihat [Migrasi](#migrasi-skema)).

6. **Deploy pertama:** Manage → *Pull or Deploy* → **Update from Remote** →
   **Deploy HEAD Commit**.

7. Buka subdomain di browser. Bila styling hilang → `app.baseURL` salah.

---

## Langkah 3 — Otomasi via GitHub Actions (opsional)

Agar cukup `git push` tanpa membuka cPanel. Meniru dua tombol lewat API cPanel.

**Secret** (Settings → Secrets and variables → Actions) — set **dari dalam folder
repo** (`gh` memilih repo dari direktori kerja; salah folder = secret nyasar):

| Secret | Isi |
|---|---|
| `CPANEL_HOST` | `https://SUBDOMAIN` (tanpa `/` di ujung) |
| `CPANEL_USER` | user cPanel |
| `CPANEL_TOKEN` | API token (Security → Manage API Tokens) |
| `CPANEL_REPO_ROOT` | `/home/USER/repositories/<proyek>` |

`.github/workflows/deploy.yml`:

```yaml
name: Deploy ke hosting
on:
  push: { branches: [main] }
  workflow_dispatch:
concurrency: { group: deploy-hosting, cancel-in-progress: false }
jobs:
  deploy:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - name: Pull lalu deploy lewat API cPanel
        env:
          CPANEL_HOST: ${{ secrets.CPANEL_HOST }}
          CPANEL_PORT: ${{ vars.CPANEL_PORT || '2083' }}
          CPANEL_USER: ${{ secrets.CPANEL_USER }}
          CPANEL_TOKEN: ${{ secrets.CPANEL_TOKEN }}
          REPO_ROOT: ${{ secrets.CPANEL_REPO_ROOT }}
          BRANCH: ${{ github.ref_name }}
          EXPECTED_SHA: ${{ github.sha }}
        run: |
          set -euo pipefail
          for v in CPANEL_HOST CPANEL_USER CPANEL_TOKEN REPO_ROOT; do
            [ -n "${!v}" ] || { echo "::error::Secret $v belum diisi."; exit 1; }
          done
          host="${CPANEL_HOST%/}"; case "$host" in https://*|http://*) ;; *) host="https://$host";; esac
          uapi() { local ep="$1"; shift; local a=(); for kv in "$@"; do a+=(--data-urlencode "$kv"); done
            curl -sS --get --max-time 120 -H "Authorization: cpanel ${CPANEL_USER}:${CPANEL_TOKEN}" \
                 "${host}:${CPANEL_PORT}/execute/${ep}" "${a[@]}"; }
          ok() { jq -e . >/dev/null 2>&1 <<<"$1" || { echo "::error::$2: balasan bukan JSON (cek HOST/PORT/TOKEN)"; echo "$1"|head -c400; exit 1; }
                 [ "$(jq -r '.status//0' <<<"$1")" = 1 ] || { echo "::error::$2 gagal: $(jq -c '.errors//.' <<<"$1")"; exit 1; }; }

          echo "== Update from Remote =="
          b="$(uapi VersionControl/update "repository_root=${REPO_ROOT}" "branch=${BRANCH}")"; ok "$b" update
          got="$(jq -r '.data.last_update.identifier // empty' <<<"$b")"
          [ -z "$got" ] || [ "$got" = "$EXPECTED_SHA" ] || { echo "::error::server di commit $got, bukan $EXPECTED_SHA"; exit 1; }

          echo "== Deploy HEAD Commit =="
          b="$(uapi VersionControlDeployment/create "repository_root=${REPO_ROOT}")"; ok "$b" deploy
          tid="$(jq -r '.data.task_id // .task_id // empty' <<<"$b")"
          [ -n "$tid" ] || { echo "::error::tak ada task_id: $b"; exit 1; }
          for i in $(seq 1 60); do sleep 5
            p="$(uapi VersionControlDeployment/retrieve "repository_root=${REPO_ROOT}")"; ok "$p" retrieve
            t="$(jq -c --arg id "$tid" '.data[]? | select((.task_id|tostring)==$id)' <<<"$p")"
            [ -n "$t" ] || { echo "menunggu antrean..."; continue; }
            [ "$(jq -r '.timestamps.succeeded//empty' <<<"$t")" = "" ] || { echo "Deploy selesai."; exit 0; }
            [ "$(jq -r '.timestamps.failed//empty'    <<<"$t")" = "" ] || { echo "::error::deploy gagal di server"; jq . <<<"$t"; exit 1; }
            echo "deploy berjalan..."
          done
          echo "::error::deploy belum selesai 5 menit"; exit 1
```

> UAPI membalas **HTTP 200 meskipun gagal** — status sebenarnya ada di body
> (`.status`, `.errors`). Selalu periksa body, bukan kode HTTP.

---

## Migrasi skema

`.cpanel.yml` **sengaja tidak menjalankan `spark`** (lihat
[kesalahan #4](#kesalahan-yang-sudah-terbukti-gagal)). Migrasi ditangani terpisah,
pilih salah satu:

- **phpMyAdmin** — simpan SQL di `deploy/*.sql`, impor manual. Paling andal.
- **Remote MySQL** — dari mesin lokal, `.env` menunjuk DB production, lalu
  `php spark migrate --all`. Butuh IP lokal didaftarkan di Remote MySQL cPanel.
- **Cron Job** (bila diizinkan) — cron memakai `/bin/sh`, sering tetap jalan
  walau shell login dimatikan:
  ```
  /usr/local/bin/php /home/USER/repositories/<proyek>/spark migrate --all >> ~/migrate.log 2>&1
  ```

**Deploy yang membawa migrasi baru harus disertai salah satu langkah di atas.**

---

## Cache CI4 setelah deploy

CI4 meng-cache locator/config. Setelah pull, cache lama membuat berkas baru
(view, migrasi, command) **tidak terlihat** — gejalanya menyesatkan (layout
rusak padahal markup benar; `migrate` bilang "complete" tanpa jalan).

Karena tak ada shell untuk `spark cache:clear`, buat aplikasi membersihkannya
sendiri: sebuah kelas yang dipanggil di event `pre_system`, menghapus isi
`writable/cache` **sekali setiap `VERSION` berubah** (dijaga penanda). Contoh
konkret: lihat `app/Libraries/DeploymentRefresh.php` di repo VestLedger.

Prasyaratnya: `VERSION` **naik pada setiap push** (jaga dengan hook `pre-push`).

---

## Checklist verifikasi

- [ ] `.cpanel.yml` valid & satu baris:
      `perl -MYAML::Syck -e 'YAML::Syck::LoadFile(".cpanel.yml")'`
- [ ] Document root subdomain = `public_html/SUBDOMAIN` (terpisah dari repo)
- [ ] `vendor/` terunggah, arsip sudah dihapus
- [ ] `.env` ada, `CI_ENVIRONMENT = production`, `app.baseURL` benar
- [ ] Update from Remote menampilkan HEAD terbaru
- [ ] Deploy HEAD Commit **bisa diklik** (tidak disable)
- [ ] Subdomain buka normal, styling utuh
- [ ] `app/Config/App.php`, `.env`, `writable/logs/` → 403/404 dari web

---

## Kesalahan yang sudah terbukti gagal

Daftar ini adalah pendekatan yang **sudah dicoba dan gagal** di lingkungan ini.
Jangan diulang.

### #1 — Task `.cpanel.yml` ditulis multi-baris
Bentuk berikut **sah menurut YAML** dan terbaca semua parser, tetapi membuat
cPanel **menonaktifkan tombol Deploy tanpa pesan apa pun**:
```yaml
tasks:
  - 'export A=1 &&
     mkdir -p ... &&
     cp ...'
```
Gejalanya menyesatkan — tombol disable terlihat seperti masalah izin akun.
**Aturan: satu task = satu baris.**

### #2 — Document root diarahkan ke `public/` di dalam repo
Terlihat elegan ("pull langsung jadi deploy"), tetapi **fatal**: begitu versi
PHP disetel di MultiPHP Manager, cPanel menulis blok handler ke `.htaccess` di
document root. Bila document root = `public/` repo, yang tertulis adalah
`public/.htaccess` yang **di-commit** → working tree kotor → **semua deploy
berikutnya diblokir**, tanpa pernah terlihat sebagai berkas asing.
**Aturan: document root selalu folder terpisah di `public_html`.**

### #3 — Membuat berkas `.sh` untuk logika deploy
Hosting **memblokir eksekusi `.sh`**. `.cpanel.yml` yang memanggil
`bash deploy.sh` gagal diam-diam. **Aturan: semua perintah inline di
`.cpanel.yml`, dengan path absolut biner (`/bin/cp`, `/bin/sed`, ...).**

### #4 — Menjalankan `php spark optimize` saat deploy
`spark optimize` memanggil `composer install --no-dev` (lihat
`system/Commands/Utilities/Optimize.php`). Di server tanpa Composer → gagal &
deploy merah; di server ber-Composer → **menimpa `vendor/`** yang diunggah
manual. **Aturan: jangan jalankan `spark optimize` di server.**

### #5 — Mengira tombol Deploy disable karena shell access
Shell access **tidak** diperlukan untuk Deploy. Bila tombol disable, sebabnya
salah satu dari dua syarat cPanel: (a) `.cpanel.yml` tidak valid, atau (b)
working tree kotor (#2 di atas, atau arsip/`error_log` sisa). Tooltip "Run the
configured tasks..." hanya teks generik, **bukan** alasan.

### #6 — Menyunting berkas yang di-commit langsung di server
Tanpa shell tidak ada `git checkout` untuk memulihkan. Menyunting `.cpanel.yml`,
`.htaccess`, dll. lewat File Manager mengotori tree permanen.
**Aturan: semua penyesuaian di mesin lokal → commit → deploy.** Bila terlanjur
kotor, satu-satunya jalan bersih: **hapus repo di Git Version Control lalu clone
ulang** (database & document root tidak terhapus), unggah lagi `vendor/` & `.env`.

### #7 — `composer install --no-dev --dry-run` di mesin dev
Pada Composer 2.10 `--dry-run` **tetap menghapus** dependensi dev dari `vendor/`.
Pulihkan dengan `composer install`. Bangun `vendor.zip` production di salinan
terpisah, bukan di working tree dev.

---

## Diagnosa cepat "The system cannot deploy"

cPanel menampilkan dua syarat tanpa menyebut mana yang gagal:

1. **`.cpanel.yml` valid** → uji lokal:
   `perl -MYAML::Syck -e 'YAML::Syck::LoadFile(".cpanel.yml")'` (+ aturan satu baris).
2. **Tidak ada perubahan uncommitted** → ini yang paling sering. Periksa di
   File Manager (Show Hidden Files), di folder repo:
   - arsip sisa: `*.zip`, `__MACOSX/`;
   - `error_log`, `.user.ini` yang dibuat PHP;
   - `public/.htaccess` yang disunting cPanel (akibat #2);
   - perubahan mode berkas (chmod massal / "Fix Permissions" mengubah
     `spark`, `builds`, `bin/*.sh` dari 755 → 644).

   Bila tak yakin, ambil fakta dari API (token cPanel):
   ```bash
   read -rs TOKEN
   curl -sG "https://SUBDOMAIN:2083/execute/VersionControl/retrieve" \
     --data-urlencode "repository_root=/home/USER/repositories/PROYEK" \
     -H "Authorization: cpanel USER:$TOKEN" | jq .
   ```

   Jika kotor dan tak terlacak: **clone ulang** (lihat #6).
