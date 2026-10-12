# Uji beban (k6, pgbench)

Kerangka uji beban PRD 14.1.1. **Dijalankan manual** ke lokal atau staging dengan akun sintetis, tidak di CI
dan tidak ke produksi. Hasil uji ke staging dilampirkan di catatan rilis. Hasil uji lokal tiket 1.12:
`docs/operasional/hasil-uji-beban.md`.

| Skrip | Isi |
|---|---|
| `masuk-saya.js` | `masuk`: csrf-cookie → masuk → keluar, 6 kali/menit. `saya`: `GET /api/v1/saya` dengan sesi bersama, 800 × `SKALA` VU, ±1 permintaan/detik per VU. Ambang: P95 baca ≤ 200 ms, masuk ≤ 500 ms, error < 0,1%. |
| `fase1.js` | Layar fase 1 (tiket 1.12): tiap VU memakai sesi akun sendiri (`beban.0001`…), campuran polling notifikasi ber-ETag, `/saya/menu`, `/saya`, daftar akun, pegawai, lokasi, unit; jeda pikir 0,5–1,5 detik. Skenario `masuk` 30/menit memakai akun sesudah akun sesi. Ambang sama. |
| `audit/*.sql` | pgbench penulisan `audit.log_aktivitas` (ADR 0003): `tunggal`, `kerja-lalu-catat` (pola yang benar), `catat-lalu-kerja` (kunci rantai ditahan selama kerja). |

## Data dan konfigurasi lokal

- Seed `AkunSintetisSeeder` (sandi dari `AKUN_SINTETIS_SANDI`) dan `OrganisasiSintetisSeeder`.
- Untuk `fase1.js`, buat akun `beban.0001`… dengan sandi yang sama. 200 akun pertama diberi peran yang membuka "lihat" `pengaturan.akun`, `sdm.pegawai`, `master_data.organisasi`, bercakupan RS. Skrip pembuatnya **tidak** di repo; cukup factory atau insert sintetis.
- `backend/.env` selama uji. Yang diukur kapasitas server, bukan rate limiter. Kembalikan setelah selesai:

```bash
RATE_LIMIT_API_PER_PENGGUNA_PER_MENIT=100000
RATE_LIMIT_API_PER_IP_PER_MENIT=100000
RATE_LIMIT_LAYAR_KERJA_PER_PENGGUNA_PER_MENIT=100000
AUTENTIKASI_BATAS_PER_IP_PER_MENIT=100000
AUTENTIKASI_BATAS_PER_NAMA_PENGGUNA_PER_MENIT=1000
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8112   # tambah Origin uji (SIMRS_ORIGIN)
```

## Menjalankan lokal

**k6 native** (binari resmi `grafana/k6` di mesin yang sama dengan server):

```bash
php artisan config:cache
cd public && PHP_CLI_SERVER_WORKERS=8 php -d opcache.enable_cli=1 -S 127.0.0.1:8112 \
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php   # dari folder public/
SIMRS_URL=http://127.0.0.1:8112 SIMRS_ORIGIN=http://localhost:8112 K6_SANDI=<sandi> VU=100 DURASI=60s \
  k6 run --summary-export hasil.json tests/beban/fase1.js
```

Variabel:
- `SIMRS_URL`;
- `SIMRS_ORIGIN` (harus ada di `SANCTUM_STATEFUL_DOMAINS`);
- `K6_SANDI`;
- `VU`;
- `DURASI`;
- `AKUN` (bawaan 200);
- `MASUK_PER_MENIT`;
- `DEBUG=1` untuk mencetak isi respons yang gagal.

Untuk `masuk-saya.js`: `K6_NAMA_PENGGUNA` (bawaan `contoh.admisi`) dan `SKALA`.

**Jangan menjalankan k6 dalam kontainer Docker Desktop di Mac** terhadap server di host atau di kontainer lain.
Koneksi baru yang menembus batas VM gagal dibuka di bawah beban (timeout 60 detik, 502), sehingga yang terukur adalah
Docker, bukan SIMRS (`hasil-uji-beban.md`, "Catatan metode"). Di Linux, `docker run --network host grafana/k6:2.3.0`
setara dengan k6 native.

`php -S` bukan server produksi; angka lokal hanya pegangan kasar per pekerja. Angka yang dibandingkan dengan anggaran
PRD 14.1 adalah hasil ke staging (php-fpm/Octane + PgBouncer).

**pgbench audit** (sebagai `simrs_app`, di dalam kontainer/server PostgreSQL agar tidak lewat jaringan Docker):

```bash
docker exec -i simrs-usu-postgres-1 sh -c 'cat > /tmp/kerja-lalu-catat.sql' < tests/beban/audit/kerja-lalu-catat.sql
docker exec simrs-usu-postgres-1 pgbench -n -U simrs_app -d <db_uji> -f /tmp/kerja-lalu-catat.sql -c 32 -j 4 -T 20
php artisan audit:verifikasi-rantai   # rantai harus tetap utuh sesudahnya
```

Jangan ke database yang dipakai tes atau pengembangan sehari-hari. Uji ini menambah ratusan ribu baris audit
yang tidak bisa dihapus `simrs_app`.
