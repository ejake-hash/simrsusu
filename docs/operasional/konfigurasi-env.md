# Konfigurasi `.env` backend

Draf, tiket 1.12. Sumber: `backend/.env.example` dan pemanggilan `env(...)` di `backend/config/*.php` (`main` per
12 Okt 2026, sesudah 1.9-fe). Variabel bertanda *sejak 1.5, menunggu merge* baru ada di cabang `feat/akses-lapis45`.
Frontend tidak membaca variabel saat produksi (profil RS dari `GET /api/v1/profil-rs`; `VITE_API_TIRUAN` hanya
berlaku di mode dev).

Aturan umum:

- `.env` produksi hanya ada di server (`chmod 640`, pemilik admin, grup `www-data`), **tidak pernah** di repo, tiket,
  atau chat. Salinan rahasia (kunci, sandi) disimpan di vault RS (PRD 14.2).
- Konfigurasi di-cache (`php artisan optimize`). Setelah mengubah `.env`: `php artisan optimize`, lalu
  `php artisan horizon:terminate` dan `systemctl reload php8.4-fpm`.
- Variabel lingkungan proses mengalahkan `.env`, kecuali bila konfigurasi sudah di-cache.

Kolom **Produksi**: nilai yang diharapkan; `<...>` = isian per RS; "bawaan" = biarkan kosong/tidak ditulis.

## 1. Aplikasi

| Variabel | Produksi | Catatan |
|---|---|---|
| `APP_NAME` | `"Karsitara SIMRS"` (bawaan) | Nama produk, bukan nama RS (nama RS dari Profil RS). **Jangan diubah setelah berjalan**: nama cookie sesi, prefiks kunci Valkey, cache, dan Horizon diturunkan dari `APP_NAME` (`config/session.php`, `database.php`, `cache.php`, `horizon.php`); mengubahnya memutus semua sesi dan meninggalkan pekerjaan di queue lama. |
| `APP_ENV` | `production` | Mengaktifkan pemeriksaan boot (`App\Core\PemeriksaanProduksi`), jumlah pekerja Horizon `production`, dan menonaktifkan simulator integrasi. |
| `APP_KEY` | `base64:<...>` dari `php artisan key:generate` | Mengenkripsi rahasia TOTP pengguna (`akses.pengguna.mfa_rahasia_enkripsi`), NIK pegawai (`sdm.pegawai.nik_enkripsi`), rahasia TOTP sementara selama pendaftaran MFA (`MfaChallenge`), dan cookie; juga kunci HMAC sidik NIK (`Pegawai::sidik`). **Hilang = semua MFA dan NIK tidak terbaca.** Simpan di vault. **Jangan dirotasi** sampai ada perintah hitung ulang sidik NIK (utang 1.8d): `APP_PREVIOUS_KEYS` membuka data lama, tetapi sidik NIK dihitung ulang dengan kunci baru sehingga keunikan NIK tidak lagi cocok. |
| `APP_PREVIOUS_KEYS` | kosong | Tidak ada di `.env.example`. Hanya untuk masa rotasi `APP_KEY` (lihat atas). |
| `APP_DEBUG` | `false` | `true` di production = aplikasi menolak boot. |
| `APP_URL` | `https://simrs.rs-contoh.invalid` | Dipakai untuk tautan (mis. email notifikasi). |
| `APP_LOCALE` | `id` | |
| `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | bawaan | |
| `APP_MAINTENANCE_DRIVER` | `file` (satu server); `cache` bila lebih dari satu server aplikasi | `APP_MAINTENANCE_STORE` untuk driver `cache`. |
| `BCRYPT_ROUNDS` | bawaan | Tidak dipakai: hash sandi dikunci Argon2id (`config/hashing.php`). |
| `ARGON_MEMORY`, `ARGON_THREADS`, `ARGON_TIME` | bawaan (65536, 1, 4) | Tidak ada di `.env.example`. Naikkan hanya setelah diukur. |

## 2. Log

| Variabel | Produksi | Catatan |
|---|---|---|
| `LOG_CHANNEL` | `stack` | Semua channel diberi penyamaran (`App\Core\Log\InstallMasking`). |
| `LOG_STACK` | `daily` | `.env.example` memakai `single` (satu berkas tumbuh terus). |
| `LOG_DAILY_DAYS` | `<hari>` (bawaan 14) | Tidak ada di `.env.example`. Kirim ke log terpusat sebelum terhapus. |
| `LOG_LEVEL` | `info` atau `warning` | **Bukan** `debug`. |
| `LOG_DEPRECATIONS_CHANNEL` | `null` | |
| `AUDIT_ANCHOR_LOG` | `<jalur>`; bawaan `storage/logs/audit-anchor.log` | Tidak ada di `.env.example`. Channel `audit_anchor` terpisah dari log aplikasi; teruskan ke SIEM (ADR 0003). |

## 3. Basis data

| Variabel | Produksi | Catatan |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | |
| `DB_HOST`, `DB_PORT` | `<host-db>`, `5432` | `.env.example` memakai 5433 (port compose pengembangan). |
| `DB_DATABASE` | `simrs` | |
| `DB_USERNAME` | `simrs_app` | Role aplikasi tanpa DDL. |
| `DB_PASSWORD` | `<sandi simrs_app>` | Vault. |
| `DB_SEARCH_PATH` | `sistem,public` | Tabel bawaan Laravel di skema `sistem`. |
| `DB_CONNECT_TIMEOUT` | `5` (bawaan) | Detik menunggu koneksi PostgreSQL terbuka (`PDO::ATTR_TIMEOUT` = `connect_timeout` libpq). Tanpa opsi ini pdo_pgsql menunggu 30 detik saat basis data tidak menjawab ([hasil-uji-beban.md](hasil-uji-beban.md)). Sejak 1.12. |
| `DB_SSLMODE` | `verify-full` bila DB di server lain (bawaan `prefer`) | Tidak ada di `.env.example`. PRD 14.2: TLS antar-server. `config/database.php` tidak punya opsi `sslrootcert`, jadi libpq mencari sertifikat CA di `~/.postgresql/root.crt` milik pengguna proses (`www-data`, juga untuk Horizon dan cron). **Belum diuji**; lihat keputusan no. 8 di [README](README.md#keputusan-yang-perlu-pemilik-repo). |
| `DB_MIGRATOR_USERNAME` | `simrs_migrator` | Koneksi `pgsql_migrator`. |
| `DB_MIGRATOR_PASSWORD` | **tidak ditulis** di `.env` produksi | Diberikan sebagai variabel lingkungan saat migrasi saja ([instalasi.md §6](instalasi.md#6-migrasi-dan-cache-konfigurasi)). |
| `DB_URL`, `DB_CHARSET` | bawaan | |

## 4. Valkey (sesi, queue, cache)

Dua instans wajib (PRD 14.1.1 butir 6). Koneksi `default` = instans sesi + queue + rate limit + kunci cache
(`noeviction`); koneksi `cache` = instans cache (`allkeys-lru`). `config/database.php`, `config/cache.php`.

| Variabel | Produksi | Catatan |
|---|---|---|
| `REDIS_CLIENT` | `phpredis` | `.env.example` memakai `predis` (lokal). PRD 10.1: phpredis di produksi; butuh ekstensi `redis`. |
| `REDIS_HOST`, `REDIS_PORT` | `<host>`, `6379` | Instans `noeviction`. |
| `REDIS_PASSWORD` | `<sandi-valkey-sesi>` | `.env.example` berisi `null`. |
| `REDIS_USERNAME` | kosong, atau pengguna ACL | Tidak ada di `.env.example`. |
| `REDIS_DB` | `0` | |
| `REDIS_CACHE_HOST`, `REDIS_CACHE_PORT` | `<host>`, `6380` | Instans `allkeys-lru`. **Jangan** diarahkan ke instans sesi. |
| `REDIS_CACHE_PASSWORD` | `<sandi-valkey-cache>` | Bila kosong, memakai `REDIS_PASSWORD`. |
| `REDIS_CACHE_USERNAME` | kosong, atau pengguna ACL | Tidak ada di `.env.example`. Bila kosong, memakai `REDIS_USERNAME`. |
| `REDIS_CACHE_DB` | `1` (bawaan) | |
| `SESSION_DRIVER` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | Queue per jenis di `App\Core\Queue\JenisQueue`. |
| `CACHE_STORE` | `redis` | |
| `REDIS_PREFIX`, `CACHE_PREFIX`, `HORIZON_PREFIX` | bawaan (turunan `APP_NAME`) | `REDIS_PREFIX` dan `HORIZON_PREFIX` tidak ada di `.env.example`. Isi eksplisit hanya bila dua instalasi berbagi Valkey (tidak disarankan). |
| `REDIS_QUEUE_RETRY_AFTER` | bawaan 150 | Tidak ada di `.env.example`. Harus > batas waktu terpanjang di koneksi `redis` (`dokumen` 120 detik, `JenisQueue::batasWaktu`). Koneksi `redis-laporan` memakai 960 tetap (batas `laporan` 900 detik). |
| `CACHE_LIMITER_STORE`, `REDIS_CACHE_CONNECTION`, `REDIS_CACHE_LOCK_CONNECTION`, `REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE` | bawaan; **jangan diubah** | Tidak ada di `.env.example`. Bawaan: penghitung rate limit dan kunci nama pengguna fiktif di store `rate-limit` (instans `noeviction`), cache di koneksi `cache`, kunci cache dan queue di koneksi `default` (`config/cache.php`, `config/queue.php`). Mengarahkannya ke instans cache membuat rate limit dan penguncian akun bisa hilang saat memori penuh. |
| `REDIS_PERSISTENT`, `REDIS_MAX_RETRIES`, `REDIS_BACKOFF_*`, `REDIS_CLUSTER`, `REDIS_URL`, `REDIS_CACHE_URL` | bawaan | Tidak ada di `.env.example`. |

## 5. Sesi dan SPA

| Variabel | Produksi | Catatan |
|---|---|---|
| `SESSION_LIFETIME` | `120` | Harus lebih besar dari batas diam terpanjang (60 menit), ADR 0002. |
| `SESSION_SECURE_COOKIE` | `true` (bawaan di luar `local`) | `false` = aplikasi menolak boot. Tidak ada sebagai baris aktif di `.env.example`. |
| `SESSION_SAME_SITE` | `strict` | PRD 14.2; `SesiSpaStateful` menjaga tetap `strict`. |
| `SESSION_HTTP_ONLY` | `true` (bawaan) | |
| `SESSION_DOMAIN` | `null` (cookie host-only) | |
| `SESSION_PATH` | `/` | |
| `SESSION_ENCRYPT` | `false` (bawaan) | Opsional `true`. |
| `SANCTUM_STATEFUL_DOMAINS` | `simrs.rs-contoh.invalid` | Host SPA (tanpa skema). Permintaan dari host ini mendapat sesi cookie. |
| `CORS_ALLOWED_ORIGINS` | `https://simrs.rs-contoh.invalid` | SPA satu origin dengan API; jangan `*`. |
| `SANCTUM_TOKEN_PREFIX` | bawaan | Token Sanctum hanya untuk integrasi mesin-ke-mesin. |

## 6. Jaringan, autentikasi, rate limit

| Variabel | Produksi | Catatan |
|---|---|---|
| `TRUSTED_PROXIES` | IP/CIDR proksi di depan aplikasi, atau kosong | Lihat [nginx-php.md §4](nginx-php.md#4-ip-klien-asli-dan-trusted_proxies). **Jangan** `*` (klien bisa memalsukan IP). |
| `AUTENTIKASI_MFA_JARINGAN_RS` | `<cidr-jaringan-rs-1>,<cidr-2>` | **Wajib.** Kosong = login web `503 konfigurasi_belum_lengkap`. Nilai `.env.example` (loopback) hanya untuk lokal/CI. |
| `AUTENTIKASI_MFA_PENERBIT` | `"<nama RS>"` | Nama di aplikasi autentikator; bawaan `APP_NAME`. |
| `AUTENTIKASI_MFA_CHALLENGE_MENIT` | bawaan 5 | Tidak ada di `.env.example`. |
| `AUTENTIKASI_MFA_ULANG_MENIT` | bawaan 15 | *Sejak 1.5, menunggu merge* (`config/autentikasi.php` di `feat/akses-lapis45`). Izin bertanda `butuh_mfa` meminta MFA ulang bila verifikasi MFA terakhir di sesi itu lebih lama dari nilai ini (K11). |
| `AKSES_LABEL_MAKS_HARI` | bawaan 365 | *Sejak 1.5, menunggu merge* (`config/akses.php` baru di `feat/akses-lapis45`). Masa berlaku terpanjang izin label sensitivitas per akun (lapis 4); izin itu selalu berbatas waktu. |
| `AUTENTIKASI_SANDI_PANJANG_MINIMAL` | bawaan 12 | Tidak ada di `.env.example`. PRD 14.2. Nilai di bawah 12 diabaikan (`Password::min(max(12, ...))` di `Akses\ModuleServiceProvider`). |
| `AUTENTIKASI_SANDI_DAFTAR_BOCOR` | bawaan (`app/Modules/Akses/Infrastructure/data/sandi-bocor.txt.gz`) | Tidak ada di `.env.example`. Perbarui berkala (ADR 0001, `SUMBER.md`). |
| `AUTENTIKASI_SANDI_CEK_HIBP` | `false` | `true` hanya bila server boleh keluar internet. |
| `AUTENTIKASI_KUNCI_MAKS_GAGAL`, `AUTENTIKASI_KUNCI_DURASI_MENIT` | bawaan 5, 15 | PRD 14.2. |
| `AUTENTIKASI_BATAS_PER_IP_PER_MENIT`, `AUTENTIKASI_BATAS_PER_NAMA_PENGGUNA_PER_MENIT` | bawaan 60, 10 | Batas masuk. |
| `AUTENTIKASI_BATAS_DIAM_MENIT`, `AUTENTIKASI_BATAS_DIAM_PERANGKAT_TERDAFTAR_MENIT` | bawaan 15, 60 | ADR 0002. |
| `RATE_LIMIT_API_PER_PENGGUNA_PER_MENIT`, `RATE_LIMIT_API_PER_IP_PER_MENIT` | bawaan 120, 120 | Semua rute `api/v1`. |
| `RATE_LIMIT_LAYAR_KERJA_PER_PENGGUNA_PER_MENIT` | bawaan 600 | |
| `RATE_LIMIT_SIAP_PER_IP_PER_MENIT` | bawaan 60 | `GET /api/v1/siap`; atur interval pemantau di bawah ini. |

Melonggarkan angka keamanan di atas (MFA, penguncian, rate limit) adalah keputusan pemilik produk/RS, bukan operator.

## 7. Tanda tangan elektronik (`config/ttd.php`)

| Variabel | Produksi | Catatan |
|---|---|---|
| `TTD_KUNCI` | `base64:<32 bita>` | Lada PIN TTE; buat dengan `php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`. Vault, tidak pernah di DB. Kosong = TTE tidak bisa diaktifkan. |
| `TTD_KUNCI_LAMA` | kosong; saat rotasi berisi kunci lama (dipisah koma) | Dipertahankan sampai semua PIN diganti. |
| `TTD_KDF` | `interactive` (bawaan) | Tidak ada di `.env.example`. **`minimum` hanya untuk tes.** |
| `TTD_MAKS_PIN_SALAH`, `TTD_KUNCI_MENIT`, `TTD_SESI_MENIT` | bawaan 5, 15, 15 | Angka PRD 7.57.2, *perlu verifikasi* kebijakan RS. |

## 8. Berkas dan audit (disk)

Disk yang tersedia di `config/filesystems.php`: `local` (`storage/app/private`), `public`, `s3`. Disk `s3` **belum bisa
dipakai**: adaptor `league/flysystem-aws-s3-v3` tidak ada di `composer.json` (lihat [README](README.md)). Sampai itu
semua berkas memakai disk `local`; pisahkan penyimpanannya dengan mount.

| Variabel | Produksi | Catatan |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | |
| `IDENTITAS_DISK` | `local` (sementara) | Logo, motif, foto paket identitas. PRD 10.6: S3/MinIO. |
| `IDENTITAS_BATAS_LOGO_KB`, `_MOTIF_KB`, `_FOTO_KB` | bawaan 200, 500, 2048 | |
| `SDM_DOKUMEN_DISK` | `local` | Dokumen izin praktik (privat). |
| `SDM_DOKUMEN_BATAS_KB` | bawaan 5120 | |
| `SDM_IZIN_PRAKTIK_HARI_PERINGATAN` | bawaan 60 | *Perlu verifikasi* kebijakan RS. |
| `AUDIT_ANCHOR_DISK` | `local` + mount terpisah (sementara) | Anchor harus di penyimpanan yang **tidak bisa ditimpa** admin basis data/server aplikasi (ADR 0003). Rincian dan batasannya di [cadangan-pemulihan.md §6](cadangan-pemulihan.md#6-anchor-audit-di-penyimpanan-terpisah). |
| `AUDIT_VERIFIKASI_JENDELA` | `50000` (bawaan) | Baris per query `audit:verifikasi-rantai`; tiap jendela harus selesai di bawah `statement_timeout` 5 detik. Uji 1.12: ±38 ribu baris/detik. Sejak 1.12. |
| `AUDIT_ANCHOR_DIREKTORI` | `audit/anchor` (bawaan) | Relatif terhadap akar disk: `storage/app/private/audit/anchor`. Mount penyimpanan terpisah tepat di folder ini. |
| `AUDIT_ZONA_WAKTU` | `Asia/Jakarta` (bawaan) | Zona `--dari/--sampai` verifikasi dan jadwal partisi. |
| `AUDIT_PARTISI_BULAN_KE_DEPAN` | bawaan 3 | |
| `AWS_*` | kosong | Baru dipakai saat disk S3/MinIO tersedia. |

Contoh mount anchor (penyimpanan dari server lain; jenis dan opsi mengikuti alat RS):

```bash
sudo mkdir -p /srv/karsitara/backend/storage/app/private/audit/anchor
# /etc/fstab: <server-arsip>:/ekspor/anchor-audit  /srv/karsitara/backend/storage/app/private/audit/anchor  nfs  rw,noexec,nosuid  0 0
sudo mount /srv/karsitara/backend/storage/app/private/audit/anchor
sudo chown www-data:www-data /srv/karsitara/backend/storage/app/private/audit/anchor
sudo -u www-data php artisan audit:ekspor-anchor   # uji tulis; berkas JSON Lines per bulan muncul di folder itu
ls -l /srv/karsitara/backend/storage/app/private/audit/anchor/   # anchor-rantai-YYYY-MM.jsonl harus ada
```

Perhatikan: disk `local` diset `'throw' => false` (`config/filesystems.php`). Bila mount tidak bisa ditulis,
`audit:ekspor-anchor` tetap selesai sukses tanpa menulis berkas (salinan di log `audit_anchor` tetap ditulis). Bila
mount terlepas, berkas ditulis ke folder di disk server aplikasi. Karena itu umur baris terakhir berkas anchor dan
status mount ikut dipantau ([proses-dan-jadwal.md §6](proses-dan-jadwal.md#6-pemantauan-dasar)).

## 9. Integrasi (`config/integrasi.php`)

| Variabel | Produksi | Catatan |
|---|---|---|
| `KREDENSIAL_KUNCI` | `base64:<32 bita>`, **berbeda** dari `APP_KEY` dan `TTD_KUNCI` | Mengenkripsi kredensial BPJS/SATUSEHAT (K9). Kosong = kredensial tidak bisa disimpan/dibaca (`503 kredensial_kunci_belum_dikonfigurasi`). Vault. |
| `KREDENSIAL_KUNCI_LAMA` | kosong; saat rotasi berisi kunci lama (dipisah koma) | Rotasi: [proses-dan-jadwal.md §5](proses-dan-jadwal.md#5-rotasi-kunci). |
| `INTEGRASI_SIMULATOR` | `false` | Kode mengabaikannya di production, tetapi tetap tulis `false`. |
| `INTEGRASI_VCLAIM_SANDBOX`, `_PRODUKSI` | bawaan, atau `<alamat resmi>` | Bawaan dari catatan lapangan, **perlu verifikasi** ke dokumen resmi BPJS sebelum produksi. Tidak bisa diubah lewat API (SSRF). |
| `INTEGRASI_APLICARES_SANDBOX`, `_PRODUKSI` | `<alamat resmi>` | Tanpa bawaan. |
| `INTEGRASI_SATUSEHAT_SANDBOX`, `_PRODUKSI` | bawaan, atau `<alamat resmi>` | *Perlu verifikasi*. |
| `INTEGRASI_BATAS_WAKTU` | bawaan 20 (detik) | Tidak ada di `.env.example`. Uji koneksi memakai 15 detik tetap. PRD 14.1.1 meminta 10 detik; lihat [README, Selisih dengan PRD](README.md#selisih-dengan-prd-dicatat-belum-diputuskan). |
| `INTEGRASI_MAKS_PERCOBAAN` | bawaan 8 | Tidak ada di `.env.example`. |
| `INTEGRASI_CB_AMBANG`, `INTEGRASI_CB_BUKA_DETIK` | bawaan 5, 60 | Tidak ada di `.env.example`. Circuit breaker per sistem. |
| `INTEGRASI_LOG_HARI` | bawaan 90 | Masa simpan `integrasi.log_api`; partisi yang lebih tua dihapus `integrasi:hapus-log-lama`. Nilai di bawah 30 dianggap 30 (`HapusLogLama`). |

## 10. Email, instalasi, menu, Horizon

| Variabel | Produksi | Catatan |
|---|---|---|
| `MAIL_MAILER` | `smtp` | Email notifikasi dikirim di queue `notifikasi` (`KirimEmailNotifikasi`). `.env.example`: `log`. |
| `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | `<smtp RS>` | Sandi di vault. |
| `MAIL_FROM_ADDRESS` | `no-reply@rs-contoh.invalid` (ganti domain RS) | `.env.example`: `hello@example.com`. |
| `MAIL_FROM_NAME` | `"${APP_NAME}"` atau nama RS | |
| `INSTALASI_DEMO` | `false` | `true` hanya untuk instalasi demo/pelatihan berdata sintetis (pita "DEMO — data fiktif"). |
| `INSTALASI_ZONA_WAKTU` | `Asia/Jakarta` atau zona RS | Jadwal harian dan tanggal berlaku. |
| `MENU_TAMPILKAN_SEGERA_HADIR` | bawaan (`false` di luar `local`) | Tidak ada di `.env.example`. `true` hanya untuk demo. |
| `HORIZON_PATH`, `HORIZON_DOMAIN`, `HORIZON_NAME` | bawaan | Tidak ada di `.env.example`. Dasbor `/horizon` ditolak untuk semua akun di luar `local` (`HorizonServiceProvider`). |
| `BROADCAST_CONNECTION` | `log` | Belum dipakai (Reverb belum ada). |
| `QUEUE_FAILED_DRIVER` | bawaan `database-uuids` | Pekerjaan gagal ke `sistem.failed_jobs`. |

## 11. Larangan produksi

| Larangan | Alasan |
|---|---|
| `APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false` | Aplikasi menolak boot (`PemeriksaanProduksi`). |
| `APP_ENV` selain `production` di server produksi | Pemeriksaan boot, jumlah pekerja Horizon, dan penolakan simulator/seeder bergantung pada nilai ini. |
| `INTEGRASI_SIMULATOR=true` | Simulator hanya untuk lokal/CI/staging (K8). |
| `AKUN_SINTETIS_*` (`AKUN_SINTETIS_SANDI`, `_MFA_RAHASIA`, `_SUPERADMIN_MFA_RAHASIA`, `_SUPERADMIN_DUA_MFA_RAHASIA`, `_SDM_MFA_RAHASIA`, `_INTEGRASI_MFA_RAHASIA`, `_TTD_PIN`) | Hanya untuk seeder lokal/staging. Hapus barisnya dari `.env` produksi. `php artisan db:seed` dilarang. |
| `TTD_KDF=minimum` | Biaya KDF PIN untuk tes saja. |
| Kunci uji dari `backend/phpunit.xml` dan `.github/workflows/ci.yml` (`TTD_KUNCI`, `KREDENSIAL_KUNCI`, rahasia TOTP sintetis) | Nilainya **publik**. Server membuat kuncinya sendiri. |
| `AUTENTIKASI_MFA_JARINGAN_RS` berisi loopback saja, atau `0.0.0.0/0` | Loopback saja = semua klien dianggap di luar RS: MFA selalu diminta dan akun yang belum punya MFA tidak bisa mendaftar MFA (hanya akun yang sudah punya MFA, mis. superadmin dari konsol, yang bisa masuk). `0.0.0.0/0` = semua klien dianggap di dalam RS, sehingga MFA untuk akses dari luar tidak pernah diminta (`KebijakanMfa::wajib`). |
| `TRUSTED_PROXIES=*` | IP klien bisa dipalsukan (aturan jaringan RS dan rate limit per IP). |
| `REDIS_CACHE_*` menunjuk instans sesi | PRD 14.1.1 butir 6. |
| `LOG_LEVEL=debug` | Log membengkak; tetap tersamarkan, tetapi tidak perlu. |
| `DB_MIGRATOR_PASSWORD` di `.env` produksi | Kredensial DDL tidak perlu ada di server saat berjalan (ADR 0003). |
| `VITE_API_TIRUAN=true` di mesin build | Tidak berpengaruh pada `npm run build` (server tiruan hanya dipasang bila `import.meta.env.DEV`, `frontend/src/app/main.ts`), tetapi jangan diset agar tidak membingungkan. |

Pemeriksaan cepat (dijalankan di `/srv/karsitara`; butuh `backend/phpunit.xml` dan `.github/workflows/ci.yml` dari
checkout yang sama):

```bash
cd /srv/karsitara
E=backend/.env
grep -nE '^APP_ENV=' $E; grep -nE '^APP_DEBUG=' $E
grep -nE '^(AKUN_SINTETIS_[A-Z_]+)=.+' $E && echo 'DILARANG: AKUN_SINTETIS_* terisi'
grep -nE '^INTEGRASI_SIMULATOR=true' $E && echo 'DILARANG: simulator menyala'
grep -nE '^TTD_KDF=minimum' $E && echo 'DILARANG: TTD_KDF=minimum'
grep -nE '^TRUSTED_PROXIES=\*' $E && echo 'DILARANG: TRUSTED_PROXIES=*'
grep -nE '^DB_MIGRATOR_PASSWORD=.+' $E && echo 'PERINGATAN: sandi migrator tersimpan di .env'
for v in $(grep -hoE 'base64:[A-Za-z0-9+/=]+' backend/phpunit.xml .github/workflows/ci.yml | sort -u); do
  grep -qF "$v" $E && echo 'DILARANG: kunci uji publik dipakai di .env'
done
grep -nE '^AUTENTIKASI_MFA_JARINGAN_RS=' $E
```

Tidak ada keluaran "DILARANG" = lulus.

## 12. Contoh `.env` produksi (kerangka)

Hanya baris yang berbeda dari bawaan atau wajib diisi. Semua `<...>` diisi di server.

```dotenv
APP_NAME="Karsitara SIMRS"
APP_ENV=production
APP_KEY=<dari php artisan key:generate>
APP_DEBUG=false
APP_URL=https://simrs.rs-contoh.invalid
APP_LOCALE=id

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=<hari>
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=<host-db>
DB_PORT=5432
DB_DATABASE=simrs
DB_USERNAME=simrs_app
DB_PASSWORD=<sandi simrs_app>
DB_SEARCH_PATH=sistem,public
DB_SSLMODE=verify-full
DB_MIGRATOR_USERNAME=simrs_migrator
# DB_MIGRATOR_PASSWORD tidak ditulis; diberikan saat migrasi saja

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_SAME_SITE=strict
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=simrs.rs-contoh.invalid
CORS_ALLOWED_ORIGINS=https://simrs.rs-contoh.invalid

TRUSTED_PROXIES=
AUTENTIKASI_MFA_JARINGAN_RS=<cidr-1>,<cidr-2>
AUTENTIKASI_MFA_PENERBIT="<nama RS>"

TTD_KUNCI=<base64:...>
TTD_KUNCI_LAMA=

INSTALASI_DEMO=false
INSTALASI_ZONA_WAKTU=Asia/Jakarta
IDENTITAS_DISK=local
SDM_DOKUMEN_DISK=local
AUDIT_ANCHOR_DISK=local
AUDIT_ANCHOR_DIREKTORI=audit/anchor

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=<host-valkey>
REDIS_PORT=6379
REDIS_PASSWORD=<sandi-valkey-sesi>
REDIS_DB=0
REDIS_CACHE_HOST=<host-valkey>
REDIS_CACHE_PORT=6380
REDIS_CACHE_PASSWORD=<sandi-valkey-cache>
REDIS_CACHE_DB=1

MAIL_MAILER=smtp
MAIL_HOST=<smtp-rs>
MAIL_PORT=<port>
MAIL_USERNAME=<pengguna>
MAIL_PASSWORD=<sandi>
MAIL_FROM_ADDRESS=no-reply@rs-contoh.invalid
MAIL_FROM_NAME="${APP_NAME}"

KREDENSIAL_KUNCI=<base64:...>
KREDENSIAL_KUNCI_LAMA=
INTEGRASI_SIMULATOR=false
```

## 13. Variabel yang dibaca kode tetapi tidak ada di `.env.example`

Dari pembandingan `env(...)` di `backend/config` dengan `.env.example` (12 Okt 2026, `main`). Bawaannya aman, tetapi
operator perlu tahu:

- **Produk ini** (sudah dibahas di tabel atas): `APP_PREVIOUS_KEYS`, `ARGON_MEMORY`, `ARGON_THREADS`, `ARGON_TIME`,
  `AUDIT_ANCHOR_LOG`, `AUTENTIKASI_MFA_CHALLENGE_MENIT`, `AUTENTIKASI_SANDI_PANJANG_MINIMAL`,
  `AUTENTIKASI_SANDI_DAFTAR_BOCOR`, `CACHE_LIMITER_STORE`, `DB_SSLMODE`, `HORIZON_NAME`, `HORIZON_DOMAIN`,
  `HORIZON_PATH`, `HORIZON_PREFIX`, `INTEGRASI_BATAS_WAKTU`, `INTEGRASI_CB_AMBANG`, `INTEGRASI_CB_BUKA_DETIK`,
  `INTEGRASI_MAKS_PERCOBAAN`, `LOG_DAILY_DAYS`, `MENU_TAMPILKAN_SEGERA_HADIR`, `QUEUE_FAILED_DRIVER`,
  `REDIS_CACHE_CONNECTION`, `REDIS_CACHE_LOCK_CONNECTION`, `REDIS_CACHE_USERNAME`, `REDIS_PREFIX`, `REDIS_QUEUE`,
  `REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE_RETRY_AFTER`, `REDIS_USERNAME`, `SANCTUM_TOKEN_PREFIX`,
  `SESSION_SECURE_COOKIE` (hanya komentar), `SESSION_HTTP_ONLY`, `TTD_KDF`.
- **Kerangka Laravel, biarkan bawaan**: `AUTH_GUARD`, `AUTH_PASSWORD_TIMEOUT`, `HASH_VERIFY`, `BCRYPT_LIMIT`,
  `DB_URL`, `DB_CHARSET`, `REDIS_URL`, `REDIS_CACHE_URL`, `REDIS_CLUSTER`, `REDIS_PERSISTENT`, `REDIS_MAX_RETRIES`,
  `REDIS_BACKOFF_*`, `SESSION_CONNECTION`, `SESSION_STORE`, `SESSION_COOKIE` (bawaan turunan `APP_NAME`),
  `SESSION_EXPIRE_ON_CLOSE`, `SESSION_PARTITIONED_COOKIE`, `LOG_DEPRECATIONS_TRACE`, `LOG_STDERR_FORMATTER`,
  `LOG_SYSLOG_FACILITY`, `MAIL_URL`, `MAIL_EHLO_DOMAIN`, `MAIL_LOG_CHANNEL`, `MAIL_SENDMAIL_PATH`, `AWS_URL`,
  `AWS_ENDPOINT`.
- **Tidak dipakai produk ini**: variabel driver lain (MySQL/MariaDB/SQL Server, Memcached, DynamoDB, SQS, Beanstalkd,
  queue/cache basis data `DB_QUEUE_*`/`DB_CACHE_*`, Postmark, Resend, Slack, Papertrail).

Sebaliknya `BROADCAST_CONNECTION`, `PHP_CLI_SERVER_WORKERS`, dan `VITE_APP_NAME` ada di `.env.example` tetapi tidak
dibaca konfigurasi backend (tidak ada `config/broadcasting.php`).

Sesudah 1.5 merge: `AUTENTIKASI_MFA_ULANG_MENIT` dan `AKSES_LABEL_MAKS_HARI` ditambahkan ke `.env.example` sebagai
baris komentar (§6).
