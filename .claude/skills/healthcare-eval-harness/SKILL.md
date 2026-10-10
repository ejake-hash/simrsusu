---
name: healthcare-eval-harness
description: Gerbang uji keselamatan pasien sebelum deploy SIMRS Laravel. Suite test untuk akurasi CDSS, kebocoran data pasien, integritas data klinis, alur klinis, dan integrasi (SATUSEHAT/BPJS/LIS). Memblokir deploy jika ada kegagalan kritis. Gunakan saat menyiapkan CI/CD, sebelum deploy, atau setelah mengubah logika klinis, skema data pasien, atau hak akses.
metadata:
  version: "1.0.0-simrs"
  origin: "Diadaptasi dari ECC healthcare-eval-harness (Health1 Super Speciality Hospitals, Dr. Keyur Patel, MIT) untuk Laravel + PHPUnit/Pest"
---

# Gerbang Uji Keselamatan Pasien (Laravel)

Satu kegagalan KRITIS memblokir deploy. Keselamatan pasien tidak bisa ditawar.

## Kapan dipakai

- Sebelum setiap deploy modul RME/klinis
- Setelah mengubah logika CDSS (interaksi obat, validasi dosis, skor klinis)
- Setelah mengubah skema tabel yang memuat data pasien
- Setelah mengubah autentikasi, Policy/Gate, atau peran
- Saat menyiapkan atau mengubah pipeline CI/CD
- Setelah menyelesaikan merge conflict di modul klinis

## Struktur test

Lima kategori, masing-masing satu testsuite di `phpunit.xml` (berlaku juga untuk Pest):

```xml
<testsuites>
    <testsuite name="Cdss"><directory>tests/Safety/Cdss</directory></testsuite>
    <testsuite name="PhiExposure"><directory>tests/Safety/PhiExposure</directory></testsuite>
    <testsuite name="DataIntegrity"><directory>tests/Safety/DataIntegrity</directory></testsuite>
    <testsuite name="Clinical"><directory>tests/Clinical</directory></testsuite>
    <testsuite name="Integration"><directory>tests/Integration</directory></testsuite>
    <!-- testsuite Unit/Feature bawaan tetap ada -->
</testsuites>
```

Test berjalan terhadap PostgreSQL (bukan SQLite), karena perilaku seperti RLS, `jsonb`,
dan constraint harus sama dengan produksi. Semua data test fiktif dari factory.

### Kategori

**1. Akurasi CDSS (KRITIS — 100%)**
Pasangan interaksi obat (diuji dua arah: A+B dan B+A), aturan dosis, skor klinis dibanding
spesifikasi terbitan, tidak ada false negative, tidak ada kegagalan diam-diam, dan sistem
**gagal tertutup** saat data master tidak terbaca. Kasus uji (pasangan obat, nilai batas) berasal
dari data master yang disetujui Komite Farmasi dan Terapi — Claude tidak mengarang nilai klinis.

```bash
php artisan test --testsuite=Cdss --stop-on-failure --coverage --min=80
```

**2. Kebocoran data pasien (KRITIS — 100%)**
Respons error API, log, URL (tidak ada No. RM/NIK/nama), browser storage, akses tanpa login,
akses lintas unit/peran yang tidak berhak, export/cetak tanpa izin, dan audit trail tercatat.

```bash
php artisan test --testsuite=PhiExposure --stop-on-failure
```

**3. Integritas data (KRITIS — 100%)**
Encounter terkunci tidak bisa diedit (termasuk lewat API dan mass assignment), addendum tertaut,
role aplikasi tidak bisa UPDATE/DELETE `audit_log`, tidak ada cascade delete pada data klinis,
penanganan edit bersamaan, tidak ada record yatim, validasi DPJP tersimpan terpisah dari penulis.

```bash
php artisan test --testsuite=DataIntegrity --stop-on-failure
```

**4. Alur klinis (TINGGI — ≥95%)**
Siklus encounter, template, pencarian obat/diagnosis, cetak dokumen, red flag, alur validasi DPJP.

**5. Integrasi (TINGGI — ≥95%)**
Pemetaan & validasi resource FHIR SATUSEHAT, bridging BPJS, mapping hasil LIS/RIS, penanganan
respons error dan pesan rusak. Panggilan ke layanan luar di-mock dengan `Http::fake()` memakai
contoh respons dari dokumentasi resmi; uji ke sandbox resmi dijalankan terpisah, bukan di setiap push.

Untuk kategori TINGGI, hitung pass rate dari laporan JUnit dengan skrip di folder skill ini:

```bash
vendor/bin/phpunit --testsuite=Clinical --log-junit storage/test-clinical.xml || true
php .claude/skills/healthcare-eval-harness/scripts/pass-rate.php storage/test-clinical.xml 95
```

(Proyek Pest: ganti `vendor/bin/phpunit` dengan `vendor/bin/pest`; opsinya sama.)
Test yang di-skip dihitung tidak lulus, supaya skip tidak menyamarkan masalah.

### Matriks lulus/gagal

| Kategori | Ambang | Jika gagal |
|---|---|---|
| Akurasi CDSS | 100% | **BLOKIR deploy** |
| Kebocoran data pasien | 100% | **BLOKIR deploy** |
| Integritas data | 100% | **BLOKIR deploy** |
| Alur klinis | ≥95% | Peringatan, deploy dengan review |
| Integrasi | ≥95% | Peringatan, deploy dengan review |

### Contoh CI (GitHub Actions — sesuaikan jika RS memakai GitLab CI/Jenkins)

```yaml
name: Gerbang Keselamatan Pasien
on: [push, pull_request]

jobs:
  safety-gate:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16   # samakan dengan versi produksi
        env:
          POSTGRES_DB: simrs_test
          POSTGRES_USER: simrs
          POSTGRES_PASSWORD: secret
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U simrs" --health-interval 5s --health-retries 10
    env:
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_DATABASE: simrs_test
      DB_USERNAME: simrs
      DB_PASSWORD: secret
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'   # samakan dengan versi produksi
          extensions: pdo_pgsql
          coverage: pcov
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate

      # KRITIS — 100%, berhenti di kegagalan pertama
      - name: Akurasi CDSS
        run: php artisan test --testsuite=Cdss --stop-on-failure --coverage --min=80
      - name: Kebocoran data pasien
        run: php artisan test --testsuite=PhiExposure --stop-on-failure
      - name: Integritas data
        run: php artisan test --testsuite=DataIntegrity --stop-on-failure

      # TINGGI — ≥95%, hanya peringatan
      - name: Alur klinis
        run: |
          vendor/bin/phpunit --testsuite=Clinical --log-junit clinical.xml || true
          php .claude/skills/healthcare-eval-harness/scripts/pass-rate.php clinical.xml 95 \
            || echo "::warning::Pass rate alur klinis di bawah 95%"
      - name: Integrasi
        run: |
          vendor/bin/phpunit --testsuite=Integration --log-junit integration.xml || true
          php .claude/skills/healthcare-eval-harness/scripts/pass-rate.php integration.xml 95 \
            || echo "::warning::Pass rate integrasi di bawah 95%"
```

### Anti-pattern

- Melewati test CDSS "karena kemarin lulus"
- Menurunkan ambang KRITIS di bawah 100%
- Menjalankan suite KRITIS tanpa `--stop-on-failure`
- Me-mock mesin CDSS di test integrasi (logika aslinya yang harus diuji)
- Deploy saat gerbang merah
- Menjalankan test keselamatan di SQLite padahal produksi PostgreSQL
- Memakai data pasien asli di test atau seeder
- `markTestSkipped()` di suite KRITIS tanpa tiket yang tercatat

## Contoh

### Menjalankan semua gerbang KRITIS secara lokal

```bash
php artisan test --testsuite=Cdss --stop-on-failure --coverage --min=80 && \
php artisan test --testsuite=PhiExposure --stop-on-failure && \
php artisan test --testsuite=DataIntegrity --stop-on-failure
```

### Contoh test integritas (Pest)

```php
it('menolak edit encounter yang sudah terkunci, termasuk lewat API', function () {
    $encounter = Encounter::factory()->terkunci()->create();
    $dokter = User::factory()->dokter()->create();

    $this->actingAs($dokter)
        ->patchJson(route('api.encounter.update', $encounter), ['anamnesis' => 'ubah'])
        ->assertForbidden();

    expect($encounter->fresh()->anamnesis)->not->toBe('ubah');
});
```

### Contoh laporan

```
## Gerbang Keselamatan: 2026-10-10 [commit abc1234]

| Kategori | Test | Lulus | Gagal | Status |
|---|---|---|---|---|
| Akurasi CDSS | 39 | 39 | 0 | LULUS |
| Kebocoran data pasien | 8 | 8 | 0 | LULUS |
| Integritas data | 12 | 12 | 0 | LULUS |
| Alur klinis | 22 | 21 | 1 | 95,45% LULUS |
| Integrasi | 6 | 6 | 0 | LULUS |

Coverage CDSS: 84% (target 80%+)
Keputusan: AMAN UNTUK DEPLOY
```
