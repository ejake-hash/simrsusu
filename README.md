# Karsitara SIMRS

SIMRS generik untuk RS Umum Tipe B pendidikan. Satu instalasi melayani satu RS. Perbedaan antar-RS diatur lewat data induk dan paket identitas, bukan cabang kode (PRD 3.4).
Demo, e2e, dan tangkapan layar memakai identitas **fiktif** "RS Contoh Pendidikan" (`docs/brand/contoh/`). Repo ini publik: tidak ada data RS nyata, dokumen pihak ketiga, rahasia, atau aset identitas RS di dalamnya. Lisensi: lihat [LICENSE](LICENSE) (bukan open source); celah keamanan: lihat [SECURITY.md](SECURITY.md).

| Folder | Isi |
|---|---|
| `backend/` | Laravel 12 (modular monolith, PRD 10.3) |
| `frontend/` | Vue 3 + TypeScript + Vite (PRD 10.4) |
| `docs/` | `prd.md`, kontrak API, backlog, logo produk dan paket identitas fiktif (`brand/`) |
| `docker/` | skrip init PostgreSQL: role, database, skema domain PRD 11.1 |
| `.github/workflows/` | CI: tes backend (Postgres 16 + Valkey), build frontend, e2e |

## Menjalankan lokal

```bash
docker compose up -d                 # PostgreSQL 16 (5433) + Valkey sesi/queue (6379) + Valkey cache (6380)

cd backend
composer install
php artisan lokal:siapkan-env        # tulis/lengkapi .env: kunci, rahasia TOTP + PIN akun sintetis, simulator
                                     # (dicetak sekali); tidak menimpa nilai terisi, cadangan di ~/.karsitara/
                                     # opsi: --paket-identitas=<folder di luar repo> --kemudahan-uji
php artisan migrate --database=pgsql_migrator
composer test                        # migrasi simrs_test sebagai migrator, lalu phpunit
php artisan db:seed                  # akun sintetis contoh.* (ditolak di production); paket identitas dari
                                     # PAKET_IDENTITAS_CONTOH_DIR bila diisi (docs/brand/README.md)
php artisan serve                    # http://127.0.0.1:8000
php artisan lokal:kode-mfa           # kode TOTP saat ini untuk akun sintetis ber-MFA (hanya APP_ENV=local)

cd ../frontend
npm install
npm run dev                          # http://localhost:5173, /api diteruskan ke backend
```

## Valkey

Cache, sesi, dan queue memakai **Valkey** (BSD-3, kompatibel Redis; PRD 10.1). Nama variabel tetap `REDIS_*` bawaan Laravel.
Ada dua instans (PRD 14.1.1 butir 6) karena kebijakan memori berlaku per instans:

| Layanan compose | Koneksi Laravel | Isi | `maxmemory-policy` |
|---|---|---|---|
| `valkey` (6379) | `default` (`REDIS_HOST`/`REDIS_PORT`) | sesi, queue, kunci (*lock*) cache | `noeviction`: menolak tulis saat penuh, tidak membuang sesi atau pekerjaan |
| `valkey-cache` (6380) | `cache` (`REDIS_CACHE_HOST`/`REDIS_CACHE_PORT`) | cache | `allkeys-lru`: membuang kunci lama |

Jangan arahkan keduanya ke satu instans; `InfrastrukturTest` memeriksa kebijakan ini lewat `CONFIG GET`.
Pindah dari compose lama (layanan `redis`): `docker compose up -d --remove-orphans`. Volume lama `simrs-usu_redis-data`
tidak dipakai lagi dan boleh dihapus setelah semua worktree pindah. Worktree yang perlu instans sendiri:
`VALKEY_PORT=… VALKEY_CACHE_PORT=… docker compose -p simrs-usu-<tiket> up -d valkey valkey-cache`.

## Ketahanan beban (PRD 14.1.1)

| Bagian | Di mana |
|---|---|
| Pemeriksaan kesehatan | `GET /up` (proses hidup), `GET /api/v1/siap` (basis data + kedua Valkey; `503` bila salah satu tidak terjangkau). Tanpa autentikasi, punya rate limit per IP |
| Batas waktu query | `statement_timeout` per role di `docker/postgres/init/sql/01-role.sql` (`simrs_app` 5 s, `simrs_analitik` 5 min) |
| Paginasi | `App\Core\Http\Paginasi` (keyset, `?kursor=&batas=`, maks 200) |
| Queue per jenis | `App\Core\Queue\JenisQueue` + `config/horizon.php`; jalankan `php artisan horizon` (dasbor `/horizon` hanya di `APP_ENV=local`) |
| Rate limit | `config/rate_limit.php` (semua `api/v1`, per akun) dan `config/autentikasi.php` (masuk) |
| Uji beban | `backend/tests/beban/` (k6, manual ke lokal/staging) |

Cluster Postgres lokal yang dibuat sebelum batas waktu ini ada: jalankan ulang
`psql -h 127.0.0.1 -p 5433 -U postgres -d postgres -v migrator_password=simrs_migrator -v app_password=simrs_app -f docker/postgres/init/sql/01-role.sql`
(aman diulang), lalu sambung ulang aplikasi.

## Autentikasi

SPA masuk dengan cookie sesi Sanctum (bukan token di `localStorage`):
`GET /sanctum/csrf-cookie` → `POST /api/v1/autentikasi/masuk` → `GET /api/v1/saya` → `POST /api/v1/autentikasi/keluar`.
Aturan sandi, penguncian, rate limit, dan batas diam sesi ada di `backend/config/autentikasi.php`;
alasannya di [`docs/adr/`](docs/adr/).

**MFA (PRD 10.3.2).** Respons masuk berbentuk `{status: masuk | perlu_mfa | perlu_daftar_mfa, …}`. MFA wajib untuk akun
`wajib_mfa` dan untuk akses dari luar `AUTENTIKASI_MFA_JARINGAN_RS` (CIDR jaringan RS). Akun yang belum punya MFA
hanya bisa mendaftar dari jaringan RS; dari luar ditolak `403 mfa_belum_terdaftar`. **Isi variabel ini di produksi**:
bila kosong, login web ditolak `503 konfigurasi_belum_lengkap`. Langkah MFA: `POST /api/v1/autentikasi/mfa`,
`…/mfa/daftar`, `…/mfa/daftar/konfirmasi` (TOTP RFC 6238 + 10 kode cadangan sekali pakai).
**Instalasi baru:** dua superadmin bernama dibuat di server dengan `php artisan akses:superadmin-pertama`
(sandi lewat prompt tersembunyi; URI otpauth dan 10 kode cadangan tampil sekali; ditolak bila sudah ada 2 superadmin aktif).
Akun sintetis `contoh.mfa` (rahasia dari `AKUN_SINTETIS_MFA_RAHASIA`) dan `contoh.mfa.baru` (belum terdaftar) untuk uji lokal dan e2e.

## Role database

| Role | Dipakai untuk | Hak |
|---|---|---|
| `simrs_migrator` | `php artisan migrate --database=pgsql_migrator` | pemilik semua skema dan tabel (DDL) |
| `simrs_app` | aplikasi saat berjalan (koneksi `pgsql` bawaan) | tanpa DDL; DML per skema, lihat di bawah |
| `simrs_analitik` | `REFRESH MATERIALIZED VIEW` dasbor (NOLOGIN sampai fase 4) | pemilik materialized view di `analitik`; `SELECT` skema domain |
| `simrs_baca` | dasbor/pelaporan (NOLOGIN sampai fase 4) | `SELECT` skema `analitik` |

| Skema | Hak `simrs_app` |
|---|---|
| `sistem` | `SELECT, INSERT, UPDATE, DELETE` |
| `audit` | `SELECT, INSERT` (log tidak bisa diubah atau dihapus) |
| `analitik` | `SELECT` saja |
| skema domain lainnya | `SELECT, INSERT, UPDATE` (tanpa hapus permanen, PRD 11.1; pembatalan memakai `status`) |

Migrasi **harus** memakai `--database=pgsql_migrator`; `php artisan migrate` biasa akan gagal karena `simrs_app` tidak boleh membuat tabel.
Tabel bawaan Laravel masuk ke skema `sistem` (`DB_SEARCH_PATH=sistem,public`).

Tes memakai `DatabaseTransactions` lewat `Tests\TestCase`. `RefreshDatabase`, `DatabaseMigrations`, dan `DatabaseTruncation` ditolak CI karena `simrs_app` tidak boleh DDL.

Skrip `docker/postgres/init/00-init.sh` jalan otomatis saat volume Postgres pertama kali dibuat, dan dipakai juga di CI.
Skrip ini aman dijalankan ulang. Setelah mengubahnya, jalankan `docker compose down -v` lalu `docker compose up -d`.

## Panduan untuk AI dan tim

- `docs/prd.md` adalah sumber kebenaran; `CLAUDE.md` merangkum konvensi; `DESIGN.md` merujuk token di PRD 5.3.
- Skill Claude Code ada di `.claude/skills/` (asal-usul di `.claude/skills/SOURCES.md`, cara pasang di `docs/PASANG-skill.txt`). Skill pihak ketiga yang bentrok dengan PRD diberi blok "Aturan proyek SIMRS" di bagian atasnya.
