# Status Proyek — Mulai Baca dari Sini

Dokumen ini adalah titik masuk bagi siapa pun (atau sesi kerja baru) yang
melanjutkan VestLedger. Isinya keadaan terkini, cara menjalankan, jebakan yang
sudah pernah memakan waktu, dan apa yang belum selesai.

**Terakhir diperbarui:** 19 Agustus 2026 · versi `v0.9.0`

---

## 1. Keadaan terkini

| | |
|---|---|
| Versi | `v0.9.0` |
| Test | 292 test, 1.126 assertion, seluruhnya lulus (1 di-skip, lihat §6) |
| Phase 1–9 | selesai semua |
| Menu nonaktif | tidak ada |
| Repository | `git@github.com:achmadani/VestLedger.git`, branch `main` |

Aplikasi sudah dapat dipakai penuh: master data, transaksi, jurnal double-entry,
portofolio, seluruh laporan keuangan, saldo awal, dan pengelolaan pengguna.

Riwayat perubahan lengkap ada di [CHANGELOG.md](../CHANGELOG.md).
Aturan akuntansi yang mengikat seluruh kode ada di [ACCOUNTING.md](ACCOUNTING.md).

---

## 2. Menjalankan di mesin ini

```bash
make setup     # composer + npm + git hooks + build aset + migrasi
make seed      # master data awal (idempoten)
make import-stocks   # 964 emiten IDX
make user-create NAME=bambang EMAIL=bambang@example.com
make serve     # http://localhost:8123
```

Ada **dua cara** aplikasi ini dilayani di mesin pengembang:

- **`https://vestledger.test`** — vhost Apache milik pengguna, terdaftar di
  `/etc/hosts`. Inilah yang dipakai sehari-hari, dan `app.baseURL` di `.env`
  menunjuk ke sini.
- **`http://localhost:8123`** — `make serve` (`spark serve`), dipakai untuk
  pemeriksaan cepat. Port 8080 **tidak bisa dipakai**: sudah ditempati Apache.

Bila `app.baseURL` dan port yang benar-benar melayani tidak cocok, seluruh URL
aset menjadi salah dan halaman tampak tanpa gaya sama sekali.

---

## 3. Prasyarat mesin

| Kebutuhan | Di mesin ini |
|---|---|
| PHP 8.3 | `php83`, atau `/opt/homebrew/opt/php@8.3/bin/php`. **`php` di PATH adalah PHP 5.6** |
| MySQL 8.4 | container Podman `mysql8-podman`, port **3308**, user `root` |
| Composer | `/opt/homebrew/bin/composer` (jalankan dengan PATH PHP 8.3 di depan) |
| Node 20 | lewat nvm, **hanya** untuk build aset |

Seluruh target `Makefile` sudah memakai path PHP 8.3 secara eksplisit, sehingga
cukup memakai `make` dan tidak perlu memikirkan hal ini.

---

## 4. Jebakan yang sudah pernah memakan waktu

Empat hal berikut pernah benar-benar menghabiskan waktu di proyek ini. Semuanya
memberi gejala yang menyesatkan.

### `php spark optimize` menyembunyikan berkas baru

`optimize` menulis `writable/cache/FileLocatorCache`. Selama cache itu ada,
**migrasi dan perintah spark yang baru dibuat tidak terlihat** — `spark migrate`
melaporkan "Migrations complete" tanpa menjalankan apa pun.

```bash
rm -f writable/cache/FileLocatorCache
```

### `composer install --no-dev` menghapus PHPUnit, bahkan dengan `--dry-run`

Pada Composer 2.10, `--dry-run` tetap menghapus dependensi pengembangan dari
`vendor/`. Jangan menjalankannya di mesin pengembang; pulihkan dengan
`composer install`.

### `FeatureTestTrait` mengubah isi respons

Body respons pada feature test kehilangan atribut Alpine `@event` dan mengubah
`&` menjadi `&amp;`. Output server sungguhan **tidak** terpengaruh — ini sudah
diverifikasi langsung lewat curl. Karena itu jangan menulis assertion terhadap
atribut `@click`/`@input`; uji perilaku interaktif lewat browser.

Titik akhir JSON juga dibungkus kerangka HTML oleh test harness.

### Class Tailwind baru memerlukan build ulang

Utility yang belum pernah dipakai di `app/Views` tidak ada di bundle CSS sampai
`make build` dijalankan, dan gejalanya menyesatkan: layout tampak rusak padahal
markup benar. `make serve` sudah build lebih dulu; gunakan `make dev` saat aktif
mengubah tampilan.

---

## 5. Alur rilis

Versi **wajib naik pada setiap push** — dijaga hook `.githooks/pre-push` yang
menolak push bila `VERSION` sama dengan yang ada di remote.

```bash
make release                 # naikkan patch, commit, push
make release PART=minor      # untuk pekerjaan besar
```

`writable/build.json` tidak di-commit; ia dihasilkan saat build/deploy sehingga
menunjuk commit yang benar-benar ter-deploy.

Setiap push ke `main` **men-deploy sendiri** ke hosting: GitHub Actions memanggil
API cPanel untuk menarik commit baru — sambil memastikan commit di server memang
commit yang di-push — lalu menjalankan `.cpanel.yml`, yang menyalin `public/` ke
document root, membersihkan cache, dan menjalankan migrasi. Rinciannya, termasuk
tiga hal yang disiapkan manual sekali di server (`vendor/`, `.env`, dan token
clone GitHub), ada di
[DEPLOYMENT.md §14](DEPLOYMENT.md#14-deploy-otomatis-setiap-git-push). Panduan yang bisa
dipakai ulang untuk proyek baru: [CPANEL-DEPLOY-PLAYBOOK.md](CPANEL-DEPLOY-PLAYBOOK.md).

```bash
make deploy       # ulangi deploy tanpa push baru
make deploy-log   # riwayat jalannya workflow
```

Dua jebakan yang sudah memakan waktu di sini, keduanya tercatat di §14: **task
`.cpanel.yml` wajib satu baris** (bila dipecah, cPanel menonaktifkan tombol
Deploy tanpa pesan apa pun), dan **`vendor/` tidak pernah ikut ter-deploy** —
unggah ulang lewat File Manager setiap kali `composer.lock` berubah; workflow
memperingatkan bila itu terjadi.

---

## 6. Catatan tentang test

- `make test` menjalankan seluruh suite (~57 detik).
- Database test terpisah: `vestledger_test`.
- **Satu test berstatus skipped**, dan itu benar: test "menu belum dibangun
  tidak boleh jadi link" membaca konfigurasi menu, dan kini seluruh menu sudah
  aktif sehingga tidak ada yang perlu diuji. Ia akan hidup kembali sendiri
  begitu ada menu baru yang dinonaktifkan.
- Tabel `users` **tidak** ikut dikosongkan antar test, jadi nama pengguna di
  test harus dibuat unik. Shield hanya mengizinkan huruf, angka, dan titik pada
  username — tanda hubung dan garis bawah ditolak.
- Transaksi beli/jual di test kini **wajib menyebut biaya secara eksplisit**
  bila angkanya diuji ketat, karena tanpa itu biaya diisi otomatis dari tarif
  sekuritas.

---

## 7. Yang belum selesai

### Menunggu tindakan pengguna

- **Kredensial Google OAuth.** Alurnya sudah jadi dan teruji, tetapi tombol
  "Masuk dengan Google" baru muncul setelah `.env` diisi:

  ```ini
  googleauth.clientId = '....apps.googleusercontent.com'
  googleauth.clientSecret = '...'
  ```

  Dibuat di Google Cloud Console → APIs & Services → Credentials → OAuth client
  ID (jenis *Web application*), dengan Authorized redirect URI persis
  `https://vestledger.test/auth/google/callback`.

- **Berkas session yang terlanjur ter-push.** Bug `session.savePath = null`
  sudah diperbaiki di v0.5.x, tetapi 16 berkas session masih ada di riwayat git.
  Isinya sesi lokal, risikonya rendah. Membersihkannya memerlukan rewrite
  history dan force push — keputusan pemilik repository.

### Belum dikerjakan

- Belum ada **jurnal penutup** akhir tahun. Neraca menyajikan laba/rugi berjalan
  sebagai baris ekuitas tersendiri, sehingga persamaan neraca tetap terpenuhi
  tanpa jurnal penutup. Bila kelak diinginkan, ia menutup akun nominal ke 3100.
- **Corporate action** (stock split, dividen saham, right issue) belum ada.
  Saat ini hanya dapat dicatat lewat penyesuaian manual.
- Laporan tahunan memakan ~164 ms pada volume lima tahun. Wajar untuk aplikasi
  personal; biayanya inheren pada tiga belas potret bulanan.

---

## 8. Keputusan yang sudah diambil, jangan diubah tanpa alasan

Rinciannya di [ACCOUNTING.md](ACCOUNTING.md). Ringkasnya:

| Keputusan | Alasan singkat |
|---|---|
| Uang memakai bilangan bulat sen, bukan float | `0.1 + 0.2 !== 0.3`; selisih satu sen membuat jurnal tidak balance |
| Average cost **tidak disimpan** | Diturunkan dari `book_value / quantity`; menyimpannya menimbulkan drift pembulatan |
| Book value dilepas **proporsional** saat jual | Jual habis tidak menyisakan book value mengambang |
| `JournalPoster` menolak jalan di luar DB transaction | Mencegah transaksi tersimpan tanpa jurnal |
| Kas tidak dipecah per sekuritas di CoA | Dipakai dimensi pada baris jurnal, agar CoA tidak membengkak |
| Fee broker dihitung sebagai **sisa** dari tarif all-in | Jumlah komponen selalu persis sama dengan konfirmasi broker |
| Kas negatif **tidak diblokir** | Aplikasi ini untuk pencatatan; transaksi kerap dimasukkan mundur |
| Posisi tanpa harga pasar **tidak** dianggap unrealized nol | Yang benar adalah "belum diketahui" |
| Harga nol di berkas IDX dilewati, bukan disimpan | Nol berarti saham disuspensi; menyimpannya membuat nilai pasar anjlok |
| XLSX dibaca sendiri, bukan dengan PhpSpreadsheet | ~5 MB dependency untuk satu sheet berformat tetap; lihat §34 |
| Grafik memakai nilai buku, bukan market value | Harga historis belum tentu ada; memakai harga terbaru membuat grafik masa lalu berubah |

---

## 9. Memeriksa kesehatan

```bash
make health
```

Memeriksa akun inti, debit = kredit, **setiap jurnal satu per satu**, kecocokan
akun 1100 dengan total posisi, letak berkas session, dan environment.

Bila posisi menyimpang dari buku besar: `make rebuild` — membangun ulang posisi
dari transaksi tanpa menyentuh buku besar.
