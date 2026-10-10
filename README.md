# SIMRS RS Pendidikan USU

| Folder | Isi |
|---|---|
| `backend/` | Laravel 12 (modular monolith, PRD 10.3) |
| `frontend/` | Vue 3 + TypeScript + Vite (PRD 10.4) |
| `docs/` | `prd.md`, prototipe HTML, dan logo asli (`brand/`) |
| `docker/` | skrip init PostgreSQL: role, database, skema domain PRD 11.1 |
| `.github/workflows/` | CI: tes backend (Postgres 16 + Redis) dan build frontend |

## Menjalankan lokal

```bash
docker compose up -d                 # PostgreSQL 16 (port 5433) + Redis 7

cd backend
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --database=pgsql_migrator
DB_DATABASE=simrs_test php artisan migrate --database=pgsql_migrator
php artisan test
php artisan serve                    # http://127.0.0.1:8000

cd ../frontend
npm install
npm run dev                          # http://localhost:5173, /api diteruskan ke backend
```

## Role database

| Role | Dipakai untuk | Hak |
|---|---|---|
| `simrs_migrator` | `php artisan migrate --database=pgsql_migrator` | pemilik semua skema dan tabel (DDL) |
| `simrs_app` | aplikasi saat berjalan (koneksi `pgsql` bawaan) | `SELECT/INSERT/UPDATE/DELETE` saja, tanpa DDL |

Migrasi **harus** memakai `--database=pgsql_migrator`; `php artisan migrate` biasa akan gagal karena `simrs_app` tidak boleh membuat tabel.
Tabel bawaan Laravel masuk ke skema `sistem` (`DB_SEARCH_PATH=sistem,public`).

Skrip `docker/postgres/init/00-init.sh` jalan otomatis saat volume Postgres pertama kali dibuat, dan dipakai juga di CI.
Skrip ini aman dijalankan ulang. Setelah mengubahnya, jalankan `docker compose down -v` lalu `docker compose up -d`.
