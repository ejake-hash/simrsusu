# Karsitara SIMRS

SIMRS generik untuk RS Umum Tipe B pendidikan. Satu instalasi melayani satu RS. Perbedaan antar-RS diatur lewat data induk dan paket identitas, bukan cabang kode (PRD 3.4).
RS USU hanya **paket identitas contoh** (logo, nama, warna `--brand-*`/`--accent`), dan logonya tidak boleh dipakai di demo publik tanpa izin tertulis USU.

| Folder | Isi |
|---|---|
| `backend/` | Laravel 12 (modular monolith, PRD 10.3) |
| `frontend/` | Vue 3 + TypeScript + Vite (PRD 10.4) |
| `docs/` | `prd.md`, prototipe HTML, dan logo asli paket contoh RS USU (`brand/`) |
| `docker/` | skrip init PostgreSQL: role, database, skema domain PRD 11.1 |
| `.github/workflows/` | CI: tes backend (Postgres 16 + Redis) dan build frontend |

## Menjalankan lokal

```bash
docker compose up -d                 # PostgreSQL 16 (port 5433) + Redis 7

cd backend
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --database=pgsql_migrator
composer test                        # migrasi simrs_test sebagai migrator, lalu phpunit
php artisan serve                    # http://127.0.0.1:8000

cd ../frontend
npm install
npm run dev                          # http://localhost:5173, /api diteruskan ke backend
```

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
