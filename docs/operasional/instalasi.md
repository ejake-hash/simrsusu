# Instalasi on-premise

Draf, tiket 1.12. Contoh: folder `/srv/karsitara`, pengguna sistem `www-data`, domain `simrs.rs-contoh.invalid`.
Sesuaikan dengan server RS; jangan tulis nilai nyata ke repo.

## 1. Kebutuhan server

**Topologi minimal** (PRD 14.2 baris Infrastruktur: zona aplikasi dan zona basis data dipisah):

| Peran | Isi | Port masuk |
|---|---|---|
| Server aplikasi | nginx, php-fpm, Horizon, cron scheduler, berkas SPA hasil build | 443 (pengguna), 80 (dialihkan ke 443) |
| Server basis data | PostgreSQL 16 | 5432 hanya dari server aplikasi dan server cadangan |
| Valkey (boleh di server aplikasi) | dua instans: sesi + queue, dan cache | 6379, 6380 hanya dari server aplikasi |
| Penyimpanan terpisah | arsip WAL dan cadangan terenkripsi, anchor audit | sesuai alat RS |

**Perangkat lunak** (dari repo):

| Komponen | Versi | Sumber |
|---|---|---|
| PHP | 8.3+ (CI memakai 8.4) | `backend/composer.json`, `.github/workflows/ci.yml` |
| Ekstensi PHP | `pdo_pgsql`, `redis` (phpredis), `sodium`, `openssl`, `mbstring`, `dom`/`libxml`, `fileinfo`, `zlib`, `curl`, `ctype`, `iconv`, `tokenizer`, `pcntl` dan `posix` (Horizon) | `composer.lock` (ext-*), kode `app/` (sodium untuk TTE, gzdecode untuk daftar sandi bocor), PRD 10.1 (phpredis di produksi) |
| Argon2id | PHP harus dibangun dengan dukungan Argon2 (`password_hash` dengan `PASSWORD_ARGON2ID`) | `config/hashing.php` (driver dikunci `argon2id`) |
| Composer | 2.x | — |
| PostgreSQL | 16, dengan ekstensi `pg_trgm` dan `unaccent` | `docker-compose.yml`, `docker/postgres/init/sql/02-database.sql` |
| Valkey | 9.1.2 (tag yang dipakai compose pengembangan) | `docker-compose.yml` |
| Node.js | 24, **hanya untuk build SPA** (boleh di mesin build terpisah) | `.nvmrc` |
| nginx | versi stabil distribusi, dengan modul `realip` bila ada proksi di depannya | — |
| Sinkronisasi waktu | chrony/NTP wajib di server aplikasi: kode MFA TOTP hanya diterima ±1 periode (30 detik) | `App\Modules\Akses\Domain\Totp`, `config/autentikasi.php` |

**Ukuran CPU/RAM/disk:** belum ditetapkan sebagai angka resmi. Uji beban lokal 1.12 ([hasil-uji-beban.md](hasil-uji-beban.md)) hanya
memberi batas bawah yang sangat konservatif (jenuh ±125 permintaan/detik di laptop dengan basis data di VM 2 CPU;
hambatannya belum diketahui), **bukan rumus ukuran server**. RAM per pekerja PHP mengikuti `memory_limit` (256 MB).
Pasang PgBouncer (PRD 14.1.1 butir 8): tanpa itu setiap permintaan membuka koneksi baru ke PostgreSQL. Angka resmi
(CPU, RAM, `pm.max_children`, `max_connections`) ditetapkan dari uji staging dengan pola klik nyata (keputusan no. 9).

**Koneksi keluar** yang dibutuhkan: SMTP RS (email notifikasi), dan bila integrasi dinyalakan, host BPJS/SATUSEHAT di
`config/integrasi.php` (alamat bawaan dari catatan lapangan, **perlu verifikasi** ke dokumen resmi sebelum produksi).
Cek sandi bocor ke HIBP mati bawaan (`AUTENTIKASI_SANDI_CEK_HIBP=false`), jadi server tidak wajib keluar internet.

## 2. Paket sistem (contoh Debian/Ubuntu)

Nama paket berbeda per distribusi; yang penting daftar ekstensi di §1 terpasang untuk **FPM dan CLI**.

```bash
sudo apt install nginx php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-opcache postgresql-client-16 chrony git unzip
php -m | grep -Ei 'pdo_pgsql|redis|sodium|openssl|mbstring|dom|fileinfo|zlib|curl|pcntl|posix'
php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'   # harus bool(true)
```

## 3. PostgreSQL: role, database, skema

Skrip yang sama dengan pengembangan dan CI: `docker/postgres/init/sql/01-role.sql` (role tingkat klaster) dan
`docker/postgres/init/sql/02-database.sql` (hak database, ekstensi, skema domain, hak per skema).
**Jangan** menjalankan `00-init.sh` di produksi: skrip itu juga membuat database `simrs_test` dan memakai sandi bawaan
bila variabel kosong.

| Role | Dipakai untuk | Catatan |
|---|---|---|
| `simrs_migrator` | `php artisan migrate --database=pgsql_migrator` | Pemilik skema dan tabel (DDL). Sandinya hanya dibutuhkan saat migrasi. |
| `simrs_app` | aplikasi (koneksi `pgsql`) | Tanpa DDL. `sistem` DML penuh, `audit` hanya `SELECT, INSERT`, `analitik` hanya `SELECT`, skema domain `SELECT, INSERT, UPDATE`. `statement_timeout = 5s`, `search_path = sistem, public`. |
| `simrs_analitik` | REFRESH materialized view (fase 4) | `NOLOGIN` sampai fase 4; `statement_timeout = 5min`. |
| `simrs_baca` | dasbor/pelaporan (fase 4) | `NOLOGIN` sampai fase 4. |

Jalankan dari salinan repo di mesin yang bisa menjangkau server basis data, sebagai superuser PostgreSQL:

```bash
cd /srv/karsitara
export PGHOST=<host-db> PGPORT=5432 PGUSER=postgres
read -rs -p 'Sandi simrs_migrator: ' SANDI_MIGRATOR; echo
read -rs -p 'Sandi simrs_app: ' SANDI_APP; echo

# 1) Role (aman dijalankan ulang; sandi role yang sudah ada tidak diubah)
psql -v ON_ERROR_STOP=1 -d postgres \
  -v migrator_password="$SANDI_MIGRATOR" -v app_password="$SANDI_APP" \
  -f docker/postgres/init/sql/01-role.sql
unset SANDI_MIGRATOR SANDI_APP

# 2) Database produksi, milik simrs_migrator
psql -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE simrs OWNER simrs_migrator"

# 3) Hak database, ekstensi pg_trgm/unaccent, skema domain, hak per skema (DBNAME otomatis = database tersambung)
psql -v ON_ERROR_STOP=1 -d simrs -f docker/postgres/init/sql/02-database.sql
```

Catatan: nilai `-v` terlihat sesaat di daftar proses mesin itu. Bila tidak dapat diterima, buat role dengan sandi
sementara lalu ganti lewat `psql -d postgres` → `\password simrs_app` dan `\password simrs_migrator`.

Periksa:

```bash
psql -d simrs -c "SELECT rolname, rolcanlogin, rolconfig FROM pg_roles WHERE rolname LIKE 'simrs_%' ORDER BY 1"
# simrs_app harus memuat statement_timeout=5s dan search_path=sistem, public
psql -d simrs -c "SELECT extname FROM pg_extension WHERE extname IN ('pg_trgm','unaccent')"
```

`pg_hba.conf`: `simrs_app` dan `simrs_migrator` hanya dari IP server aplikasi, metode `scram-sha-256`; superuser
`postgres` hanya lokal. Bila basis data di server lain, pasang TLS di PostgreSQL dan isi `DB_SSLMODE=verify-full`
(PRD 14.2: TLS 1.2+ termasuk antar-server). Pengaturan arsip WAL ada di [cadangan-pemulihan.md](cadangan-pemulihan.md).

## 4. Valkey dua instans

PRD 14.1.1 butir 6 dan `docker-compose.yml`. Satu instans untuk keduanya **dilarang**: saat memori penuh, cache bisa
membuang sesi atau pekerjaan di queue.

| Instans | Isi | Pengaturan (dari compose) | Tambahan produksi |
|---|---|---|---|
| `valkey` (6379) | sesi, queue Horizon, rate limit, kunci cache (`lock_connection`) | `--appendonly yes --maxmemory-policy noeviction` | `requirepass`, `bind` ke IP privat, `maxmemory` sesuai RAM + peringatan pemakaian memori (saat penuh, tulis ditolak) |
| `valkey-cache` (6380) | cache aplikasi | `--save "" --appendonly no --maxmemory 256mb --maxmemory-policy allkeys-lru` | `requirepass`, `bind` ke IP privat; `maxmemory` disesuaikan |

Contoh `valkey.conf` instans sesi + queue (paket distribusi atau kontainer `valkey/valkey:9.1.2-alpine`):

```conf
bind <ip-privat-server-aplikasi>
port 6379
requirepass <sandi-valkey-sesi>
appendonly yes
maxmemory <ukuran>
maxmemory-policy noeviction
```

Instans cache: `port 6380`, `save ""`, `appendonly no`, `maxmemory <ukuran>`, `maxmemory-policy allkeys-lru`,
`requirepass <sandi-valkey-cache>`. Isian `.env`: [konfigurasi-env.md §4](konfigurasi-env.md#4-valkey-sesi-queue-cache).

Periksa:

```bash
valkey-cli -p 6379 -a '<sandi>' CONFIG GET maxmemory-policy   # noeviction
valkey-cli -p 6380 -a '<sandi>' CONFIG GET maxmemory-policy   # allkeys-lru
```

## 5. Kode dan `.env`

```bash
sudo mkdir -p /srv/karsitara && sudo chown <admin>:www-data /srv/karsitara
sudo usermod -aG www-data <admin>   # admin perlu menulis bootstrap/cache saat composer install (masuk ulang sesudahnya)
git clone <url-repo> /srv/karsitara && cd /srv/karsitara
git checkout <tag-rilis>

cd backend
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env && chmod 640 .env && sudo chown <admin>:www-data .env
php artisan key:generate          # APP_KEY; simpan salinannya di vault (cadangan-pemulihan.md §4)
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} +
```

**Semua perintah `php artisan` sesudah ini dijalankan sebagai `www-data`** (`sudo -u www-data php artisan ...`). Bila
dijalankan sebagai admin, berkas log harian dan cache konfigurasi bisa dibuat milik admin, lalu php-fpm dan Horizon
gagal menulisnya. Hanya `composer install` yang dijalankan sebagai admin; skrip `package:discover`-nya menulis
`bootstrap/cache/packages.php` dengan ganti-nama berkas, jadi cukup folder `bootstrap/cache` bisa ditulis grup
`www-data`. Pembagian pemilik ini belum diuji di server RS.

`--no-dev` wajib: aplikasi menolak boot di `APP_ENV=production` bila alat debug (Telescope, Debugbar) terpasang
(`App\Core\PemeriksaanProduksi`). Jangan memakai skrip `composer setup`: skrip itu menjalankan `migrate` dengan koneksi
`simrs_app` (pasti gagal) dan `npm` di folder backend.

Isi `.env` menurut [konfigurasi-env.md](konfigurasi-env.md). Buat kunci tambahan:

```bash
php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'   # untuk TTD_KUNCI
php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'   # untuk KREDENSIAL_KUNCI (kunci berbeda)
```

## 6. Migrasi dan cache konfigurasi

Migrasi **selalu** sebagai `simrs_migrator`; `php artisan migrate` biasa gagal karena `simrs_app` tidak boleh DDL.
Sandi migrator sebaiknya **tidak** disimpan di `.env` produksi (ADR 0003: kredensial DDL tidak perlu ada di server
aplikasi saat berjalan). Variabel lingkungan mengalahkan `.env`, tetapi **tidak** bila konfigurasi sudah di-cache;
karena itu `config:clear` dulu.

```bash
cd /srv/karsitara/backend
sudo -u www-data php artisan config:clear
read -rs -p 'Sandi simrs_migrator: ' DB_MIGRATOR_PASSWORD; echo; export DB_MIGRATOR_PASSWORD
sudo -u www-data --preserve-env=DB_MIGRATOR_PASSWORD php artisan migrate --database=pgsql_migrator --force
unset DB_MIGRATOR_PASSWORD

# Partisi bulan ini dan beberapa bulan ke depan (juga dijadwalkan harian; aman dijalankan ulang)
sudo -u www-data php artisan audit:siapkan-partisi
sudo -u www-data php artisan notifikasi:siapkan-partisi
sudo -u www-data php artisan integrasi:siapkan-partisi

# Cache konfigurasi, rute, event, view. Ulangi setiap .env berubah.
sudo -u www-data php artisan optimize
sudo -u www-data php artisan about --only=environment   # APP_ENV production, debug OFF
```

**Jangan** menjalankan `php artisan db:seed` di produksi: `DatabaseSeeder` hanya berisi akun, organisasi, dan TTE
sintetis. Ketiga seeder itu melempar error bila `APP_ENV=production` (`AkunSintetisSeeder`,
`OrganisasiSintetisSeeder`, `TtdSintetisSeeder`).

## 7. Build SPA

Di server aplikasi atau mesin build (Node 24, `.nvmrc`):

```bash
cd /srv/karsitara/frontend
unset VITE_API_TIRUAN          # server tiruan hanya aktif di `vite` dev (main.ts: import.meta.env.DEV); tidak ikut build
npm ci
npm run build                  # vue-tsc -b && vite build → frontend/dist
```

Hasil `frontend/dist` disajikan nginx; `/api` dan `/sanctum` diteruskan ke Laravel. Konfigurasi di
[nginx-php.md](nginx-php.md). Profil dan identitas RS dibaca dari `GET /api/v1/profil-rs`, bukan variabel build.

## 8. Langkah awal instalasi baru

Urutan wajib wizard (FR-54-08; `App\Core\Instalasi\Penyiapan::LANGKAH`, kontrak `GET /api/v1/penyiapan`). Langkah
dikonfirmasi berurutan di `/pengaturan/penyiapan`; langkah 6–7 (`menunggu_fase_2`) dan langkah 8 yang dilewati dihitung
tuntas **untuk urutan konfirmasi**. `siap_melayani` baru terkunci saat langkah 1–6 semuanya `selesai`. Karena langkah 6
selalu `menunggu_fase_2` di fase 1, **`siap_melayani` tetap false di fase 1** dan Beranda menampilkan pita "Instalasi
belum bisa melayani pasien" (PRD 7.54 baris Siap melayani; `PenyiapanTest`). Ini sesuai rancangan, bukan error
pemasangan.

| No | Kode langkah | Isi | Dikerjakan di |
|---|---|---|---|
| 1 | `superadmin` | Dua superadmin dengan MFA; `AUTENTIKASI_MFA_JARINGAN_RS` terisi | Konsol server (§8.1) dan `.env` |
| 2 | `profil_rs` | Nama RS dan logo paket identitas | Pengaturan Sistem → Profil rumah sakit (`/pengaturan/profil-rs`) |
| 3 | `organisasi` | Unit, KSM, poliklinik, bangsal, kamar, TT, kelas rawat | `/master-data/organisasi` |
| 4 | `paket_referensi` | Wajib: wilayah, referensi (kode variabel RME), LOINC, UCUM. ICD-10/ICD-9-CM tidak wajib (K7) | Wizard atau `referensi:muat` (§8.3) |
| 5 | `pegawai` | Pegawai aktif dan izin praktik berlaku | `/sdm/pegawai` |
| 6 | `penjamin_tarif` | Fase 2 (status `menunggu_fase_2`) | — |
| 7 | `formularium` | Fase 2 (status `menunggu_fase_2`) | — |
| 8 | `kredensial_integrasi` | Untuk **ketiga** sistem (`bpjs_vclaim`, `aplicares`, `satusehat`): kredensial lingkungan aktif lengkap dan uji koneksi terakhir di lingkungan itu berhasil (`SyaratPenyiapan`). **Boleh dilewati** dengan alasan | Pengaturan Sistem → Konfigurasi integrasi (`/pengaturan/integrasi`); butuh `KREDENSIAL_KUNCI` dan alamat resmi di `.env` ([konfigurasi-env.md §9](konfigurasi-env.md#9-integrasi-configintegrasiphp)) |
| 9 | `modul_aktif` | Minimal satu modul aktif untuk seluruh RS | Modul 53 dan 54 sudah aktif dari migrasi `2026_10_11_120000_create_inti_modul_aktif_table`; layar modul aktif belum ada, jadi cukup dikonfirmasi |

Status semua langkah: `/pengaturan/penyiapan` (izin `pengaturan.penyiapan.lihat`, konfirmasi
`pengaturan.penyiapan.perbaiki`).

### 8.1 Superadmin pertama dan MFA

1. Isi `AUTENTIKASI_MFA_JARINGAN_RS` dengan CIDR jaringan RS **yang sebenarnya** (bukan loopback). Selama kosong,
   login web ditolak `503 konfigurasi_belum_lengkap`. Dari luar daftar ini MFA selalu wajib, dan akun yang belum punya
   MFA tidak bisa mendaftar MFA dari luar. Setelah ubah `.env`: `php artisan optimize`.
2. Pastikan IP klien terbaca benar (bukan IP proksi), lihat [nginx-php.md §4](nginx-php.md#4-ip-klien-asli-dan-trusted_proxies).
   Bila salah, semua pengguna dianggap dari satu IP: aturan jaringan RS dan rate limit per IP tidak berarti.
3. Buat superadmin untuk **dua orang berbeda** (PRD 9.2.4), masing-masing di depan orangnya:

   ```bash
   cd /srv/karsitara/backend
   sudo -u www-data php artisan akses:superadmin-pertama --nama-pengguna=<nama.pengguna> --nama="<Nama Lengkap>" \
     --nip=<nip-opsional> --email=<email-opsional>
   ```

   Sandi diminta lewat prompt tersembunyi (minimal 12 karakter, dicek ke daftar sandi bocor). URI `otpauth`, kunci
   TOTP, dan 10 kode cadangan **tampil sekali** di terminal: langsung dipindai orangnya, jangan disalin ke tiket,
   chat, atau dokumen. Perintah menolak bila sudah ada 2 superadmin aktif; superadmin berikutnya diberikan dari
   aplikasi. Nama penerbit di aplikasi autentikator dari `AUTENTIKASI_MFA_PENERBIT`.
4. Masuk lewat web dengan tiap akun, pastikan MFA diminta dan diterima.
5. Lupa sandi (tiket 1.14): admin pemegang `pengaturan.akun.perbaiki` mereset di Pengaturan → Akun & akses setelah
   memverifikasi identitas tatap muka; sandi superadmin hanya oleh superadmin lain. Bila **tidak ada superadmin lain**
   yang bisa masuk, reset darurat di server (sandi sementara tampil sekali, akun wajib menggantinya saat masuk, semua
   sesinya diputus, audit `akses.sandi_direset_konsol` dengan pengguna sistem operasi):

   ```bash
   sudo -u www-data php artisan akses:reset-sandi <nama.pengguna> --alasan="<alasan>" --dasar="<no. berita acara>"
   ```

   MFA tidak ikut direset; autentikator hilang = reset MFA oleh superadmin lain.

### 8.2 Profil RS dan paket identitas

Nama, logo, motif, dan warna RS diunggah di `/pengaturan/profil-rs` (disimpan di disk `IDENTITAS_DISK`; batas logo
200 KB, motif 500 KB, foto 2048 KB, `config/instalasi.php`). Paket contoh fiktif di `docs/brand/contoh` hanya untuk
demo dan uji; produksi memakai berkas resmi dari RS.

### 8.3 Paket referensi

Berkas **resmi** dari RS/pemilik (`backend/database/referensi/SUMBER.md`); jangan diisi dari salinan tidak resmi.
ICD-10 dan ICD-9-CM tidak pernah dikemas di repo (K7): dimuat dari berkas yang disediakan RS (mis. ekspor E-Klaim).
Satu pemuatan = satu versi lengkap; kode yang hilang dinonaktifkan, tidak dihapus.

```bash
cd /srv/karsitara/backend
# Pratinjau dulu (tidak menyimpan), lalu jalankan tanpa --pratinjau
sudo -u www-data php artisan referensi:muat wilayah /tmp/<berkas-wilayah>.csv --versi=<versi> --sumber="<asal berkas>" --pratinjau
sudo -u www-data php artisan referensi:muat wilayah /tmp/<berkas-wilayah>.csv --versi=<versi> --sumber="<asal berkas>"
# Sistem lain: referensi | icd10 | icd9cm | loinc | ucum. LOINC wajib --lisensi="<teks atribusi>".
```

Lewat wizard (`POST /api/v1/paket-referensi/{sistem}/muat`) berkas paling besar 10 MB, diunggah dua kali (pratinjau
lalu terapkan); batas PHP dan nginx harus ≥ 12 MB dan batas waktu ≥ 60 detik ([nginx-php.md](nginx-php.md)).
Transaksi pemuatan boleh berjalan sampai 180 detik (`PemuatReferensi`), lebih lama dari batas web 60 detik. Berkas
besar (wilayah, sekitar 90 ribu baris) lebih aman dimuat lewat `referensi:muat` di konsol, yang tidak kena batas web.

## 9. Pemeriksaan akhir sebelum dibuka

```bash
curl -fsS https://simrs.rs-contoh.invalid/api/v1/siap          # {"status":"siap", ...}: basis data + kedua Valkey
sudo -u www-data php artisan horizon:status                     # running
sudo -u www-data php artisan schedule:list                      # 13 jadwal, proses-dan-jadwal.md §3
sudo -u www-data php artisan audit:verifikasi-rantai            # Rantai utuh
psql -h <host-db> -U simrs_app -d simrs -c 'SHOW statement_timeout'   # 5s
```

Lalu jalankan daftar larangan di [konfigurasi-env.md §11](konfigurasi-env.md#11-larangan-produksi) dan pasang
cadangan ([cadangan-pemulihan.md](cadangan-pemulihan.md)) **sebelum** data pasien pertama masuk.

## 10. Pembaruan versi

```bash
cd /srv/karsitara
git fetch --tags && git checkout <tag-rilis-baru>
cd backend
composer install --no-dev --optimize-autoloader --no-interaction
sudo -u www-data php artisan down --retry=60          # opsional bila migrasi berat
sudo -u www-data php artisan config:clear
read -rs -p 'Sandi simrs_migrator: ' DB_MIGRATOR_PASSWORD; echo; export DB_MIGRATOR_PASSWORD
sudo -u www-data --preserve-env=DB_MIGRATOR_PASSWORD \
  php artisan migrate --database=pgsql_migrator --force   # migrasi maju saja di produksi
unset DB_MIGRATOR_PASSWORD
sudo -u www-data php artisan optimize
sudo -u www-data php artisan horizon:terminate        # systemd menyalakan ulang dengan kode baru
sudo systemctl reload php8.4-fpm                      # opcache.validate_timestamps=0
(cd ../frontend && npm ci && npm run build)
sudo -u www-data php artisan up
```

Ambil cadangan logis (cadangan-pemulihan.md §3.2) sebelum migrasi rilis besar.
