# Hasil uji beban fase 1 (12 Oktober 2026, data sintetis)

Tiket 1.12 butir be 1 (endpoint fase 1) dan be 2 (penulisan audit, utang ADR 0003). Skrip: `backend/tests/beban/`
(cara menjalankan di README di sana). Angka ini dari **satu laptop pengembang**, bukan server produksi kecil yang
sebenarnya. Angka pembanding anggaran PRD 14.1 tetap uji ke staging (Octane/php-fpm + PgBouncer) sebelum pilot
(PRD 14.1.1 "Bukti").

## Lingkungan

| Komponen | Isi |
|---|---|
| Mesin | Apple M5, 10 inti, 16 GB |
| Aplikasi | PHP 8.4.21, opcache, server bawaan PHP dengan **8 pekerja** (`PHP_CLI_SERVER_WORKERS=8`), `config:cache`, `APP_DEBUG=false` |
| Basis data dan Valkey | PostgreSQL 16 dan dua Valkey dari `docker-compose.yml` di VM Docker Desktop (**2 CPU, 4 GB**) |
| Klien | k6 2.3.0 native di mesin yang sama (bukan lewat Docker; lihat "Catatan metode") |
| Data | 2.017 akun dan pegawai (2.000 sintetis `beban.*`), `OrganisasiSintetisSeeder`; 200 akun berperan uji (lihat akun, pegawai, organisasi) |

## be 1: endpoint fase 1 (`fase1.js`)

Skenario `kerja`: tiap VU memakai sesinya sendiri dan memanggil campuran layar dengan jeda pikir 0,5–1,5 detik:
- polling notifikasi dengan ETag (bobot 4);
- `/saya/menu` (bobot 2);
- `/saya`, daftar akun, daftar pegawai, daftar lokasi, dan daftar unit (bobot 1 masing-masing).

Skenario `masuk`: masuk lalu keluar, 30 kali per menit. Setiap tingkat berjalan 60 detik.

| VU | permintaan/detik | median ms | P95 ms | P99 ms | maks ms | masuk P95 ms | error |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 25 | 25,0 | 21 | 29 | 86 | 161 | 149 | 0,00% |
| 50 | 46,7 | 20 | 30 | 133 | 284 | 148 | 0,00% |
| 100 | 82,4 | 25 | 55 | 244 | 586 | 174 | 0,00% |
| 200 | 126,4 | 111 | 402 | 644 | 1.747 | 283 | 2,24% |
| 400 | 124,3 | 818 | 963 | 1.173 | 1.393 | 1.018 | 3,01% |

**Bacaan:**
- **Sampai 100 VU** (±82 permintaan/detik), anggaran PRD 14.1 terpenuhi: baca P95 ≤ 200 ms, masuk P95 ≤ 500 ms, error 0%.
- **Titik jenuh ±125 permintaan/detik** pada lingkungan ini. Sesudah itu laju tidak naik lagi, P95 melewati anggaran
  (402 ms pada 200 VU), dan error muncul. **Hambatannya belum diketahui dan kemungkinan bukan pekerja PHP:** median
  saat beban rendah 20–25 ms, jadi 8 pekerja PHP saja seharusnya sanggup ±300 permintaan/detik. Calon hambatan:
  - PostgreSQL dan Valkey di VM Docker 2 CPU;
  - koneksi TCP baru tiap permintaan (port efemeral habis);
  - k6 di mesin yang sama.

  Angka ini hanya batas bawah yang sangat konservatif, **bukan rumus ukuran server**.
- **Profil acuan PRD 14.1.1** (800 pengguna serentak) belum bisa dibandingkan langsung. Satu VU di sini meminta ±1 kali per detik, jauh lebih rapat daripada pengguna nyata. Kebutuhan pekerja di server RS dihitung ulang dari uji staging dengan pola klik nyata.
- **Error di 200–400 VU** bukan error aplikasi:
  - `Can't assign requested address` ke Valkey dan PostgreSQL: port efemeral host habis, karena **setiap permintaan PHP membuka koneksi TCP baru** ke Valkey dan PostgreSQL (tanpa koneksi persisten dan tanpa PgBouncer);
  - status 0: batas waktu klien saat antrean server penuh.

  Di produksi, PgBouncer (PRD 14.1.1 butir 8) dan koneksi Valkey persisten mengatasi yang pertama. Lihat Utang 1.12.
- **Permintaan yang menggantung lama.** Satu permintaan tercatat **3 menit 39 detik** pada percobaan awal. Penyebabnya
  belum dipastikan. pdo_pgsql sendiri sudah memutus koneksi yang tidak menjawab dalam 30 detik (diuji: 30,01 detik).
  Dugaan terkuat adalah koneksi Valkey, yang konfigurasinya tanpa `timeout`/`read_timeout` (batas socket 60 detik dan
  coba ulang). Tiket ini menambah `DB_CONNECT_TIMEOUT` (bawaan 5 detik, diuji 5,01 detik) agar kegagalan PostgreSQL
  lebih cepat dari 30 detik. Batas waktu Valkey dicatat sebagai utang (perlu hati-hati: koneksi queue memakai blocking
  pop Horizon).

## be 2: penulisan audit (pgbench, `tests/beban/audit/`)

Dijalankan sebagai `simrs_app` di dalam kontainer PostgreSQL (soket lokal), 20 detik per kombinasi, ke
`audit.log_aktivitas` dengan trigger rantai hash dan kunci `pg_advisory_xact_lock` (ADR 0003). Tidak ada transaksi gagal.

| Pola | Penulis | transaksi/detik | P50 ms | P95 ms | P99 ms |
|---|---:|---:|---:|---:|---:|
| `tunggal`: satu INSERT, autocommit | 1 | 5.317 | 0,15 | 0,31 | 0,76 |
| | 8 | 4.311 | 1,51 | 3,52 | 5,63 |
| | 32 | 5.242 | 5,91 | 7,17 | 8,65 |
| `kerja-lalu-catat`: kerja 5 ms, lalu INSERT audit di akhir transaksi | 1 | 174 | 5,65 | 6,09 | 7,02 |
| | 8 | 1.376 | 5,70 | 6,44 | 8,26 |
| | 32 | 3.436 | 9,18 | 10,90 | 13,32 |
| `catat-lalu-kerja`: INSERT audit, lalu kerja 5 ms dalam transaksi yang sama | 1 | 174 | 5,68 | 6,28 | 6,98 |
| | 8 | 176 | 45,27 | 46,75 | 48,67 |
| | 32 | 174 | 182,73 | 187,96 | 202,49 |

**Bacaan:**
- **Kunci rantai bukan hambatan bila `catat()` di akhir transaksi.** Pola `kerja-lalu-catat` tetap berskala sampai 3.400 per detik. Itu jauh di atas kebutuhan RS 500 TT: puncak ratusan aksi tercatat per detik.
- **Bila `catat()` dipanggil sebelum kerja lain di transaksi yang sama**, semua penulis audit di seluruh RS antre di satu kunci. Laju terkunci di 1 / lama transaksi (±174 per detik untuk 5 ms), dan latensi naik lurus dengan jumlah penulis (188 ms pada 32 penulis). Aturan CLAUDE.md dan ADR 0003 ("panggil `catat()` di akhir") terbukti wajib. Pelanggarannya baru terlihat di bawah beban.
- **Verifikasi rantai sesudah uji: 702.714 baris.**
  - Sebelum perbaikan, `audit:verifikasi-rantai` **gagal** karena `statement_timeout` 5 detik `simrs_app`, akibat satu query untuk seluruh rentang.
  - Sekarang verifikasi diproses per jendela `urutan` (`AUDIT_VERIFIKASI_JENDELA`, bawaan 50.000): utuh dalam 18 detik (±38 ribu baris per detik).
  - Tes `RantaiAuditTest::test_verifikasi_per_jendela_tetap_memeriksa_sambungan_antarjendela` membuktikan sambungan antarjendela ikut diperiksa.

## Yang belum diukur

- Penyaring cakupan `unit` di daftar kerja (utang backlog lapis 3): semua akun uji bercakupan seluruh RS.
- Hambatan pada titik jenuh (lihat di atas), soak 8 jam, dan 2× beban puncak 1 jam (PRD 14.1.1). Semuanya diukur di
  staging.

## Catatan metode

- **Docker Desktop di Mac tidak dipakai di jalur permintaan.**
  - Percobaan k6 dalam kontainer menghasilkan permintaan yang menggantung 60 detik sampai 6 menit, juga 502, padahal pekerja PHP menganggur. Jalurnya: k6 di kontainer ke port host ke nginx di kontainer ke php-fpm di host.
  - Log nginx menunjukkan `upstream timed out while connecting`: koneksi baru yang menembus batas VM Docker gagal dibuka.
  - Karena itu angka di atas memakai k6 native dan server PHP di host. Hanya basis data dan Valkey yang tetap di VM, dan jalur itu pun menyumbang sebagian error di 200–400 VU.
- **Tes lain di mesin yang sama menaikkan ekor latensi** (P99 puluhan detik pada percobaan pertama). Uji diulang saat mesin tidak menjalankan tes lain; beban rata-rata mesin 2–5.
- **Server bawaan PHP bukan php-fpm/Octane.** Angkanya batas bawah kasar per pekerja, bukan kapasitas server produksi.
