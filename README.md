# Karsitara SIMRS

SIMRS generik untuk RS Umum Tipe B pendidikan. Satu instalasi melayani satu RS. Perbedaan antar-RS diatur lewat data induk dan paket identitas, bukan cabang kode (PRD 3.4).
RS USU hanya **paket identitas contoh** (logo, nama, warna `--brand-*`/`--accent`), dan logonya tidak boleh dipakai di demo publik tanpa izin tertulis USU.

| Folder | Isi |
|---|---|
| `backend/` | Laravel 12 (modular monolith, PRD 10.3) |
| `frontend/` | Vue 3 + TypeScript + Vite (PRD 10.4) |
| `docs/` | `prd.md`, prototipe HTML, dan logo asli paket contoh RS USU (`brand/`) |
| `docker/` | skrip init PostgreSQL: role, database, skema domain PRD 11.1 |
| `.github/workflows/` | CI: tes backend (Postgres 16 + Valkey), build frontend, e2e |

## Menjalankan lokal

```bash
docker compose up -d                 # PostgreSQL 16 (5433) + Valkey sesi/antrean (6379) + Valkey cache (6380)

cd backend
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --database=pgsql_migrator
composer test                        # migrasi simrs_test sebagai migrator, lalu phpunit
php artisan db:seed                  # akun sintetis contoh.admisi/perawat/dokter (ditolak di production)
php artisan serve                    # http://127.0.0.1:8000

cd ../frontend
npm install
npm run dev                          # http://localhost:5173, /api diteruskan ke backend
```

## Valkey

Cache, sesi, dan antrean memakai **Valkey** (BSD-3, kompatibel Redis; PRD 10.1). Nama variabel tetap `REDIS_*` bawaan Laravel.
Ada dua instans (PRD 14.1.1 butir 6) karena kebijakan memori berlaku per instans:

| Layanan compose | Koneksi Laravel | Isi | `maxmemory-policy` |
|---|---|---|---|
| `valkey` (6379) | `default` (`REDIS_HOST`/`REDIS_PORT`) | sesi, antrean, kunci (*lock*) cache | `noeviction`: menolak tulis saat penuh, tidak membuang sesi atau pekerjaan |
| `valkey-cache` (6380) | `cache` (`REDIS_CACHE_HOST`/`REDIS_CACHE_PORT`) | cache | `allkeys-lru`: membuang kunci lama |

Jangan arahkan keduanya ke satu instans; `InfrastrukturTest` memeriksa kebijakan ini lewat `CONFIG GET`.
Pindah dari compose lama (layanan `redis`): `docker compose up -d --remove-orphans`. Volume lama `simrs-usu_redis-data`
tidak dipakai lagi dan boleh dihapus setelah semua worktree pindah. Worktree yang perlu instans sendiri:
`VALKEY_PORT=… VALKEY_CACHE_PORT=… docker compose -p simrs-usu-<tiket> up -d valkey valkey-cache`.

## Autentikasi

SPA masuk dengan cookie sesi Sanctum (bukan token di `localStorage`):
`GET /sanctum/csrf-cookie` → `POST /api/v1/autentikasi/masuk` → `GET /api/v1/saya` → `POST /api/v1/autentikasi/keluar`.
Aturan sandi, penguncian, batas laju, dan batas diam sesi ada di `backend/config/autentikasi.php`;
alasannya di [`docs/adr/`](docs/adr/).

**MFA (PRD 10.3.2).** Respons masuk berbentuk `{status: masuk | perlu_mfa | perlu_daftar_mfa, …}`. MFA wajib untuk akun
`wajib_mfa` dan untuk akses dari luar `AUTENTIKASI_MFA_JARINGAN_RS` (CIDR jaringan RS). **Isi variabel ini di produksi**:
bila kosong, semua akses dianggap dari luar dan wajib MFA. Langkah MFA: `POST /api/v1/autentikasi/mfa`,
`…/mfa/daftar`, `…/mfa/daftar/konfirmasi` (TOTP RFC 6238 + 10 kode cadangan sekali pakai).
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
