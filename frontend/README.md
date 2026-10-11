# Karsitara SIMRS — Frontend

Vue 3 + TypeScript + Vite (PRD 10.4). Node sesuai `.nvmrc` di akar repo (`nvm use`).

```bash
npm ci
npm run dev        # proksi /api dan /sanctum ke SIMRS_API_URL (bawaan http://127.0.0.1:8000)
npm run build
npm run test:unit  # Vitest
```

Skrip install dependensi diblokir kecuali tercatat di `allowScripts` (`package.json`, npm ≥ 11.19). `esbuild` dan
`fsevents` sengaja ditolak (`false`): biner esbuild sudah datang dari paket opsional `@esbuild/<platform>`
(skrip `postinstall`-nya hanya memeriksa ulang, atau mengunduh biner dari jaringan bila paket itu tidak ada), dan
fsevents terpasang dengan `fsevents.node` siap pakai. Paket baru yang meminta skrip install: tinjau dulu
(`npm install-scripts ls`), lalu `approve` atau `deny`.

## E2E (Playwright)

Hanya ke lokal/CI dengan akun sintetis, atau staging. Jangan pernah dengan data pasien asli.

1. Backend jalan dan sudah di-seed dengan sandi akun sintetis yang diketahui:
   ```bash
   # di backend/, dengan AKUN_SINTETIS_SANDI terisi di .env
   php artisan db:seed --class='App\Modules\Akses\Database\Seeders\AkunSintetisSeeder'
   php artisan serve
   ```
   Seed ulang sebelum tiap run: uji "terkunci" mengunci akun khusus `contoh.terkunci` selama 15 menit.
2. Jalankan:
   ```bash
   npx playwright install chromium   # sekali
   E2E_SANDI=<AKUN_SINTETIS_SANDI> npm run test:e2e
   ```
   Backend atau port lain: `SIMRS_API_URL=http://127.0.0.1:8001 E2E_PORT=5174`. Port Vite harus ada di
   `SANCTUM_STATEFUL_DOMAINS` backend. Backend e2e perlu `TRUSTED_PROXIES=127.0.0.1`: uji
   `jaringan-rs.spec.ts` meniru akses dari luar jaringan RS lewat `X-Forwarded-For` dari proksi Vite. Rate limit masuk 60/menit per IP dan 10/menit per nama pengguna.
