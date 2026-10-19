# CLAUDE.md — Karsitara SIMRS (produk generik RS Tipe B pendidikan)

## Urutan sumber kebenaran
1. **`docs/prd.md`** — kebutuhan, aturan bisnis, regulasi, database, hak akses, UI. Dibaca lebih dulu untuk bagian yang dikerjakan. **Baca per bagian** (`grep -n` lalu rentang baris), jangan utuh — begitu juga `docs/api/openapi.yaml` dan `docs/backlog-fase1.md`.
2. **File ini** — ringkasan konvensi yang paling sering dilanggar.
3. **Skill `.claude/skills/`** — pola teknis. Bila contoh skill bentrok dengan 1/2, **ikuti 1/2**. Alur kerja tiket (worktree, merge sendiri, DoD, hemat token) ada di skill **`kerja-tiket`** — baca sebelum mengerjakan tiket, membuka/meninjau/merge PR, atau menyiapkan worktree.
4. **Prototipe HTML & catatan lapangan di luar repo** (`/Users/ejake/Documents/SIMRS Tipe B/`) — contoh/bukti, bukan dokumen resmi. Bila berbeda dengan PRD, ikuti PRD dan laporkan selisihnya.

## Stack (tetap)
- Backend: **PHP 8.3+** (CI 8.4), **Laravel 12**, modular monolith `backend/app/Modules/*` (PRD 10.3).
- Database: **PostgreSQL 16**, satu database `simrs`, skema per domain (11.1). **Valkey** (`REDIS_*`) dua instans: `valkey` sesi+antrean (`noeviction`), `valkey-cache` cache (`allkeys-lru`) (14.1.1 butir 6).
- Frontend: **Vue 3 + TypeScript + Vite** (SPA, Vue Router, Pinia) di `frontend/` (10.4). **Bukan** Blade/Livewire/Inertia.
- Token desain: `frontend/src/design-system/tokens.css` (PRD 5.3). Jangan tulis HEX di komponen. Lihat @DESIGN.md.
- **Nama modul bahasa Indonesia** (10.3, 10.4): backend PascalCase (`app/Modules/RawatJalan`), frontend kebab-case (`src/modules/rawat-jalan`). Nama lapisan teknis tetap: `Core`, `Shared`, `Domain`, `Application`, `Infrastructure`, `Http`, `Models`, `Database`.

## Alur kerja (ringkas; rinci di skill `kerja-tiket`)
- **Tiket aktif** di `docs/backlog-fase2.md` (Fase 1 tinggal 1.10, terhambat). Satu tiket = satu PR; jalur `BE`, `FE`, atau `BE+FE` (kontrak dulu, lalu paralel).
- **Worktree:** folder utama satu sesi saja dan tidak pernah pull/checkout oleh agen. Worktree baru (sesi atau kartu Korlap) → jalankan `scripts/worktree-siapkan.sh` sebelum apa pun; selesai → `scripts/worktree-bersihkan.sh <jalur>` dari folder utama.
- **Kerja berkelanjutan:** merge sendiri bila CI hijau + tinjauan "Disetujui". Ambigu bukan aturan PRD → pilih bawaan, tulis "Asumsi:" di PR, lanjut. CI gagal 3× sebab sama → catat utang di backlog, ambil tiket lain. Berhenti hanya bila tak ada tiket Siap atau butuh data/kredensial pihak luar.
- **Tinjauan:** PR backend/keamanan/kontrak → subagen `peninjau`; PR frontend → `peninjau` fokus kontrak + layar; PR dokumen/backlog → tanpa tinjauan. Komentar PR berawalan "Tinjauan:".
- **[Perlu pemilik]** (PR terpisah, judul berawalan itu): PRD beraturan baru karangan sesi, openapi di luar endpoint tiket, pelonggaran keamanan, migrasi hapus data, atau dependensi baru/versi mayor.
- **Navigasi:** `graphify query|explain|path` dulu; tak menjawab → `grep -n` + rentang baris. Graf = indeks, baca berkas asli sebelum mengubah (rincian hook di `kerja-tiket`).
- **Antislop** <!-- antislop --> (`.claude/skills/antislop*`, mode `during`): hanya tiket FE yang mengubah layar dan tinjauan teks/dokumen, bukan BE/kontrak/migrasi. Arah desain = `DESIGN.md` + PRD 5.3, aset dari paket identitas → jangan bertanya ulang (R-23/R-37 terpenuhi); wizard pemasangannya dilewati; bentrok → ikuti PRD/CLAUDE.md.
- **Hemat token:** dokumen besar per bagian; WIP commit + push ±30 menit; laporan akhir 5 baris (format di `kerja-tiket`); PRD diselaraskan sekali per fase.
- **DoD bertingkat:** tiket kecil = satu PR, e2e hanya spec terkait @1440 px + axe. E2E penuh 4 lebar → workflow malam + gerbang fase.

## Repo publik (keputusan pemilik, 12 Okt 2026)
Repo **publik**. Dilarang menambah: **data RS nyata** (pasien/pegawai/NIK/RM/kode faskes/alamat/kontak); **dokumen pihak ketiga** (dokumen RS, pedoman identitas, katalog BPJS/Kemenkes tak boleh disebar — rujuk jalur luar repo); **rahasia** (kredensial/kunci/token/sandi; nilai uji sintetis di `phpunit.xml`/CI ditandai, dilarang di server); **aset identitas RS** (logo/foto/motif/nama RS). Demo/e2e/tangkapan layar pakai fiktif "RS Contoh Pendidikan" (`docs/brand/contoh/`). Berkas yang terlanjur di riwayat: laporkan ke pemilik, jangan force-push sendiri. Lisensi: `LICENSE` (hak cipta). Celah: `SECURITY.md`.
<!-- simrs-skills -->
## Konvensi wajib (ringkasan PRD; sering dilanggar)

**Produk generik (PRD 3.4)**
- Satu instalasi = satu RS (bukan multi-tenant). Beda antar-RS = data induk/aturan/`inti.modul_aktif`; **dilarang** cabang kode per RS (`if rs == ...`) atau fork per pelanggan.
- Nama, logo, kop, warna dari `inti.profil_rs.paket_identitas`. Demo/e2e: fiktif "RS Contoh Pendidikan".
- Keputusan RS ada di PRD 17.2. Butir *perlu verifikasi* tidak dikodekan keras.
- Aturan UX 5.1 butir 9–17 = syarat terima komponen bersama (identitas 2 penanda, TTE hanya pemilik akun, aksi massal tak menelan peringatan).

**Database (PRD 11.1)**
- Nama skema/tabel/kolom **bahasa Indonesia**, `snake_case`, tabel **tunggal** (`pasien`, `resep_item`). Tabel bawaan Laravel tetap Inggris di skema `sistem`.
- Kunci utama `id uuid` **UUIDv7** (bukan `bigint`/`$table->id()`). Kunci tamu `<tabel>_id`.
- Waktu `*_pada` (`timestamptz`), pelaku `*_oleh`; model `CREATED_AT = 'dibuat_pada'`, `UPDATED_AT = 'diubah_pada'`.
- **Tanpa penghapusan permanen** data klinis/keuangan, **tanpa `SoftDeletes`**. Pembatalan: `status = 'batal'`, `alasan_batal`, `dibatalkan_oleh`, `dibatalkan_pada`. Perbaikan = versi baru (`*_versi`) atau adendum.
- Tabel `users`/`password_reset_tokens` bawaan Laravel **tidak dipakai**; autentikasi `akses.pengguna`.
- Migrasi Laravel, **maju saja** di produksi, selalu `php artisan migrate --database=pgsql_migrator`.
- Role: `simrs_migrator` (DDL), `simrs_app` (`sistem` DML penuh, `audit` `SELECT,INSERT`, `analitik` `SELECT`, domain `SELECT,INSERT,UPDATE`), `simrs_analitik`, `simrs_baca`. Tabel domain yang perlu `DELETE` diberi hak di migrasinya sendiri dengan komentar.

**Audit (PRD 11.2, ADR 0003)**
- Tulis log hanya lewat `App\Core\Audit\CatatAktivitas`. `urutan`/`hash_rantai` diisi trigger; jangan dari kode.
- Di transaksi bisnis, panggil `catat()` di **akhir**: kunci rantai dipegang sampai commit. Transaksi selain `READ COMMITTED` ditolak trigger.
- Jangan masukkan sandi, token, atau data pasien ke `sebelum`/`sesudah`.

**Otorisasi (PRD 9.2)**
- Tolak kecuali diberikan. Policy/Gate lewat **`AccessEvaluator`** (lima lapis, 9.2.5) satu-satunya jalur; jangan cek peran langsung di controller/komponen.
- Data bagian halaman yang tak diizinkan **tidak dikirim server** (`JsonResource` sesuai izin).
- Kode izin `<menu>.<sub_menu>.<aksi>` diperiksa `->middleware('can:<kode>')` atau `Gate::allows($kode, $cakupan)`. Menu/bagian baru dan halaman `TERSEDIA` didaftarkan di `MenuBawaan`, lalu migrasi memanggil `MenuBawaan::pasang()`.
- Row Level Security PostgreSQL: **tidak dipakai**. Bila nanti dipakai, identitas per transaksi dengan `select set_config('app.user_id', ?, true)`. `auth.uid()` (Supabase) tidak ada di proyek ini.

**Keamanan (PRD 14.2)**
- Autentikasi SPA **cookie Sanctum (stateful)**; bukan token di `localStorage`. Token Sanctum hanya mesin-ke-mesin.
- Hash sandi **Argon2id**. Kredensial integrasi dari `.env`/vault, tidak pernah di kode atau log.
- Data pasien tidak boleh muncul di log, URL, query string, atau pesan error.

**API (PRD 10.3.1)**
- Prefiks `/api/v1/`, kata benda **Indonesia**, kebab-case, **tanpa jamak**: `/api/v1/pasien`. Aksi bisnis = sub-sumber kata kerja: `POST /api/v1/resep/{id}/sahkan`. Kunci JSON `snake_case` Indonesia.

**Ketahanan beban (PRD 14.1.1)**
- Endpoint daftar **hanya** lewat `App\Core\Http\Paginasi::respons()`: keyset `(waktu, id)`, `?kursor=&batas=`, bawaan 50, maks 200 (`422 ukuran_halaman_melebihi_batas`). Jangan `paginate()`/`cursorPaginate()`/`get()` tanpa batas. Filter cakupan dipaksa server.
- Queue memilih jenis di `App\Core\Queue\JenisQueue`: `->onConnection($jenis->koneksi())->onQueue($jenis->value)`. Ekspor/laporan selalu di antrean.
- `simrs_app` `statement_timeout` 5 detik. Pekerjaan lama pakai `SET LOCAL statement_timeout` di transaksinya, bukan menaikkan batas role.
- Semua rute `api/v1` kena `throttle:api` (per akun; `config/rate_limit.php`). Layar kerja cepat: `->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja')`. `RateLimitTest` gagal bila ada rute tanpa batas laju.

**Tes**
- PHPUnit/Pest terhadap **PostgreSQL** (bukan SQLite), memakai **`DatabaseTransactions`** lewat `Tests\TestCase`. `RefreshDatabase`/`DatabaseMigrations`/`DatabaseTruncation` **ditolak CI**.
- `composer test` (migrasi migrator, lalu phpunit). DB tes bawaan `simrs_test`; di worktree `DB_DATABASE_TES=simrs_test_<tiket> composer test`.
- `composer analyse` (Larastan level 5). `phpstan-baseline.neon` hanya kode lama; kode baru harus bersih.
- Playwright hanya ke **staging** atau **lokal/CI data sintetis**. Jangan data pasien asli.

**Istilah (PRD 10.3.3)**
- Pelayanan RS tetap Indonesia (pasien, kunjungan, tagihan, peran, izin, antrean pasien). Teknis pakai kata lazim: `error`, `email`, `challenge`, `anchor`, `masking`, `rate limit`, `trusted proxy`, `queue` (kata "antrean" khusus antrean pasien), `jumlah_tugas`, `master_data`. Istilah baru meragukan → tabel glosarium (10.3.3) lewat PR, tidak per berkas.

**Klinis dan integrasi**
- Regulasi Indonesia (Permenkes 24/2022, UU 27/2022 PDP, PRD 15). Abaikan HIPAA/GDPR/DISHA di skill pihak ketiga kecuali diminta.
- Nilai klinis (ambang kritis, interaksi obat, dosis, ICD, EWS) hanya dari data master disetujui RS; jangan dikarang/hardcode. Jangan mengarang endpoint SATUSEHAT/BPJS atau kode ICD — skill `satusehat-fhir`/`bpjs-bridging`/`koding-icd` dari dokumen resmi; bila belum ada, tanyakan. Contoh kode non-PHP di skill = ilustrasi pola; implementasikan PHP/Laravel atau TypeScript/Vue.
