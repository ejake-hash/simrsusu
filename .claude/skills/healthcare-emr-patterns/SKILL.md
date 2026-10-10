---
name: healthcare-emr-patterns
description: Pola pengembangan Rekam Medis Elektronik (RME) di SIMRS Laravel. Keselamatan pasien, alur kunjungan/encounter (asesmen, CPPT/SOAP, diagnosis, resep, order penunjang), validasi DPJP untuk RS Pendidikan, encounter terkunci + addendum, dan UI entri data klinis. Gunakan saat membangun fitur RME seperti asesmen, CPPT, peresepan, order lab/radiologi, atau tampilan hasil penunjang.
metadata:
  version: "1.0.0-simrs"
  origin: "Diadaptasi dari ECC healthcare-emr-patterns (Health1 Super Speciality Hospitals, Dr. Keyur Patel, MIT) untuk SIMRS Laravel di Indonesia"
---

# Pola Pengembangan RME (SIMRS Laravel)

Mengutamakan keselamatan pasien, akurasi klinis, dan efisiensi kerja petugas.

> Batas peran Claude: Claude membangun struktur, alur, validasi teknis, dan UI. Aturan klinis
> (ambang nilai kritis, daftar interaksi obat, batas dosis, isi template) **berasal dari data
> master yang disetujui Komite Medik/Komite Farmasi dan Terapi**, bukan dikarang atau
> di-hardcode oleh Claude. Jika data master belum ada, buat struktur tabelnya dan tanyakan isinya.

## Kapan dipakai

- Alur kunjungan rawat jalan, IGD, dan rawat inap (asesmen awal, CPPT/SOAP, diagnosis, resep, order)
- Catatan klinis terstruktur + teks bebas
- Modul resep/obat dengan cek alergi, interaksi, dan dosis
- Integrasi CDSS (skor klinis, peringatan)
- Tampilan hasil lab/radiologi dengan penandaan nilai abnormal/kritis
- Audit trail data klinis
- UI entri data klinis

## Prinsip

### Keselamatan pasien lebih dulu

Setiap keputusan desain diuji dengan: "Apakah ini bisa mencelakai pasien?"

- Interaksi obat dan alergi WAJIB memunculkan peringatan, tidak boleh lolos diam-diam
- Nilai lab abnormal WAJIB ditandai secara visual (warna + teks/ikon)
- Tanda vital/nilai kritis WAJIB memicu alur eskalasi
- Tidak ada perubahan data klinis tanpa audit trail (lihat `healthcare-phi-compliance`)
- Jika pengecekan keselamatan gagal karena error teknis (mis. data master tidak terbaca),
  sistem harus **gagal tertutup**: tampilkan bahwa pengecekan tidak bisa dilakukan, jangan anggap aman

### Alur encounter dalam satu halaman

Encounter mengalir vertikal dalam satu halaman, bukan berpindah-pindah tab:

```
Header Pasien (sticky — selalu terlihat)
├── Nama, No. RM, tanggal lahir/umur, jenis kelamin, penjamin (BPJS/umum/asuransi)
├── Alergi, obat aktif, DPJP, peserta didik yang bertugas (jika ada)
│
Alur Encounter (scroll vertikal)
├── 1. Keluhan utama (template terstruktur + teks bebas)
├── 2. Anamnesis / riwayat penyakit sekarang
├── 3. Pemeriksaan fisik (per sistem)
├── 4. Tanda vital (memicu skor klinis otomatis)
├── 5. Diagnosis (pencarian ICD-10 dari tabel master — lihat `koding-icd`)
├── 6. Tindakan (ICD-9-CM dari tabel master)
├── 7. Resep (data master obat + cek alergi/interaksi/dosis)
├── 8. Order penunjang (lab/radiologi)
├── 9. Rencana & tindak lanjut (kontrol, rujuk, rawat inap)
└── 10. Simpan → Tanda tangan / Validasi DPJP → Kunci → Cetak
```

Untuk rawat inap, catatan harian mengikuti format CPPT/SOAP yang dipakai RS.

### Template klinis

```php
// Isi template dikelola dari data master, bukan hardcode
final class TemplateKlinis
{
    public function __construct(
        public readonly int $id,
        public readonly string $nama,            // mis. "Nyeri dada"
        /** @var list<string> */ public readonly array $chips,          // pilihan gejala yang bisa diklik
        /** @var list<string> */ public readonly array $fieldWajib,     // data yang wajib diisi
        /** @var list<string> */ public readonly array $redFlags,       // memicu peringatan yang tidak bisa ditutup
        /** @var list<string> */ public readonly array $saranKodeIcd,   // kode dari tabel master ICD
    ) {}
}
```

Red flag dari template memunculkan peringatan yang terlihat dan tidak bisa ditutup begitu saja,
BUKAN notifikasi toast.

### Keselamatan peresepan

```
Dokter memilih obat
  → Cek alergi pasien
  → Cek interaksi dengan obat aktif
  → Cek interaksi dengan obat lain di encounter ini
  → Validasi dosis terhadap berat badan/umur/fungsi ginjal (aturan dari data master)
  → Interaksi tingkat KRITIS: BLOKIR resep secara default
      → Hanya bisa lanjut dengan override + alasan tertulis
  → Interaksi tingkat MAYOR: tampilkan peringatan, wajib konfirmasi
  → Semua peringatan dan alasan override tercatat di audit trail
```

Tingkat keparahan interaksi berasal dari basis data interaksi obat yang dipakai RS (disetujui
Komite Farmasi dan Terapi), bukan dari penilaian Claude. Resep oleh peserta didik mengikuti aturan
validasi di `rs-pendidikan-akses`.

### Encounter terkunci + addendum

Setelah encounter ditandatangani/divalidasi:
- Tidak boleh diedit — koreksi hanya lewat **addendum** (record terpisah yang tertaut)
- Encounter asli dan addendum sama-sama tampil di timeline pasien
- Audit trail mencatat siapa menulis, siapa memvalidasi, kapan, dan semua addendum

```php
// Penegakan di Policy, bukan hanya menyembunyikan tombol di UI
public function update(User $user, Encounter $encounter): bool
{
    return $encounter->status !== StatusEncounter::Terkunci
        && $user->can('encounter.tulis', $encounter);
}
```

### RS Pendidikan: tulis vs validasi

- Simpan `ditulis_oleh` dan `divalidasi_oleh` sebagai dua field terpisah, plus `divalidasi_pada`
- Catatan peserta didik berstatus "menunggu validasi" sampai DPJP/supervisor memvalidasi
- Daftar catatan yang belum divalidasi harus mudah dilihat DPJP
- Aturan rinci (siapa boleh menulis apa, batas waktu validasi) ada di `rs-pendidikan-akses`

### Pola UI data klinis

**Tanda vital:** nilai saat ini dengan penandaan rentang normal (warna + teks), panah tren
dibanding sebelumnya, skor klinis otomatis (mis. NEWS2) hanya bila semua parameter tersedia,
dan panduan eskalasi sesuai kebijakan RS.

**Hasil lab:** penandaan rentang normal, perbandingan dengan hasil sebelumnya, nilai kritis dengan
peringatan yang wajib dikonfirmasi, waktu pengambilan/analisis, dan order yang masih menunggu.

**Cetak resep/dokumen:** identitas pasien, alergi, diagnosis, detail obat (nama generik + dagang,
dosis, rute, frekuensi, durasi), identitas dan tanda tangan dokter. Setiap cetak tercatat di audit.

### Aksesibilitas UI klinis

- Kontras minimum 4.5:1 (WCAG AA) — pencahayaan ruang pelayanan bervariasi
- Target sentuh minimal 44×44px
- Navigasi keyboard penuh — petugas entri data butuh kecepatan
- Jangan andalkan warna saja — selalu warna + teks/ikon
- Label yang jelas pada semua field form
- Peringatan klinis tidak boleh hilang otomatis — petugas harus mengonfirmasi
- Ikuti `DESIGN.md` di root proyek

### Anti-pattern

- Menyimpan data klinis di localStorage/sessionStorage
- Pengecekan interaksi obat yang gagal diam-diam
- Toast yang bisa ditutup untuk peringatan klinis kritis
- UI encounter berbasis tab yang memecah alur kerja
- Mengizinkan edit pada encounter yang sudah dikunci (termasuk lewat API atau mass assignment)
- Menampilkan data klinis tanpa audit trail
- Data klinis dalam array tanpa tipe — gunakan DTO, enum, dan cast Eloquent
- Ambang klinis atau daftar interaksi obat di-hardcode di kode

## Contoh (data fiktif)

### Alur encounter

```
Dokter membuka encounter pasien RM 00-12-34 (fiktif)
  → Header: "Budi S., 58 th, L — Alergi: Penisilin — Obat aktif: Metformin 500 mg — DPJP: dr. A, Sp.JP"
  → Keluhan utama: template "Nyeri dada"
    → Chip: "substernal", "menjalar ke lengan kiri", "seperti ditekan"
    → Red flag dari template → peringatan tidak bisa ditutup
  → Pemeriksaan: kardiovaskular — "S1 S2 normal, murmur (-)"
  → Tanda vital: nadi 110, TD 90/60, SpO2 94%
    → Sub-skor NEWS2 dari tiga parameter ini: nadi 110 = 1, sistolik 90 = 3, SpO2 94% (skala 1) = 1
    → Skor total BELUM dihitung: laju napas, suhu, kesadaran, dan pemakaian oksigen belum diisi
    → UI menampilkan "Skor belum lengkap" + menandai sistolik ≤90 sebagai parameter merah
  → Diagnosis: cari "infark" di tabel master ICD-10 → dokter memilih kode
  → Resep: dokter memilih obat → CDSS cek terhadap Metformin dan alergi Penisilin
  → Simpan → tanda tangan → encounter terkunci, hanya addendum
```

### Override interaksi obat

```
Dokter meresepkan obat X untuk pasien yang sedang mendapat obat Y
  → Basis data interaksi RS menandai X + Y sebagai KRITIS
  → UI: modal merah memblokir resep
  → Dokter memilih "Override dengan alasan" dan menulis alasannya
  → Alasan + peringatan tersimpan di audit trail
  → Resep diproses dengan catatan override
```

### Catatan peserta didik + validasi DPJP

```
PPDS menulis CPPT pukul 07.30 → status "menunggu validasi"
  → DPJP melihat daftar "Belum divalidasi (3)"
  → DPJP memvalidasi pukul 10.15 → divalidasi_oleh = DPJP, divalidasi_pada = 10.15
  → Catatan terkunci; koreksi berikutnya hanya lewat addendum
```

### Encounter terkunci + addendum

```
Encounter E-2026-0891 divalidasi dr. A pukul 14.30
  → Semua field terkunci — tidak ada tombol edit
  → Tombol "Tambah addendum" tersedia
  → dr. A menambahkan: "Hasil lab diterima — troponin meningkat"
  → Record baru E-2026-0891-A1 tertaut ke encounter asli
  → Timeline menampilkan keduanya dengan waktu masing-masing
```
