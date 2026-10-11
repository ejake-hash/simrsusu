# CLAUDE.md — Karsitara SIMRS (produk generik RS Tipe B pendidikan)

## Urutan sumber kebenaran
1. **`docs/prd.md`** — kebutuhan, aturan bisnis, regulasi, database, hak akses, UI. Selalu dibaca lebih dulu untuk bagian yang dikerjakan.
2. **File ini** — ringkasan konvensi yang paling sering dilanggar.
3. **Skill di `.claude/skills/`** — pola teknis umum. Bila contoh di skill bertentangan dengan 1 atau 2, **ikuti 1 dan 2**. Empat skill pihak ketiga yang bentrok sudah diberi blok "Aturan proyek SIMRS" di bagian atasnya.
4. **Prototipe `docs/html/`** — contoh tampilan dengan data fiktif. Bila berbeda dengan PRD, ikuti PRD dan laporkan selisihnya.

## Stack (tetap)
- Backend: **PHP 8.3+** (CI memakai 8.4), **Laravel 12**, modular monolith di `backend/app/Modules/*` (PRD 10.3).
- Database: **PostgreSQL 16**, satu database `simrs`, skema per domain (PRD 11.1). **Valkey** (kompatibel Redis, variabel `REDIS_*`) dua instans: `valkey` untuk sesi + antrean (`noeviction`), `valkey-cache` untuk cache (`allkeys-lru`) (PRD 14.1.1 butir 6).
- Frontend: **Vue 3 + TypeScript + Vite** (SPA, Vue Router, Pinia) di `frontend/` (PRD 10.4). **Bukan** Blade, Livewire, atau Inertia.
- Token desain: `frontend/src/design-system/tokens.css` (dari PRD 5.3). Jangan menulis warna HEX langsung di komponen. Lihat @DESIGN.md.
- **Nama modul bahasa Indonesia** (PRD 10.3, 10.4): backend PascalCase (`app/Modules/RawatJalan`), frontend kebab-case (`src/modules/rawat-jalan`). Nama lapisan teknis tetap: `Core`, `Shared`, `Domain`, `Application`, `Infrastructure`, `Http`, `Models`, `Database`.

## Kerja paralel
Beberapa sesi (orang atau agen) bisa bekerja bersamaan di mesin yang sama. Aturannya:

1. **Folder utama `SIMRS-USU` dipakai satu sesi saja.** Sesi lain wajib memakai worktree sendiri:
   `git worktree add ../SIMRS-USU-<tiket> <branch>` (worktree butuh `composer install`, `npm ci`, dan `.env` sendiri; jangan salin `.env` worktree lain). Di `.env` itu pakai nomor DB Valkey sendiri (`REDIS_DB`, `REDIS_CACHE_DB`) dan port `php artisan serve` sendiri.
2. **Setiap worktree backend memakai database tes sendiri `simrs_test_<tiket>`**, dibuat dari skrip yang sama dengan CI:
   ```bash
   export PGHOST=127.0.0.1 PGPORT=5433 PGUSER=postgres PGPASSWORD=postgres
   psql -c "CREATE DATABASE simrs_test_<tiket> OWNER simrs_migrator"
   psql -d simrs_test_<tiket> -f docker/postgres/init/sql/02-database.sql   # DBNAME otomatis = database tersambung
   DB_DATABASE_TES=simrs_test_<tiket> composer test                          # migrate --database=pgsql_migrator, lalu phpunit
   ```
   Jangan memakai `simrs_test` (bawaan CI dan folder utama) atau database tes worktree lain. Jangan `docker compose down -v`: volume Postgres dipakai bersama.
3. **Sebelum stash, checkout, atau reset: jalankan `git status` dan `git worktree list`**, lalu sentuh hanya berkas tiket sendiri. Stash dipakai bersama oleh semua worktree dan bisa ikut menelan perubahan staged milik sesi lain.
4. **Node lokal mengikuti `.nvmrc` (24)**, sama dengan CI.

<!-- simrs-skills -->
## Konvensi wajib (ringkasan PRD)

**Produk generik (PRD 3.4)**
- Satu instalasi = satu RS (tidak multi-tenant). Beda antar-RS = data induk, aturan, atau `inti.modul_aktif`; **dilarang** cabang kode per RS (`if rs == ...`) atau fork per pelanggan.
- Nama, logo, kop, dan warna merek dari `inti.profil_rs.paket_identitas`. Paket RS USU hanya contoh; jangan dipakai di demo publik.
- Keputusan yang biasanya dari RS ada di PRD 17.2. Butir bertanda *perlu verifikasi* tidak boleh dikodekan keras.
- Aturan UX 5.1 butir 9–17 adalah syarat terima komponen bersama (identitas 2 penanda, TTE hanya pemilik akun, aksi massal tidak menelan peringatan).

**Database (PRD 11.1)**
- Nama skema, tabel, kolom **bahasa Indonesia**, `snake_case`, tabel **tunggal** (`pasien`, `resep_item`). Tabel bawaan Laravel tetap Inggris di skema `sistem`.
- Kunci utama `id uuid` **UUIDv7** (bukan `bigint`, bukan `$table->id()`). Kunci tamu `<tabel>_id`.
- Waktu `*_pada` (`timestamptz`), pelaku `*_oleh`; model memakai `CREATED_AT = 'dibuat_pada'`, `UPDATED_AT = 'diubah_pada'`.
- **Tidak ada penghapusan permanen** data klinis/keuangan dan **tidak memakai `SoftDeletes`**. Pembatalan: `status = 'batal'`, `alasan_batal`, `dibatalkan_oleh`, `dibatalkan_pada`. Perbaikan membuat versi baru (`*_versi`) atau adendum.
- Tabel `users`/`password_reset_tokens` bawaan Laravel **tidak dipakai**; autentikasi memakai `akses.pengguna`.
- Migrasi Laravel saja, **maju saja** di produksi, selalu `php artisan migrate --database=pgsql_migrator`.
- Role: `simrs_migrator` (DDL), `simrs_app` (aplikasi: `sistem` DML penuh, `audit` hanya `SELECT, INSERT`, `analitik` hanya `SELECT`, domain `SELECT, INSERT, UPDATE`), `simrs_analitik`, `simrs_baca`. Tabel domain yang benar-benar perlu `DELETE` diberi hak di migrasinya sendiri dengan komentar alasan.

**Audit (PRD 11.2, ADR 0003)**
- Tulis log hanya lewat `App\Core\Audit\CatatAktivitas`. `urutan` dan `hash_rantai` diisi trigger; jangan diisi dari kode.
- Di dalam transaksi bisnis, panggil `catat()` di akhir: kunci rantai dipegang sampai commit. Transaksi selain `READ COMMITTED` ditolak trigger.
- Jangan pernah memasukkan sandi, token, atau data pasien ke `sebelum`/`sesudah`.

**Otorisasi (PRD 9.2)**
- Tolak kecuali diberikan. Policy/Gate Laravel lewat **`AccessEvaluator`** (lima lapis, rumus 9.2.5) adalah satu-satunya jalur; jangan cek peran langsung di controller atau komponen.
- Data bagian halaman yang tidak diizinkan **tidak dikirim server** (bentuk `JsonResource` sesuai izin).
- Row Level Security PostgreSQL: **tidak dipakai** saat ini. Bila nanti dipakai, identitas diset per transaksi dengan `select set_config('app.user_id', ?, true)`, bukan `SET` biasa. `auth.uid()` adalah fungsi Supabase dan tidak ada di proyek ini.

**Keamanan (PRD 14.2)**
- Autentikasi SPA dengan **cookie Sanctum (stateful)**; bukan token pribadi di `localStorage`. Token Sanctum hanya untuk integrasi mesin-ke-mesin.
- Hash kata sandi **Argon2id**. Kredensial integrasi hanya dari `.env`/vault, tidak pernah di kode atau log.
- Data pasien tidak boleh muncul di log aplikasi, URL, query string, atau pesan error.

**API (PRD 10.3.1)**
- Prefiks `/api/v1/`, kata benda **bahasa Indonesia**, kebab-case, **tanpa bentuk jamak**: `/api/v1/pasien`, `/api/v1/permintaan-pemeriksaan/{id}`. Aksi bisnis sebagai sub-sumber kata kerja: `POST /api/v1/resep/{id}/sahkan`. Kunci JSON `snake_case` Indonesia.

**Tes**
- PHPUnit/Pest terhadap **PostgreSQL** (bukan SQLite). Tes memakai **`DatabaseTransactions`** lewat `Tests\TestCase`. `RefreshDatabase`, `DatabaseMigrations`, `DatabaseTruncation` **ditolak CI**.
- Jalankan dengan `composer test` (migrasi sebagai migrator, lalu phpunit). Database tes bawaan `simrs_test`; di worktree pakai `DB_DATABASE_TES=simrs_test_<tiket> composer test` (lihat "Kerja paralel").
- Analisis statis: `composer analyse` (Larastan level 5, juga di CI). `phpstan-baseline.neon` hanya berisi temuan kode lama; kode baru harus bersih, jangan menambah baseline.
- Playwright hanya ke **staging**, atau ke **lokal/CI dengan data sintetis**. Jangan pernah memakai data pasien asli di tes, seed, log, atau rekaman.

**Klinis dan integrasi**
- Regulasi yang berlaku: Indonesia (Permenkes 24/2022, UU 27/2022 PDP, dan regulasi lain di PRD 15). Abaikan rujukan HIPAA/GDPR/DISHA di skill pihak ketiga kecuali diminta.
- Nilai klinis (ambang kritis, interaksi obat, dosis, kode ICD, skor EWS) hanya dari data master yang disetujui RS; jangan dikarang atau di-hardcode.
- Jangan mengarang endpoint SATUSEHAT/BPJS atau kode ICD. Skill `satusehat-fhir`, `bpjs-bridging`, `koding-icd` diisi **hanya dari dokumen resmi**; jika belum ada, tanyakan.
- Contoh kode non-PHP di skill mana pun hanya ilustrasi pola; implementasikan dalam PHP/Laravel atau TypeScript/Vue.
