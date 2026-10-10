---
name: healthcare-phi-compliance
description: "Pola perlindungan data pasien dan data pribadi di SIMRS Laravel + PostgreSQL: klasifikasi data, otorisasi (Policy/Gate, RLS opsional), audit trail append-only, penandaan kolom sensitif, dan jalur kebocoran (log, URL, error, browser storage, export). Gunakan saat kode menyentuh data pasien atau pegawai, saat membuat hak akses, atau saat mengaudit kebocoran data. Konteks regulasi: Indonesia (Permenkes 24/2022, UU 27/2022 PDP)."
metadata:
  version: "1.0.0-simrs"
  origin: "Diadaptasi dari ECC healthcare-phi-compliance (Health1 Super Speciality Hospitals, Dr. Keyur Patel, MIT) untuk SIMRS Laravel/PostgreSQL di Indonesia"
---

# Perlindungan Data Pasien (SIMRS Laravel + PostgreSQL)

Pola untuk melindungi data pasien, data pegawai/klinisi, dan data keuangan di SIMRS.
Kerangka regulasi yang berlaku adalah regulasi Indonesia: Permenkes No. 24 Tahun 2022
tentang Rekam Medis dan UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi, ditambah
kebijakan internal RS. Rincian kewajiban hukum ada di skill `regulasi-rme-pdp`; skill
ini berisi pola teknisnya. Abaikan rujukan HIPAA/GDPR dari sumber lain kecuali diminta.

## Kapan dipakai

- Fitur apa pun yang membaca atau menulis data rekam medis / data pasien
- Membuat hak akses, Policy, Gate, middleware, atau peran pengguna
- Mendesain tabel yang memuat data pasien atau data pegawai
- Membuat API, export (Excel/PDF), atau laporan yang memuat data pasien
- Membuat atau mengubah audit trail dan logging
- Review kode untuk kebocoran data

## Tiga lapis

**Klasifikasi** (apa yang sensitif), **akses** (siapa boleh melihat), **audit** (siapa sudah melihat/mengubah).

### Klasifikasi data

**Data pasien sensitif**: semua data yang bisa mengidentifikasi pasien DAN/ATAU berkaitan
dengan kesehatannya: nama, tanggal lahir, alamat, nomor telepon, email, **NIK**, **nomor kartu
BPJS/JKN**, **No. RM**, IHS number SATUSEHAT, diagnosis, tindakan, obat, hasil lab, radiologi,
data klaim/penjaminan, jadwal kunjungan, data rawat inap, atau kombinasinya.

**Data pribadi non-pasien**: data pribadi pegawai dan klinisi, jasa medis/remunerasi dokter,
gaji, rekening bank, data peserta didik, data vendor.

### Akses: Policy/Gate Laravel (utama)

Otorisasi utama dilakukan di aplikasi dengan Policy/Gate. Jangan cek peran langsung di
controller atau Blade. Aturan peran dan supervisi RS Pendidikan ada di skill `rs-pendidikan-akses`.

```php
// app/Policies/PasienPolicy.php
public function view(User $user, Pasien $pasien): bool
{
    // Contoh: akses berdasarkan keterlibatan dalam pelayanan, bukan sekadar peran
    return $user->can('rekam-medis.lihat-semua')
        || $pasien->kunjunganAktif()->whereHas('tim', fn ($q) => $q->where('user_id', $user->id))->exists();
}

// Controller
public function show(Pasien $pasien)
{
    $this->authorize('view', $pasien);
    AuditLog::catat('read', $pasien);
    return new PasienResource($pasien);
}
```

### Akses: Row Level Security PostgreSQL (opsional, lapis kedua)

Pakai RLS hanya jika tim memutuskannya di `CLAUDE.md`. **`auth.uid()` adalah fungsi Supabase
dan tidak ada di proyek ini.** Identitas user dikirim ke PostgreSQL per transaksi:

```php
// Di dalam transaksi per request. WAJIB SET LOCAL (via set_config(..., true)).
DB::transaction(function () use ($user) {
    DB::select("select set_config('app.user_id', ?, true)", [(string) $user->id]);
    // ... query aplikasi ...
});
```

```sql
ALTER TABLE pasien ENABLE ROW LEVEL SECURITY;
CREATE POLICY pasien_baca ON pasien FOR SELECT
  USING (EXISTS (
    SELECT 1 FROM tim_pelayanan t
    WHERE t.pasien_id = pasien.id
      AND t.user_id = current_setting('app.user_id', true)::bigint
  ));
```

- `set_config(..., false)` / `SET` biasa **bocor ke request berikutnya** pada koneksi persisten
  atau pooler (PgBouncer). Selalu `true` (transaction-local).
- Role DB aplikasi tidak boleh superuser atau pemilik tabel (pemilik tabel melewati RLS
  kecuali `FORCE ROW LEVEL SECURITY`).
- Migrasi dan job sistem memakai role terpisah yang didokumentasikan.

### Audit trail (append-only)

Setiap akses baca/tulis/cetak/export data pasien dicatat. Tabel audit tidak boleh diubah atau
dihapus oleh role aplikasi; jangan mengandalkan disiplin kode saja:

```sql
-- Role aplikasi hanya boleh INSERT dan SELECT pada audit_log
REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM simrs_app;
GRANT INSERT, SELECT ON audit_log TO simrs_app;
```

```php
// Kolom minimum audit_log
// id, waktu (timestamptz), user_id, pasien_id, aksi (create|read|update|delete|print|export),
// jenis_resource, resource_id, perubahan (jsonb: before/after, TANPA field rahasia),
// ip_address, user_agent, session_id, alasan (wajib untuk akses darurat/break-the-glass)
enum AksiAudit: string { case Create='create'; case Read='read'; case Update='update';
                         case Delete='delete'; case Print='print'; case Export='export'; }
```

### Jalur kebocoran yang umum di Laravel

- **Pesan error**: jangan ada identitas pasien di exception yang sampai ke user. Produksi wajib `APP_DEBUG=false`.
- **Log** (`storage/logs`, Sentry, dsb.): jangan `Log::info($pasien)` atau dump model/request utuh. Catat ID internal saja.
- **URL**: jangan pakai No. RM, NIK, atau nama di route/query string. Gunakan UUID publik
  (kolom `uuid` + `getRouteKeyName()`), sementara primary key tetap `bigint`.
- **Validasi**: pesan validasi yang memantulkan input (mis. NIK) jangan ditampilkan di log.
- **Browser storage**: jangan simpan data pasien di localStorage/sessionStorage; hati-hati juga dengan state Livewire/Inertia yang ikut terserialisasi ke HTML.
- **Export & cetak**: Excel/PDF adalah kebocoran paling sering. Wajib otorisasi, audit (`export`/`print`), dan watermark nama petugas bila memungkinkan.
- **Queue & job**: payload job tersimpan di DB/Redis. Kirim ID, bukan model berisi data pasien; untuk payload sensitif gunakan job terenkripsi (`ShouldBeEncrypted`).
- **Seeder/test**: jangan pernah memakai data pasien asli. Gunakan factory dengan data fiktif.
- **Kredensial integrasi** (SATUSEHAT, BPJS): hanya di `.env`, tidak pernah di kode atau log.

### Penandaan kolom sensitif

```sql
COMMENT ON COLUMN pasien.nama          IS 'SENSITIF: identitas_pasien';
COMMENT ON COLUMN pasien.tanggal_lahir IS 'SENSITIF: identitas_pasien';
COMMENT ON COLUMN pasien.nik           IS 'SENSITIF: nomor_identitas';
COMMENT ON COLUMN pasien.no_bpjs       IS 'SENSITIF: nomor_identitas';
COMMENT ON COLUMN jasa_medis.nominal   IS 'PRIBADI: keuangan';
```

Untuk kolom yang perlu dienkripsi di level aplikasi, gunakan cast `encrypted` Laravel. Catatan:
kolom terenkripsi tidak bisa dicari/di-index langsung; untuk pencarian NIK simpan juga hash
terpisah (mis. HMAC) yang di-index.

### Checklist sebelum deploy

- [ ] Tidak ada data pasien di pesan error, log, atau stack trace; `APP_DEBUG=false`
- [ ] Tidak ada No. RM/NIK/nama di URL
- [ ] Tidak ada data pasien di browser storage atau state frontend yang tidak perlu
- [ ] Semua route data pasien memakai `auth` + Policy
- [ ] Audit trail tercatat untuk read/write/print/export
- [ ] Role DB aplikasi tidak bisa UPDATE/DELETE `audit_log`
- [ ] Session timeout dikonfigurasi (`SESSION_LIFETIME`) dan logout otomatis di komputer bersama
- [ ] Jika RLS dipakai: semua query di dalam transaksi dengan `set_config(..., true)`, dan test isolasi lulus
- [ ] Seed/test tidak memakai data asli

## Contoh

### Error handling aman

```php
// BURUK — membocorkan identitas pasien
throw new \RuntimeException("Pasien {$pasien->nama} (RM {$pasien->no_rm}) tidak ditemukan");

// BAIK — pesan umum; detail ke log dengan ID internal saja
Log::warning('Pencarian pasien gagal', ['pasien_id' => $pasien->id, 'unit_id' => $unitId]);
abort(404, 'Data tidak ditemukan');
```

### Test isolasi akses (Pest)

```php
it('perawat unit lain tidak bisa melihat pasien', function () {
    $pasien = Pasien::factory()->dirawatDi(Unit::factory()->create())->create();
    $perawatLain = User::factory()->perawat()->create();

    $this->actingAs($perawatLain)
        ->get(route('pasien.show', $pasien))
        ->assertForbidden();
});
```

### Logging aman

```php
// BURUK
Log::info('Memproses pasien', ['pasien' => $pasien->toArray()]);

// BAIK — ID internal saja (bukan No. RM, bukan NIK)
Log::info('Memproses pasien', ['pasien_id' => $pasien->id]);
```
