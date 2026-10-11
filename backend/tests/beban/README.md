# Uji beban (k6)

Kerangka uji beban PRD 14.1.1. **Dijalankan manual** ke lokal atau staging dengan akun sintetis, tidak di CI
dan tidak ke produksi. Hasil uji ke staging dilampirkan di catatan rilis.

| Skrip | Isi |
|---|---|
| `masuk-saya.js` | `masuk`: csrf-cookie → masuk → keluar, 6 kali/menit. `saya`: `GET /api/v1/saya` dengan sesi bersama, 800 × `SKALA` VU, ±1 permintaan/detik per VU. Ambang: P95 baca ≤ 200 ms, masuk ≤ 500 ms, error < 0,1%. |

## Menjalankan lokal

Backend di-seed `AkunSintetisSeeder` (sandi dari `AKUN_SINTETIS_SANDI`) dan dijalankan dengan beberapa pekerja:

```bash
# backend/.env selama uji: semua VU memakai satu akun, jadi rate limit per pengguna dinaikkan
# (yang diukur kapasitas server, bukan pemrate limit). Kembalikan setelah selesai.
#   RATE_LIMIT_API_PER_PENGGUNA_PER_MENIT=100000
PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8000 --no-reload   # tanpa --no-reload hanya satu proses

# k6 terpasang:
K6_SANDI=<AKUN_SINTETIS_SANDI> k6 run tests/beban/masuk-saya.js
# atau lewat Docker (tanpa memasang k6):
docker run --rm -v "$PWD/tests/beban:/beban:ro" -e SIMRS_URL=http://host.docker.internal:8000 \
  -e K6_SANDI=<AKUN_SINTETIS_SANDI> grafana/k6:2.3.0 run /beban/masuk-saya.js
```

Variabel: `SIMRS_URL`, `SIMRS_ORIGIN` (harus ada di `SANCTUM_STATEFUL_DOMAINS`), `K6_NAMA_PENGGUNA`
(bawaan `contoh.admisi`, akun tanpa MFA), `K6_SANDI`, `DURASI` (bawaan `2m`), `SKALA` (bawaan `0.05`).
Ringkasan akhir k6 memuat P95 per skenario (`http_req_duration{scenario:…}`).

`php artisan serve` bukan server produksi; angka lokal hanya memastikan skrip dan alur berjalan. Di Mac dengan k6
lewat Docker, sekitar 40 VU sudah memicu `dial: i/o timeout` di server dev (juga untuk `/up`), jadi uji lokal
memakai `-e SKALA=0.0125` (10 VU).
Angka yang dibandingkan dengan anggaran PRD 14.1 adalah hasil ke staging (Octane + PgBouncer).
Klien dari Docker dianggap di luar jaringan RS bila alamatnya tidak ada di `AUTENTIKASI_MFA_JARINGAN_RS`,
sehingga masuk meminta MFA; tambahkan alamat itu di `.env` lokal bila perlu.
