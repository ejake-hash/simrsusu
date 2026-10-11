# Dokumentasi Bridging — BPJS (VClaim & Antrol) dan Satu Sehat

> **Catatan lapangan dari aplikasi lain, bukan spesifikasi Karsitara.** Disalin 11 Okt 2026 dari dokumentasi
> aplikasi SIMRS lain yang sudah terhubung ke SATUSEHAT (produksi) dan lulus UAT VClaim. Nomor peserta, RM, SEP,
> rujukan, kode PPK, kode dokter, dan nama faskes **sudah diganti placeholder**. Nama kelas/tabel di bawah milik
> aplikasi sumber, bukan nama di repo ini. Pakai sebagai bukti perilaku API dan daftar jebakan; desain Karsitara
> tetap mengikuti PRD (lihat "Selisih dengan PRD" di bawah). Belum dicocokkan dengan trustmark BPJS atau dokumentasi
> resmi Kemenkes terbaru — perlu verifikasi sebelum dipakai sebagai kontrak.
>
> **Selisih dengan PRD yang tidak boleh ditiru:**
> 1. Kredensial di tabel terenkripsi APP_KEY → PRD: vault/secret store (FR-54-08 langkah 8).
> 2. Payload request/response utuh di log jsonb → mengandung data pasien; PRD 14.2: log integrasi berakses terbatas,
>    bermasa simpan, dan disamarkan di log aplikasi.
> 3. Log ditulis di dalam transaksi bisnis (ikut rollback) → Karsitara: outbox + antrean `integrasi`, setelah commit.
> 4. Satu `location_id` aktif untuk semua Encounter → Karsitara: Encounter.location dari lokasi fisik kunjungan
>    sebenarnya (PRD FR-54-11, FR-49-06).
> 5. SATUSEHAT hanya batch harian 23:59 → Karsitara: kirim setelah catatan disahkan lewat outbox, batch hanya untuk
>    ulang/backfill (PRD 10, 7.49). Ketentuan batas waktu pengiriman SATUSEHAT perlu verifikasi.
> 6. Observation, Procedure, ServiceRequest, DiagnosticReport, ImagingStudy belum ada di aplikasi sumber → wajib di
>    Karsitara (FR-20-04, 7.00.5).


**RS sumber (RS khusus mata)** · PPK BPJS **<kodePPK RS>** · Disusun 10 Okt 2026

Dokumen ini merangkum **seluruh method bridging BPJS dan Satu Sehat** yang
terpasang di aplikasi sumber: endpoint, payload request, bentuk response,
dan jebakan yang sudah terbukti secara empiris di lapangan.

**Cakupan: 9 layanan.** BPJS — VClaim, Antrean Online (Antrol, dua arah), i-Care
JKN, Aplicare, Apotek Online, Rekam Medis elektronik, E-Klaim INA-CBG. Kemenkes —
Satu Sehat (FHIR R4) + katalog KFA.

Dua lembaga, dua gaya teknis yang sangat berbeda:

| | **BPJS** (VClaim + Antrol) | **Satu Sehat** (Kemenkes) |
|---|---|---|
| Gaya API | REST khas, payload bersarang | **FHIR R4** standar internasional |
| Autentikasi | HMAC signature per-request | **OAuth2** client credentials (token ber-TTL) |
| Response | Terenkripsi (AES + LZString) | JSON FHIR polos |
| Pemicu | Saat transaksi berlangsung | **Batch** harian 23:59 + retry 01:00 |
| Kegagalan | Ditolak saat itu juga | Terkumpul di log, bisa di-*retry*/backfill |

> **Sumber kebenaran.** Isi dokumen ini diambil dari **kode yang berjalan**
> (`BpjsVClaimService`, `BpjsAntreanService`, `AntrolMobileController`,
> `BpjsClient`, `SatusehatService`, `SatusehatClient`) plus catatan uji empiris
> ke `apijkn-dev`, sandbox Kemenkes, dan produksi — **bukan** dari trustmark atau
> dokumentasi resmi apa adanya. Beberapa hal terbukti berbeda di lapangan; itu
> ditandai dengan ⚠️ di bawah. Bila menemui galat field, **baca tabel log dulu
> (`bpjs_vclaim_logs` / `satusehat_resource_logs`), jangan menebak.**

---

## Daftar Isi

1. [Pondasi: autentikasi, signature, enkripsi](#1-pondasi)
2. [Konfigurasi & kredensial](#2-konfigurasi--kredensial)
3. [VClaim — Peserta](#3-vclaim--peserta)
4. [VClaim — Rujukan](#4-vclaim--rujukan)
5. [VClaim — SEP](#5-vclaim--sep)
6. [VClaim — Fingerprint & Approval Penjaminan](#6-vclaim--fingerprint--approval-penjaminan)
7. [VClaim — Rencana Kontrol (SKDP) & SPRI](#7-vclaim--rencana-kontrol-skdp--spri)
8. [VClaim — LPK (Lembar Pengajuan Klaim)](#8-vclaim--lpk)
9. [VClaim — Monitoring](#9-vclaim--monitoring)
10. [VClaim — Referensi](#10-vclaim--referensi)
11. [Antrol Sisi A — SIMRS ke BPJS](#11-antrol-sisi-a--simrs-ke-bpjs)
12. [Antrol Sisi B — Mobile JKN ke SIMRS](#12-antrol-sisi-b--mobile-jkn-ke-simrs)
13. [Jebakan BPJS yang sudah terbukti](#13-jebakan-bpjs-yang-sudah-terbukti)
14. [Diagnostik & pemecahan masalah BPJS](#14-diagnostik--pemecahan-masalah-bpjs)

**Satu Sehat (Kemenkes)**

15. [Satu Sehat — pondasi & OAuth2](#15-satu-sehat--pondasi--oauth2)
16. [Satu Sehat — resolusi IHS](#16-satu-sehat--resolusi-ihs)
17. [Satu Sehat — resource FHIR](#17-satu-sehat--resource-fhir)
18. [Satu Sehat — KFA (obat)](#18-satu-sehat--kfa-obat)
19. [Satu Sehat — Location & Organization](#19-satu-sehat--location--organization)
20. [Satu Sehat — sinkronisasi, retry, backfill](#20-satu-sehat--sinkronisasi-retry-backfill)
21. [Satu Sehat — jebakan yang sudah terbukti](#21-satu-sehat--jebakan-yang-sudah-terbukti)
22. [Satu Sehat — diagnostik](#22-satu-sehat--diagnostik)

**Layanan BPJS lainnya**

23. [i-Care JKN](#23-i-care-jkn)
24. [Aplicare — ketersediaan tempat tidur](#24-aplicare--ketersediaan-tempat-tidur)
25. [Apotek Online](#25-apotek-online)
26. [Rekam Medis elektronik ke BPJS](#26-rekam-medis-elektronik-ke-bpjs)
27. [E-Klaim INA-CBG](#27-e-klaim-ina-cbg)

**Rute internal aplikasi**

28. [Rute internal VClaim](#28-rute-internal-vclaim)
29. [Rute internal Antrean](#29-rute-internal-antrean)

---

# BAGIAN I — BPJS (VClaim & Antrol)

Service: `BpjsVClaimService`, `BpjsAntreanService`, `AntrolMobileController`
Pondasi bersama: `Bpjs/BpjsClient.php`.

---

## 1. Pondasi

Seluruh layanan BPJS (VClaim, Antrean, iCare) memakai satu kelas pondasi:
`app/Services/Bpjs/BpjsClient.php`.

### 1.1 Header autentikasi

Setiap request wajib membawa empat header:

| Header | Isi |
|---|---|
| `X-cons-id` | Consumer ID faskes |
| `X-timestamp` | Epoch UTC detik (`time()`) |
| `X-signature` | `base64(hmac_sha256("{consId}&{timestamp}", secretKey))` |
| `user_key` | Kunci layanan (**beda antara VClaim dan Antrean**) |

```php
$signature = base64_encode(hash_hmac('sha256', "{$consId}&{$timestamp}", $secretKey, true));
```

### 1.2 Base URL

```
baseUrl = rtrim(base_url, '/') . '/' . trim(service_name, '/')
```

🚨 **Base URL harus HOST SAJA.** Segmen path (`vclaim-rest`) milik *Service Name*.
Bila path ikut ditulis di Base URL, URL jadi dobel
(`.../vclaim-rest/vclaim-rest/referensi/propinsi`) dan BPJS membalas **halaman 404
ASP.NET** — mirip "endpoint mati", padahal murni salah konfigurasi.

### 1.3 Enkripsi response

Dua layanan berperilaku **berbeda**:

| Layanan | Bentuk response |
|---|---|
| **VClaim** | String terenkripsi → perlu dekripsi |
| **Antrean RS** | Sebagian JSON polos, sebagian terenkripsi (lihat §11) |

Dekripsi dua langkah:

```
step 1 : AES-256-CBC
         key  = hex2bin(sha256(consId + secretKey + timestamp))
         iv   = 16 byte pertama dari key
step 2 : LZString::decompressFromEncodedURIComponent
```

🔑 **`timestamp` yang dipakai mendekripsi harus timestamp request itu sendiri.**
`BpjsClient` menyimpannya (`$lastTimestamp`) dan meregenerasi ulang pada tiap
percobaan retry — karena timestamp adalah bagian dari kunci dekripsi.

### 1.4 Bentuk response & penentuan sukses

```json
{ "metaData": { "code": "200", "message": "Sukses" }, "response": { ... } }
```

- `BpjsClient::parse()` menerima **`metaData` maupun `metadata`** (huruf besar-kecil
  tidak konsisten antar endpoint BPJS).
- `is_success` = `code === '200'` saja.
- ⚠️ **Code `201` tidak selalu berarti gagal.** Contoh: "Peserta Sudah Aproval
  Penjaminan di tanggal … Silahkan Terbitkan SEP" (201) sebenarnya **sukses**.
  Di Log Integrasi ia tetap berlabel merah — itu hanya label.

### 1.5 Pencatatan

Semua panggilan tulis dicatat ke tabel **`bpjs_vclaim_logs`** lewat
`BpjsVClaimService::log()`.

| Kolom | Keterangan |
|---|---|
| `visit_id` | Boleh null (operasi tanpa kunjungan) |
| `action` | `GENERATE_SEP`, `CHECK_RUJUKAN`, dst. |
| `request_payload` / `response_payload` | jsonb |
| `is_success` | **Pakai kolom ini**, bukan `response_payload->metaData->code` |
| `error_message` | Pesan BPJS saat gagal |

⚠️ Untuk response bertipe array, yang tersimpan **hanya node `response`** (tanpa
`metaData`). Jadi `metaData.code` kosong di mayoritas entri — gunakan `is_success`.

⚠️ Log `INSERT_RUJUKAN` ditulis **di dalam** transaksi DB
`createRujukanKeluarCore` → **ikut ter-rollback** saat insert gagal. Untuk
men-debug, panggil endpoint read-only secara langsung.

---

## 2. Konfigurasi & Kredensial

Disimpan di **tabel `integration_configs`** (bukan `.env`), kolom `credentials`
ber-tipe `encrypted:array`. Diatur lewat menu **BRIDGING → Konfigurasi & Status**.

| `system_name` | Keperluan |
|---|---|
| `VCLAIM` | SEP, rujukan, SKDP, SPRI, LPK, monitoring |
| `ANTREAN` | Antrean Online (Antrol) |
| `ICARE` | Riwayat pelayanan peserta |
| `SATUSEHAT` | Kemenkes (di luar lingkup dokumen ini) |

Isi: `base_url`, `configuration.service_name`, `configuration.kode_faskes`,
`credentials.{cons_id, secret_key, user_key}`.

| Lingkungan | Base URL | Service VClaim | Service Antrean |
|---|---|---|---|
| **DEV** | `https://apijkn-dev.bpjs-kesehatan.go.id` | `vclaim-rest-dev` | `antreanrs_dev` |
| **PROD** | `https://apijkn.bpjs-kesehatan.go.id` | `vclaim-rest` | `antreanrs` |

Pembeda cepat: ada tidaknya `-dev` pada base URL dan service name.

> `cons_id` + `secret_key` **sama** untuk semua layanan dalam satu faskes; yang
> berbeda hanya `user_key` dan base/service. Nilai kredensial tidak ditulis di
> dokumen ini — lihat `integration_configs` atau catatan internal.

---

## 3. VClaim — Peserta

### `checkPeserta`

```
GET /Peserta/{type}/{identifier}/tglSEP/{tglSep}
```

`type` = `nik` atau `nokartu`.

**Response (ringkas):**
```json
{ "peserta": {
    "noKartu": "...", "nik": "...", "nama": "...", "pisa": "1",
    "sex": "L", "tglLahir": "<tglLahir>",
    "hakKelas": { "kode": "3", "keterangan": "KELAS III" },
    "jenisPeserta": { "kode": "...", "keterangan": "PBI (APBD)" },
    "provUmum": { "kdProvider": "...", "nmProvider": "..." },
    "statusPeserta": { "kode": "0", "keterangan": "AKTIF" },
    "informasi": { "dinsos": null, "noSKTM": null, "prolanisPRB": null },
    "cob": { "nmAsuransi": null, "noAsuransi": null, "tglTAT": null, "tglTMT": null }
} }
```

🔑 `statusPeserta.kode` ≠ `0` berarti peserta **tidak aktif** — SEP akan ditolak.

---

## 4. VClaim — Rujukan

### 4.1 Pencarian rujukan

| Method | Endpoint | Guna |
|---|---|---|
| `checkRujukanFktp` | `GET /Rujukan/{noRujukan}` | Rujukan dari **FKTP** (faskes 1) |
| `checkRujukan` | `GET /Rujukan/RS/{noRujukan}` | Rujukan dari **RS/FKRTL** |
| `getRujukanFktpByKartu` | `GET /Rujukan/Peserta/{noKartu}` | Rujukan FKTP terakhir |
| `listRujukanFktpByKartu` | `GET /Rujukan/List/Peserta/{noKartu}` | Daftar rujukan FKTP |
| `getRujukanByKartu` | `GET /Rujukan/RS/Peserta/{noKartu}` | Rujukan RS terakhir |
| `listRujukanByKartu` | `GET /Rujukan/RS/List/Peserta/{noKartu}` | Daftar rujukan RS |
| `jumlahSepRujukan` | `GET /Rujukan/JumlahSEP/{jnsRujukan}/{noRujukan}` | `jns`: 1=FKTP, 2=FKRTL |

🚨 **Nomor rujukan harus dicari di DUA endpoint.** Rujukan antar-RS tidak ditemukan
lewat `/Rujukan/{no}`. Pola yang dipakai aplikasi: coba FKTP dulu → bila gagal,
fallback ke `/Rujukan/RS/{no}`. Dari endpoint mana rujukan ditemukan, itu pula yang
menentukan `asalRujukan` ('1' FKTP / '2' FKRTL) saat menerbitkan SEP.

**Response rujukan (ringkas):**
```json
{ "rujukan": {
    "noKunjungan": "...", "tglKunjungan": "2026-09-01",
    "provPerujuk": { "kode": "<kodePPK perujuk>", "nama": "<nama faskes perujuk>" },
    "peserta": { ... },
    "diagnosa": { "kode": "H25.9", "nama": "..." },
    "poliRujukan": { "kode": "MAT", "nama": "MATA" },
    "keluhan": "..."
} }
```

⚠️ Sebagian response membungkus `rujukan` dalam **array** — ambil elemen pertama.

### 4.2 Rujukan keluar

| Method | Endpoint |
|---|---|
| `insertRujukanKeluar` | `POST /Rujukan/2.0/insert` |
| `updateRujukanKeluar` | `PUT /Rujukan/2.0/Update` |
| `deleteRujukanKeluar` | `DELETE /Rujukan/delete` |
| `listRujukanKeluar` | `GET /Rujukan/Keluar/List/tglMulai/{a}/tglAkhir/{b}` |
| `showRujukanKeluar` | `GET /Rujukan/Keluar/{noRujukan}` |
| `listSpesialistikRujukan` | `GET /Rujukan/ListSpesialistik/PPKRujukan/{ppk}/TglRujukan/{tgl}` |
| `listSaranaRujukan` | `GET /Rujukan/ListSarana/PPKRujukan/{ppk}` |

**Payload insert** — dibungkus `{ "request": { "t_rujukan": { … } } }`:

```json
{ "noSep": "...", "tglRujukan": "2026-10-10", "tglRencanaKunjungan": "2026-10-15",
  "ppkDirujuk": "<kodePPK tujuan>", "jnsPelayanan": "2", "catatan": "...",
  "diagRujukan": "H25.9", "tipeRujukan": "0", "poliRujukan": "MAT", "user": "..." }
```

🚨 **Wajib DUA tanggal terpisah:**
- `tglRujukan` = **hari ini** (masa depan → *"Tgl.Rujukan Melebihi Tanggal Sekarang"*)
- `tglRencanaKunjungan` = rencana kunjungan, boleh masa depan
  (kosong → *"Tgl.Rencana Kunjungan Harus Diisi"*)

🚨 Faskes + poli tujuan **harus terdaftar** di `listSpesialistikRujukan`. Faskes
tanpa spesialistik → insert membalas *"Data Tidak Ada"*. `PPKRujuk` wajib **8 digit**.

### 4.3 Rujukan khusus (HD)

| Method | Endpoint |
|---|---|
| `insertRujukanKhusus` | `POST /Rujukan/Khusus/insert` |
| `deleteRujukanKhusus` | `POST /Rujukan/Khusus/delete` |
| `listRujukanKhusus` | `GET /Rujukan/Khusus/List/Bulan/{b}/Tahun/{t}` |

> ⚠️ N/A untuk RS Mata (perpanjangan rujukan khusus hemodialisa).

---

## 5. VClaim — SEP

| Method | Endpoint |
|---|---|
| `generateSep` | `POST /SEP/2.0/insert` |
| `updateSep` | `PUT /SEP/2.0/update` |
| `getSep` | `GET /SEP/{noSep}` |
| `cancelSep` | `DELETE /SEP/2.0/delete` |
| `updateTglPulang` | `PUT /SEP/2.0/updtglplg` |
| `listUpdateTglPulang` | `GET /Sep/updtglplg/list/bulan/{b}/tahun/{t}[/{filter}]` |
| `getSepInternal` | `GET /SEP/Internal/{noSep}` |
| `deleteSepInternal` | `DELETE /SEP/Internal/delete` |
| `getSepForInacbg` | `GET /sep/cbg/{noSep}` |
| `getSepSuplesi` | `GET /sep/JasaRaharja/Suplesi/{noKartu}/tglPelayanan/{tgl}` |
| `getSepKllInduk` | `GET /sep/KllInduk/List/{noKartu}` |

🚨 **Perhatikan huruf besar-kecil — BPJS case-sensitive dan polanya TIDAK konsisten:**

| Pola | Dipakai oleh |
|---|---|
| `/SEP/` | `2.0/insert`, `2.0/update`, `2.0/delete`, `2.0/updtglplg`, `FingerPrint/*`, `Internal/*` |
| `/Sep/` | `pengajuanSEP`, `aprovalSEP`, `persetujuanSEP/list`, `updtglplg/list` |
| `/sep/` | `cbg/{noSep}`, `JasaRaharja/Suplesi/*`, `KllInduk/List/*` |

Ketiganya **benar** pada tempatnya masing-masing. Menyeragamkan = 404.

### 5.1 Payload insert SEP

Dibungkus `{ "request": { "t_sep": { … } } }`:

```json
{
  "noKartu": "<noKartu>",
  "tglSep": "2026-09-23",
  "ppkPelayanan": "<kodePPK RS>",
  "jnsPelayanan": "2",
  "klsRawat": { "klsRawatHak": "3", "klsRawatNaik": "", "pembiayaan": "", "penanggungJawab": "" },
  "noMR": "<noRM>",
  "rujukan": { "asalRujukan": "1", "tglRujukan": "2026-09-01",
               "noRujukan": "<noRujukan>", "ppkRujukan": "<kodePPK perujuk>" },
  "catatan": "...",
  "diagAwal": "H44.3",
  "poli": { "tujuan": "MAT", "eksekutif": "0" },
  "cob": { "cob": "0" },
  "katarak": { "katarak": "0" },
  "jaminan": { "lakaLantas": "0", "noLP": "",
               "penjamin": { "tglKejadian": "", "keterangan": "", "suplesi": { … } } },
  "tujuanKunj": "",
  "flagProcedure": "",
  "kdPenunjang": "",
  "assesmentPel": "",
  "skdp": { "noSurat": "", "kodeDPJP": "" },
  "dpjpLayan": "<kodeDPJP>",
  "noTelp": "08...",
  "user": "..."
}
```

**`jnsPelayanan`**: `1` = Rawat Inap, `2` = Rawat Jalan/IGD.

🚨 **`tujuanKunj` harus string KOSONG, bukan `'0'`.** Terbukti empiris di
apijkn-dev: `'0'` ditolak *"flagProcedure tidak sesuai"* (BPJS menganggap `'0'`
berarti ada prosedur sehingga menuntut `flagProcedure` terisi). Kosong lolos.

Aturan pasangan field:

| `tujuanKunj` | Arti | Field wajib |
|---|---|---|
| `''` | Normal | `flagProcedure`, `kdPenunjang`, `assesmentPel`, `dpjpLayan` **kosong** |
| `'1'` | Prosedur | `flagProcedure` (+ `kdPenunjang`) |
| `'2'` | Konsultasi | `assesmentPel` |

⚠️ `tujuanKunj` 1/2 **hanya diterima di `/SEP/2.0/update`**, bukan di insert.
Insert selalu memaksa Normal.

### 5.2 Response insert

```json
{ "sep": { "noSep": "<noSEP>", "tglSep": "...", "peserta": { … } } }
```

⚠️ Response insert **minim** — tidak memuat flag `katarak`/`cob` yang kita kirim.
Karena itu aplikasi mem-*persist* ulang flag tersebut ke snapshot `visits.sep_data`
agar penanda "*Pasien Operasi Katarak*" tetap muncul saat cetak SEP.

### 5.3 getSep — bentuknya BERBEDA dari insert

🚨 Tiga perbedaan yang sudah menjebak:

1. **Datar, bukan bersarang.** `GET /SEP/{no}` mengembalikan blok SEP **datar** di
   `response` (`penjamin`, `kelasRawat`, `kdStatusKecelakaan` di level atas), bukan
   `response.sep` seperti insert. Dukung keduanya.
2. **Objek, bukan string.** `tujuanKunj`, `flagProcedure`, `kdPenunjang`,
   `assesmentPel` dikirim sebagai `{kode, nama}` → *"Array to string conversion"*
   saat cetak bila tidak diratakan.
3. **Label, bukan kode.** `jnsPelayanan` bisa berupa `"Rawat Inap"` (label) atau
   objek — bukan `"1"`/`"2"`. Harus dikonversi balik ke kode.

Ditangani oleh `AdmisiService::normalizeGetSep()`.

🚨 **`getSep` TIDAK PERNAH mengirim `provPerujuk`.** Survei 60 SEP produksi:
0 dari 60 memuat blok itu. Nama faskes perujuk harus di-resolve dari
`sep_data.noRujukan` lewat `resolveRujukanDetail()` lalu di-*cache*.

### 5.4 Gotcha: filter opsional & trailing slash

`listUpdateTglPulang` dan `listPersetujuanSep` punya segmen `filter` **opsional**.

🚨 **Jangan tinggalkan trailing slash saat filter kosong.** `.../tahun/2026/`
ditolak BPJS dengan *"Transaksi tidak dapat diproses"* — klien hanya mem-`ltrim`
path, tidak mem-`rtrim`. Segmen filter hanya disambung bila benar-benar terisi.

### 5.5 Pembatalan SEP

```json
{ "request": { "t_sep": { "noSep": "...", "user": "..." } } }
```

---

## 6. VClaim — Fingerprint & Approval Penjaminan

| Method | Endpoint |
|---|---|
| `getFingerprint` | `GET /SEP/FingerPrint/Peserta/{noKartu}/TglPelayanan/{tgl}` |
| `listFingerprint` | `GET /SEP/FingerPrint/List/Peserta/TglPelayanan/{tgl}` |
| `fingerprintRandomQuestion` | `GET /SEP/FingerPrint/randomquestion/faskesterdaftar/nokapst/{noKartu}/tglsep/{tgl}` |
| `fingerprintRandomAnswer` | `POST /SEP/FingerPrint/randomanswer` |
| `pengajuanSep` | `POST /Sep/pengajuanSEP` |
| `aprovalSep` | `POST /Sep/aprovalSEP` |
| `listPersetujuanSep` | `GET /Sep/persetujuanSEP/list/bulan/{b}/tahun/{t}` |

🚨 **Path memakai `/Sep/` (huruf kecil), bukan `/SEP/`.** Case sensitif —
`/SEP/pengajuanSEP` gagal.

**Alur approval** saat peserta belum validasi biometrik (poli wajib-FP seperti Mata):

```
POST /Sep/pengajuanSEP   (best-effort)
        ↓
POST /Sep/aprovalSEP     (otoritatif)
        ↓
POST /SEP/2.0/insert     (terbitkan SEP normal)
```

**Payload:**
```json
{ "request": { "t_sep": {
    "noKartu": "...", "tglSep": "...", "jnsPelayanan": "2",
    "jnsPengajuan": "2", "keterangan": "...", "user": "..." } } }
```
`jnsPengajuan`: `1` = backdate, `2` = finger.

⚠️ Response memakai **`metadata`** (huruf kecil) dan `response` berupa **string**
nomor referensi.

⚠️ Pesan *"Peserta Sudah Aproval Penjaminan di tanggal … Silahkan Terbitkan SEP"*
(code 201) = **SUKSES**, bukan gagal.

---

## 7. VClaim — Rencana Kontrol (SKDP) & SPRI

| Method | Endpoint |
|---|---|
| `postSuratKontrol` | `POST /RencanaKontrol/insert` |
| `updateRencanaKontrol` | `PUT /RencanaKontrol/Update` |
| `deleteRencanaKontrol` | `DELETE /RencanaKontrol/Delete` |
| `insertSpri` | `POST /RencanaKontrol/InsertSPRI` |
| `updateSpri` | `PUT /RencanaKontrol/UpdateSPRI` |
| `deleteSpri` | `DELETE /RencanaKontrol/Delete` |
| `getSuratKontrol` | `GET /RencanaKontrol/noSuratKontrol/{no}` |
| `cariSepKontrol` | `GET /RencanaKontrol/nosep/{noSep}` |
| `listRencanaKontrolByKartu` | `GET /RencanaKontrol/ListRencanaKontrol/Bulan/{b}/Tahun/{t}/Nokartu/{k}/filter/{f}` |
| `listRencanaKontrolByTgl` | `GET /RencanaKontrol/ListRencanaKontrol/tglAwal/{a}/tglAkhir/{b}/filter/{f}` |
| `listSpesialistikKontrol` | `GET /RencanaKontrol/ListSpesialistik/JnsKontrol/{j}/nomor/{n}/TglRencanaKontrol/{tgl}` |
| `jadwalDokterKontrol` | `GET /RencanaKontrol/JadwalPraktekDokter/JnsKontrol/{j}/KdPoli/{p}/TglRencanaKontrol/{tgl}` |

🚨 **RencanaKontrol memakai path TANPA VERSI** — berbeda dari SEP/Rujukan.
Terverifikasi empiris:

| Path | Hasil |
|---|---|
| `POST /RencanaKontrol/insert` | ✅ 200 |
| `POST /RencanaKontrol/v2/Insert` | ❌ 404 *"Transaksi tidak dapat diproses"* |
| `POST /RencanaKontrol/2.0/insert` | ❌ HTTP 404 |
| `PUT /RencanaKontrol/Update` | ✅ |
| `PUT /RencanaKontrol/v2/Update` | ❌ 404 |

**Jangan dipindah ke v2 — itu mematikan SKDP/SPRI.**

💡 `insertRencanaKontrol()` hanyalah **alias** `postSuratKontrol()` — endpoint sama.

**Payload Surat Kontrol:**
```json
{ "request": { "noSEP": "...", "kodeDokter": "<kodeDPJP>", "poliKontrol": "MAT",
               "tglRencanaKontrol": "2026-10-20", "user": "...", "formPRB": null } }
```

**Payload SPRI** (endpoint berbeda, **tanpa** `formPRB`):
```json
{ "request": { "noKartu": "...", "nik": "...", "noSep": "...",
               "kodeDokter": "...", "poliKontrol": "...",
               "tglRencanaKontrol": "...", "user": "..." } }
```

🚨 Field bernama **`tglRencanaKontrol`**, bukan `tglRencana`. Salah nama →
*"Parameter format tglRencanaKontrol salah"*.

💡 **"Jadwal Poli tidak ditemukan" saat buat Surat Kontrol biasanya berarti
HARI LIBUR**, bukan bug.

**Response `getSuratKontrol`** memuat SEP induk + rujukan asal:
```
response.sep.provPerujuk.{noRujukan, asalRujukan, tglRujukan, kdProviderPerujuk}
response.{kodeDokter, poliTujuan}
response.sep.diagnosa  →  "H25.9 - Nama diagnosa"
```

🚨 **SEP kontrol**: `rujukan.noRujukan` harus diisi **rujukan ASAL**
(`provPerujuk.noRujukan`), **bukan** nomor surat kontrol. Surat kontrol hanya
ditaruh di blok `skdp.noSurat`. Salah isi → *"Data Rujukan Tidak Ada"*.

---

## 8. VClaim — LPK

| Method | Endpoint |
|---|---|
| `insertLpk` | `POST /LPK/insert` |
| `updateLpk` | `PUT /LPK/update` |
| `deleteLpk` | `DELETE /LPK/delete` |
| `listLpk` | `GET /LPK/TglMasuk/{tgl}/JnsPelayanan/{jns}` |

Dibungkus `{ "request": { "t_lpk": { … } } }`.

💡 `submitKlaim()` adalah **alias backward-compat** dari `insertLpk()` — pengajuan
klaim memang dilakukan lewat LPK.

---

## 9. VClaim — Monitoring

| Method | Endpoint |
|---|---|
| `monitoringKunjungan` | `GET /Monitoring/Kunjungan/Tanggal/{tgl}/JnsPelayanan/{jns}` |
| `monitoringKlaim` | `GET /Monitoring/Klaim/Tanggal/{tgl}/JnsPelayanan/{jns}/Status/{st}` |
| `historiPelayanan` | `GET /monitoring/HistoriPelayanan/NoKartu/{k}/tglMulai/{a}/tglAkhir/{b}` |

⚠️ **Tidak ada endpoint "cek status klaim per No.SEP".** `checkStatusKlaim()`
sengaja mengembalikan pesan pengarah ke `monitoringKlaim(tgl, jns, status)` —
bukan bug, melainkan penanda bahwa BPJS tak menyediakannya.

🚨 **Tidak ada endpoint "daftar rujukan MASUK berdasarkan tanggal".** BPJS hanya
menyediakan lookup per-nomor dan per-peserta. Rujukan keluar punya list-by-tanggal;
rujukan masuk **tidak**.

**Konsekuensi:** rujukan masuk baru terbaca saat **pasien datang membawa nomornya**
dan SEP diterbitkan — bukan saat faskes menerbitkan rujukan. Fitur "Rujukan Masuk"
pernah dibuat lalu **di-rollback** justru karena ini.

---

## 10. VClaim — Referensi

Semua di-*cache* (`refCached`, TTL 1800 detik; data statis 86400 detik).

| Method | Endpoint | TTL |
|---|---|---|
| `refDiagnosa` | `GET /referensi/diagnosa/{q}` | 30 m |
| `refPoli` | `GET /referensi/poli/{q}` | 30 m |
| `refFaskes` | `GET /referensi/faskes/{q}/{jns}` | 30 m |
| `refDokter` | `GET /referensi/dokter/{q}` | 30 m |
| `refDpjp` | `GET /referensi/dokter/pelayanan/{jns}/tglPelayanan/{tgl}/Spesialis/{sp}` | — |
| `refPropinsi` | `GET /referensi/propinsi` | 24 j |
| `refKabupaten` | `GET /referensi/kabupaten/propinsi/{kd}` | 24 j |
| `refKecamatan` | `GET /referensi/kecamatan/kabupaten/{kd}` | 24 j |
| `refDiagnosaPrb` | `GET /referensi/diagnosaprb` | 24 j |
| `refObatPrb` | `GET /referensi/obatprb/{q}` | 30 m |
| `refProcedure` | `GET /referensi/procedure/{q}` | 30 m |
| `refKelasRawat` | `GET /referensi/kelasrawat` | 24 j |
| `refSpesialistik` | `GET /referensi/spesialistik` | 24 j |
| `refRuangRawat` | `GET /referensi/ruangrawat` | 24 j |
| `refCaraKeluar` | `GET /referensi/carakeluar` | 24 j |
| `refPascaPulang` | `GET /referensi/pascapulang` | 24 j |

`testConnection()` memakai `/referensi/propinsi` (ringan & idempoten).

---

## 11. Antrol Sisi A — SIMRS ke BPJS

Service: `BpjsAntreanService` · system `ANTREAN` · service name `antreanrs`.

🔑 **Campuran JSON polos dan terenkripsi.** Referensi dan pembacaan memakai
`getEnc`/`postEnc` (perlu dekripsi); penulisan antrean memakai `post` (JSON polos).

### 11.1 Referensi

| Method | Endpoint | Enkripsi |
|---|---|---|
| `refPoli` | `GET /ref/poli` | ✅ |
| `refDokter` | `GET /ref/dokter` | ✅ |
| `refJadwalDokter` | `GET /jadwaldokter/kodepoli/{poli}/tanggal/{tgl}` | ✅ |
| `refPoliFingerprint` | `GET /ref/poli/fp` | ✅ |
| `refPasienFingerprint` | `GET /ref/pasien/fp/identitas/{jns}/noidentitas/{no}` | ✅ |

### 11.2 Antrean

| Method | Endpoint | Enkripsi |
|---|---|---|
| `addAntrean` | `POST /antrean/add` | — |
| `updateWaktuAntrean` | `POST /antrean/updatewaktu` | — |
| `addAntreanFarmasi` | `POST /antrean/farmasi/add` | — |
| `batalAntrean` | `POST /antrean/batal` | — |
| `getListAntrean` | `POST /antrean/getlistantrean` | ✅ |
| `getSisaAntrean` | `POST /antrean/sisa` | ✅ |
| `getListWaktu` | `POST /antrean/getlisttask` | ✅ |
| `getAntreanByKodebooking` | `POST /antrean/getlisttask` | ✅ |
| `validateBookingCode` | `POST /antrean/getlisttask` | ✅ |

### 11.3 Helper (bukan endpoint)

| Method | Guna |
|---|---|
| `classifyAddResult(result)` | Klasifikasi hasil `addAntrean` → OK / tolak / perlu tindakan |
| `resultReason(result)` | Ambil pesan penolakan BPJS untuk ditampilkan operator |
| `checkQuota(kodePoli, tgl)` | **Alias backward-compat** → `refJadwalDokter()` |

### 11.4 Dashboard & jadwal

| Method | Endpoint |
|---|---|
| `dashboardWaktuTunggu` | `GET /dashboard/waktutunggu/tanggal/{tgl}/waktu/{w}` |
| `dashboardWaktuTungguBulan` | `GET /dashboard/waktutunggu/bulan/{b}/tahun/{t}/waktu/{w}` |
| `updateJadwalDokter` | `POST /jadwaldokter/updatejadwaldokter` |

🚨 **`updatejadwaldokter` hanya MENGUBAH jadwal yang sudah ada di HFIS — bukan
membuat.** Jadwal praktek dibuat oleh admin faskes lewat **portal HFIS**
(web BPJS), bukan dari SIMRS. Berlaku aturan **H-1**: jadwal hari-sama tak bisa
diubah.

### 11.5 Perbedaan penting: DPJP VClaim ≠ Dokter Antrean

Dua daftar dokter yang **terpisah**:

| | Sumber | Dipakai untuk |
|---|---|---|
| **DPJP VClaim** | `GET /referensi/dokter/pelayanan/{jns}/tglPelayanan/{tgl}/Spesialis/{sp}` | SEP & klaim |
| **Dokter Antrean** | `GET /ref/dokter` + `/jadwaldokter/…` (HFIS) | Jadwal Mobile JKN |

⇒ Bisa memilih DPJP di VClaim **tetapi** Antrean membalas *"Jadwal dokter tidak
ditemukan di Poli dan Subspesialis ini"* — karena jadwal praktek di HFIS belum
dibuat. Itu **bukan** kesalahan pemetaan kode.

---

## 12. Antrol Sisi B — Mobile JKN ke SIMRS

Endpoint yang **kita sediakan** untuk dipanggil Mobile JKN.
Controller: `AntrolMobileController` · prefix `/api/v1/antrol`.

🔑 **Autentikasi berbeda dari aplikasi.** Bukan JWT, melainkan header `x-token`
BPJS (middleware `verify-antrol-token`).

| Endpoint | Auth | Guna |
|---|---|---|
| `GET /antrol/token` | publik, *rate-limited* | Terbitkan token (`x-username` + `x-password`) |
| `POST /antrol/status` | x-token | Status antrean |
| `POST /antrol/ambil` | x-token | Ambil nomor antrean |
| `POST /antrol/sisa` | x-token | Sisa antrean |
| `POST /antrol/batal` | x-token | Batal antrean |
| `POST /antrol/checkin` | x-token | Check-in pasien |
| `POST /antrol/pasien-baru` | x-token | Daftar pasien baru |
| `POST /antrol/jadwal-operasi` | x-token | Jadwal operasi (umum) |
| `POST /antrol/jadwal-operasi-pasien` | x-token | Jadwal operasi per pasien |
| `POST /antrol/farmasi/ambil` | x-token | Antrean farmasi |
| `POST /antrol/farmasi/status` | x-token | Status antrean farmasi |

**Bentuk response** (mengikuti kontrak BPJS):
```json
{ "response": { … }, "metadata": { "code": 200, "message": "Ok" } }
```

⚠️ Perhatikan: **`metadata`** huruf kecil, dan `code` bertipe **integer** —
berbeda dari VClaim yang memakai `metaData` dengan `code` string.

Endpoint `/token` diberi *rate-limit* (`throttle:antrol-token`, per-IP) untuk
menahan brute-force kredensial.

### Tiga tingkat pengujian Mobile JKN

| Tingkat | Cara | Status |
|---|---|---|
| 1 | Postman/WS memanggil `/antrol/*` | ✅ bisa sekarang — ini yang dinilai verifikator |
| 2 | Aplikasi Mobile JKN **dev** | ⏳ perlu registrasi HFIS + BPJS mengarahkan backend dev ke endpoint kita |
| 3 | Mobile JKN asli di HP peserta | ❌ hanya setelah go-live produksi |

---

## 13. Jebakan BPJS yang Sudah Terbukti

Daftar ini berasal dari kejadian nyata — bukan kemungkinan teoretis.

| # | Jebakan | Akibat |
|---|---|---|
| 1 | Base URL memuat path | URL dobel → 404 ASP.NET, terlihat seperti endpoint mati |
| 2 | `tujuanKunj = '0'` | Ditolak *"flagProcedure tidak sesuai"* — harus string kosong |
| 3 | `/SEP/pengajuanSEP` | Case salah; yang benar `/Sep/` huruf kecil |
| 4 | `RencanaKontrol` versi `v2`/`2.0` | 404 — endpoint ini **tanpa versi** |
| 5 | `tglRencana` | Harus `tglRencanaKontrol` |
| 6 | SEP kontrol diisi nomor surat kontrol | *"Data Rujukan Tidak Ada"* — harus rujukan asal |
| 7 | Rujukan RS dicari di endpoint FKTP | *"Data Tidak Ditemukan"* — perlu fallback dua endpoint |
| 8 | `getSep` dianggap sama bentuk dengan insert | *"Array to string conversion"*, `jnsPelayanan` salah baca |
| 9 | `getSep` diharap memuat `provPerujuk` | Tidak pernah ada (0/60) — Faskes Perujuk tampil "—" |
| 10 | Code 201 dianggap gagal | Approval penjaminan sebenarnya sukses |
| 11 | Pindah ke v1 | Seluruh keluarga v1 **ditutup sejak 2022-03-31** |
| 12 | Insert rujukan tanpa `tglRencanaKunjungan` | *"Tgl.Rencana Kunjungan Harus Diisi"* |
| 13 | Mengandalkan `metaData.code` di log | Mayoritas kosong — pakai kolom `is_success` |
| 14 | Berharap jadwal HFIS dibuat dari SIMRS | `updatejadwaldokter` hanya UPDATE, berlaku H-1 |
| 15 | Rujuk internal poli beda **hari sama** | BPJS dedup 1 SEP per rujukan per tanggal → balikkan SEP induk |

### Catatan khusus: rujuk internal

Jawaban resmi KC BPJS: rujuk internal = **penerbitan SEP biasa** `/SEP/2.0/insert`,
hanya `poli.tujuan` berbeda dari poli rujukan, dan **hanya untuk kunjungan ke-2
dan seterusnya** (hari berikutnya, rujukan sama). Tidak ada endpoint khusus,
tidak ada `tujuanKunj` khusus, dan **tidak ada** `insertSepInternal`.

---

## 14. Diagnostik & Pemecahan Masalah BPJS

### 14.1 Membaca respons

| Gejala | Artinya |
|---|---|
| HTML 404 `.svc` | Salah **path** (base URL/service name), bukan autentikasi |
| JSON `metaData` 201/401 | Kredensial salah / data tidak ditemukan |
| JSON `metaData` 200 | Sukses |
| cURL error 28 (SSL timeout) | IP `apijkn` bermasalah — pernah diatasi dengan pin `/etc/hosts` ke IP sehat |

### 14.2 Uji manual dengan cURL

```bash
TS=$(date +%s)
SIG=$(printf "%s&%s" "$CONSID" "$TS" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)
curl -s -i "$BASE/$SERVICE/referensi/propinsi" \
  -H "X-cons-id: $CONSID" -H "X-timestamp: $TS" \
  -H "X-signature: $SIG"  -H "user_key: $USERKEY"
```

### 14.3 Membaca log bridging

```php
// Sukses — pakai is_success, BUKAN metaData.code
App\Models\BpjsVClaimLog::where('action','GENERATE_SEP')
    ->where('is_success', true)->latest()->first();

// Query jsonb (PostgreSQL)
App\Models\BpjsVClaimLog::where('request_payload->jnsPelayanan', '1')->get();
```

**Daftar `action`:** `CHECK_PESERTA`, `CHECK_RUJUKAN`, `CHECK_RUJUKAN_FKTP`,
`INSERT_RUJUKAN`, `UPDATE_RUJUKAN`, `DELETE_RUJUKAN`, `GENERATE_SEP`, `UPDATE_SEP`,
`GET_SEP`, `CANCEL_SEP`, `UPDATE_TGL_PULANG`, `DELETE_SEP_INTERNAL`,
`PENGAJUAN_SEP`, `APROVAL_SEP`, `FINGERPRINT_ANSWER`, `INSERT_RENCANA_KONTROL`,
`UPDATE_RENCANA_KONTROL`, `DELETE_RENCANA_KONTROL`, `INSERT_SPRI`, `UPDATE_SPRI`,
`INSERT_LPK`, `UPDATE_LPK`, `DELETE_LPK`, `MONITORING_KUNJUNGAN`.

### 14.4 Uji cepat Antrol

```bash
bash Docs/uat-antrol-demo.sh    # token → status → token salah (read-only)
```

---

---

---

# BAGIAN II — SATU SEHAT (Kemenkes)

Service: `SatusehatService` (≈2.592 baris) · Client: `Satusehat/SatusehatClient.php`
Standar: **FHIR R4**.

---

## 15. Satu Sehat — Pondasi & OAuth2

### 15.1 Host

| Lingkungan | Host |
|---|---|
| **Sandbox** | `https://api-satusehat-stg.dto.kemkes.go.id` |
| **Produksi** | `https://api-satusehat.kemkes.go.id` |

Dipilih lewat `configuration.env` (`sandbox` / `production`).

Tiga basis path:

```
{host}/oauth2/v1      → token
{host}/fhir-r4/v1     → resource FHIR
{host}/kfa-v2         → katalog obat KFA
```

### 15.2 Autentikasi — OAuth2, bukan signature

Berbeda total dari BPJS. Tidak ada HMAC per-request; yang dipakai **bearer token**:

```
POST {oauth}/accesstoken?grant_type=client_credentials
Body (form): client_id, client_secret
```

Token di-*cache* dengan **TTL = `expires_in` − 60 detik** (jaga-jaga terhadap
selisih jam server). Kunci cache mengandung hash `client_id` + `env`, sehingga
berpindah lingkungan tidak memakai token basi.

```php
$cacheKey = 'satusehat:token:' . substr(sha1($clientId . '|' . $env), 0, 16);
```

`getAccessToken()` adalah pintu token (cached); `fetchTokenInfo()` sengaja
**tidak** di-*cache* — dipakai menampilkan status
kredensial (`status: "approved"`) secara live di layar Bridging.

### 15.3 Konfigurasi

Sama seperti BPJS: tabel `integration_configs`, `system_name = SATUSEHAT`.

| Kunci | Isi |
|---|---|
| `credentials.client_id` / `client_secret` | Kredensial OAuth2 |
| `configuration.env` | `sandbox` / `production` |
| `configuration.organization_id` | ID Organization faskes di Satu Sehat |
| `configuration.location_id` | **ID Location aktif — wajib terisi** (lihat §21) |
| `configuration.timeout` | Detik (default 30) |

---

## 16. Satu Sehat — Resolusi IHS

Satu Sehat tidak mengenal nomor RM maupun nomor BPJS. Setiap orang diacu dengan
**IHS number**, dan satu-satunya kunci pencariannya adalah **NIK**.

| Method | Guna |
|---|---|
| `resolvePatientIhs(Patient)` | IHS pasien dari NIK |
| `resolvePractitionerIhs(Employee)` | IHS dokter dari NIK |
| `checkPatientIhs(Patient)` | Cek status tanpa menulis |
| `resolveIhsBatch(limit)` | Resolusi massal |
| `probeIhsByNik(resource, nik)` | Diagnostik langsung |
| `lookupDemographicsByNik(nik)` | Demografi dari NIK |

Hasil di-*cache* di tabel `satusehat_ihs`.

🚨 **Tanpa NIK, data tidak bisa dikirim.** Ini berlaku untuk pasien **dan** dokter.
Dokter tanpa NIK menggagalkan seluruh kunjungan yang ia tangani — penyebab umum
kegagalan parsial pada batch.

---

## 17. Satu Sehat — Resource FHIR

### 17.1 Yang sudah terpasang

| Resource | Isi | Catatan |
|---|---|---|
| **Encounter** | Kunjungan | RJ = `AMB`, IGD = `EMER`, Ranap = `IMP` (+ periode admisi/pulang) |
| **Condition** | Diagnosis utama & sekunder | ICD-10 |
| **Medication** | Obat | **Wajib `kfa_code`** |
| **MedicationRequest** | Peresepan | `status: active`, `intent: order` |
| **MedicationDispense** | Penyerahan obat | |

Dikirim sebagai **Bundle transaction atomik** — resource saling terhubung lewat
`urn:uuid`, dan seluruh bundle diterima atau ditolak bersama.

| Method | Guna |
|---|---|
| `buildVisitBundle(Visit)` | Susun bundle |
| `syncVisitBundle(Visit)` | Kirim — mengembalikan `http_status` + `response` |
| `previewPayloads(Visit)` | Lihat tanpa mengirim |
| `sendEncounter(Visit)` | Kirim Encounter saja |
| `sendCondition(Visit)` | Kirim Condition saja |

💡 `sendEncounter`/`sendCondition` mengirim **satu resource** — dipakai untuk
diagnosis terpisah. Jalur normal memakai bundle atomik di atas.

### 17.2 Yang belum terpasang

Resource ini sudah dikenali kerangkanya tetapi **belum menghasilkan data**:

| Resource | Keperluan | Status |
|---|---|---|
| **Observation** | Tanda vital (TD, nadi, suhu, RR, SpO₂, GCS, antropometri) | ⚠️ **Gap paling mendesak** — sebenarnya wajib fase 1 |
| **Procedure** | Tindakan & operasi (ICD-9-CM) | Belum |
| **ServiceRequest** | Permintaan lab/radiologi | Belum — prasyarat DiagnosticReport |
| **DiagnosticReport** | Hasil lab & radiologi | Belum |
| **ImagingStudy** | Studi pencitraan | Belum |

### 17.3 Eligibilitas pengiriman

Sebuah kunjungan dikirim hanya bila **semua** terpenuhi:

1. Status kunjungan **SELESAI**
2. Belum pernah `SYNCED`
3. Pasien punya **NIK**
4. Ada `doctorExamination.diagnosis_utama`
5. Dokter punya **NIK**

---

## 18. Satu Sehat — KFA (Obat)

```
GET {host}/kfa-v2/products/all?keyword=...&size=15
```

| Method | Guna |
|---|---|
| `searchKfa(keyword)` | Cari kode KFA (membungkus `SatusehatClient::kfaSearch`) |
| `skippedNoKfaItems(Visit)` | Daftar obat yang dilewati karena tanpa KFA |

Command: `php artisan satusehat:isi-kfa`

🚨 **Obat tanpa `kfa_code` DILEWATI — bundle tetap terkirim tanpa baris obat itu,
dan Satu Sehat TIDAK membalas galat.** Dari sisi Kemenkes pengiriman dianggap
sukses; peresepan hanya tampak kosong.

Ini pernah terjadi: 0 dari 425 obat punya `kfa_code` → seluruh peresepan hilang
dari Satu Sehat. **Gap data, bukan bug kode.**

Agar tidak terulang, skip kini **dilaporkan**, bukan dibiarkan senyap:
`buildKfaWarningNote()` mengumpulkan nama obat yang dilewati dan menuliskannya ke
kolom `notes` pada `satusehat_sync_logs` tiap batch; `skippedNoKfaItems($visit)`
menampilkannya per kunjungan.

⚠️ Tetap perhatikan: peringatan ini **bukan** penanda kegagalan. Batch bisa
berstatus SUCCESS sambil melewati seluruh obat.

Format kode KFA: `92xxxxxx` (template) atau `93xxxxxx` (aktual).
System URI: `http://sys-ids.kemkes.go.id/kfa`.
Extension `medicationType`: `NC` (non-compound) / `C` (compound).

---

## 19. Satu Sehat — Location & Organization

| Method | Guna |
|---|---|
| `registerLocation(name, physicalType, saveToConfig)` | Daftarkan lokasi baru |
| `listLocations()` | Daftar lokasi terdaftar |
| `updateLocation(id, name, status, physicalType)` | Ubah |
| `deactivateLocation(id, name, physicalType)` | Nonaktifkan |
| `setActiveLocation(id)` | **Tetapkan lokasi aktif** |

```php
app(App\Services\SatusehatService::class)->setActiveLocation('<uuid-location>');
```

⚠️ `registerLocation` untuk lokasi yang sudah ada membalas `duplicate 20002` —
itu wajar, bukan kegagalan.

⚠️ Jangan mengirim placeholder literal (`<...>`) ke `setActiveLocation`.

---

## 20. Satu Sehat — Sinkronisasi, Retry, Backfill

### 20.1 Penjadwalan

| Waktu | Tugas |
|---|---|
| **23:00** | `satusehat:resolve-ihs` — resolusi IHS pasien/dokter **lebih dulu** |
| **23:59** | `satusehat:batch-sync` — kunjungan SELESAI hari itu (`PENDING`/`FAILED`) |
| **01:00** | `satusehat:batch-sync --retry` — ulangi `PARTIAL`/`FAILED` |

🔑 Urutannya disengaja: IHS di-resolve pukul 23:00 agar saat batch berjalan
pukul 23:59 identitas pasien dan dokter sudah siap. `next_retry_at` dihitung ke
pukul 01:00 **berikutnya** — batch pukul 23:59 tidak boleh menjadwalkan retry ke
waktu yang sudah lewat.

Zona waktu `Asia/Jakarta`, `withoutOverlapping`.
Memerlukan cron sistem: `* * * * * php artisan schedule:run`.

### 20.2 Method

| Method | Guna |
|---|---|
| `batchSync(syncType)` | Sinkron batch (AUTO/MANUAL) |
| `syncVisit(Visit)` | Satu kunjungan |
| `retry(syncLogId)` | Ulangi satu batch |
| `retryLatestUnfinished()` | Ulangi batch terakhir yang belum tuntas |
| `backfillSync(limit, from, to)` | Data historis (maks 5.000/run, default 200) |
| `countBackfillEligible(from, to)` | Hitung sisa yang layak |
| `backfillEligibleQuery(from, to)` | Query mentah kunjungan yang layak (untuk audit) |
| `dashboardStats(from, to)` | Statistik layar Bridging |
| `testConnection()` | Uji koneksi |

### 20.3 Backfill lewat antarmuka

Dashboard Satu Sehat → tombol **Backfill** → modal dengan pratinjau jumlah yang
layak, rentang tanggal opsional, dan batas per-run. Klik berulang sampai sisa 0.
Endpoint `POST /integrasi/satusehat/backfill` (izin `integrasi.write`).

⚠️ Operasi ini panjang. Batas waktu sudah disesuaikan: `@set_time_limit(0)` di sisi
server; di sisi antarmuka sync-manual & retry 10 menit, backfill 30 menit —
tanpa itu, batas bawaan 15 detik memutus lebih dulu dan memunculkan "gagal" palsu
yang memancing klik ganda.

---

## 21. Satu Sehat — Jebakan yang Sudah Terbukti

| # | Jebakan | Akibat |
|---|---|---|
| 1 | **`location_id` kosong** | **Setiap** bundle ditolak HTTP 400 *"Element not found: Encounter.location (RuleNumber: 10120)"* → 0 terkirim, 100% gagal |
| 2 | Obat tanpa `kfa_code` | Dilewati — peresepan kosong di Satu Sehat **tanpa galat dari Kemenkes**; terbaca hanya lewat `notes` batch |
| 3 | Pasien/dokter tanpa NIK | IHS tak bisa di-resolve → kunjungan gagal |
| 4 | Simpan form Konfigurasi dengan field Location kosong | Dulu **menghapus** `location_id` yang sudah di-set (konfigurasi di-*replace*) — sudah diperbaiki jadi *merge* |
| 5 | Bundle ditolak sebelum masuk | Dulu alasannya **tidak tersimpan** → `retry()` tak menemukan apa pun dan mati total — kini ditulis sebagai baris `Bundle` di log |
| 6 | Batas waktu antarmuka 15 detik | Backfill tampak "gagal" padahal berjalan → klik ganda |
| 7 | Menyimpulkan dari warning KFA | Warning "5 obat tanpa KFA" **bukan** penyebab gagal-total; itu hanya melewati baris obat |

### Catatan tentang jebakan #1

Ini pernah menyebabkan tiga hari tanpa pengiriman sama sekali. Yang menyesatkan:
dua hipotesis lain terasa masuk akal (IHS bermasalah, warning KFA) dan keduanya
**salah**. Hanya `syncVisitBundle()` yang menampilkan `OperationOutcome` asli dari
Kemenkes — di situlah alasan sebenarnya terbaca.

---

## 22. Satu Sehat — Diagnostik

### 22.1 Melihat alasan penolakan

```php
$svc = app(App\Services\SatusehatService::class);
$r = $svc->syncVisitBundle($visit);
// $r['http_status'] + $r['response'] = OperationOutcome asli dari Kemenkes
```

🔑 Ini **satu-satunya** cara melihat alasan ketika bundle ditolak seluruhnya.

### 22.2 Memeriksa prasyarat

```php
$svc->testConnection();              // kredensial & koneksi
$svc->probeIhsByNik('Patient', $nik);
$svc->checkPatientIhs($patient);
$svc->skippedNoKfaItems($visit);     // obat yang dilewati
$svc->previewPayloads($visit);       // lihat bundle tanpa mengirim
```

### 22.3 Tabel log

| Tabel | Isi |
|---|---|
| `satusehat_sync_logs` | Ringkasan batch (`sent`, `failed`, `sync_type`) |
| `satusehat_resource_logs` | Per-resource, termasuk `error_message` |
| `satusehat_ihs` | Cache IHS |

### 22.4 Membaca kode galat Kemenkes

| Kode | Arti |
|---|---|
| `10120` | Elemen wajib tidak ada (mis. `Encounter.location`) |
| `20013` | Resource tidak valid |
| `20002` | Duplikat (mis. Location sudah terdaftar) — **wajar** |

---

# BAGIAN III — LAYANAN BPJS LAINNYA

Selain VClaim dan Antrol, ada **lima layanan BPJS lain** yang terpasang. Semuanya
memakai pondasi `BpjsClient` yang sama (§1), dengan `system_name` masing-masing di
`integration_configs`.

| Layanan | `system_name` | Guna | Enkripsi response |
|---|---|---|---|
| **i-Care JKN** | `ICARE` | Riwayat pelayanan peserta lintas faskes | ✅ |
| **Aplicare** | `APLICARE` | Ketersediaan tempat tidur | — |
| **Apotek Online** | (VClaim) | Resep kronis/PRB ke apotek | ✅ |
| **Rekam Medis** | `REKAM_MEDIS` | Kirim RM elektronik ke BPJS | khusus (§26) |
| **E-Klaim INA-CBG** | `INACBGS` | Grouper & klaim | khusus (§27) |

---

## 23. i-Care JKN

Service: `BpjsIcareService` · `system_name = ICARE`

| Method | Endpoint |
|---|---|
| `riwayatFkrtl(param, kodeDokter)` | `POST /api/rs/validate` |
| `riwayatForVisit(visitId)` | pembungkus — resolve No.Kartu + kode DPJP dari kunjungan |
| `testConnection()` | `POST /api/rs/validate` |

**Payload:**
```json
{ "param": "<noKartu|NIK>", "kodedokter": "<kodeDPJP>" }
```

**Response:** `{ "url": "https://..." }` — tautan **viewer** i-Care, bukan data
mentah. Riwayat ditampilkan oleh BPJS di halaman itu.

🔑 **Akses i-Care adalah akses PHI** (riwayat kesehatan pasien di faskes lain).
Karena itu `riwayatFkrtl` menerima `auditMeta` yang ikut tersimpan di
`request_payload` sebagai jejak audit **beserta bukti informed consent**. Jangan
memanggilnya tanpa dasar.

---

## 24. Aplicare — Ketersediaan Tempat Tidur

Service: `BpjsAplicareService` · `system_name = APLICARE` · response **JSON polos**

| Method | Endpoint |
|---|---|
| `refKelas()` | `GET /ref/kelas` |
| `pushRoom(Room)` | `POST /bed/update/{kodePPK}` |
| `createRoom(Room)` | `POST /bed/create/{kodePPK}` |
| `deleteRoom(kodekelas, koderuang)` | `POST /bed/delete/{kodePPK}` |
| `readBeds(start, limit)` | `GET /bed/read/{kodePPK}/{start}/{limit}` |
| `syncAll()` | Rekonsiliasi seluruh ruang aktif |

**Helper (bukan endpoint):**

| Method | Guna |
|---|---|
| `refKelasOptions()` | Daftar kelas siap pakai untuk dropdown pemetaan |
| `buildBedPayload(Room)` | Susun payload bed dari data ruang |

**Pemicu otomatis:** perubahan ketersediaan bed mendorong Job `PushAplicareRoom`.
Command `aplicare:sync` dipakai untuk rekonsiliasi.

⚠️ Ruang **tanpa `bpjs_kelas_code` dilewati** — Aplicare menuntut `kodekelas`
valid. Yang dilewati dikembalikan sebagai `skipped`.

Kegagalan tidak menggagalkan operasi rawat inap; cukup tercatat di
`bpjs_aplicare_logs`.

> Relevansi untuk RS Mata: terbatas (kapasitas ranap kecil), tetapi tetap wajib
> dilaporkan ke BPJS bila ada tempat tidur terdaftar.

---

## 25. Apotek Online

Service: `BpjsApotekOnlineService` — untuk resep **kronis/PRB** yang ditebus di
apotek jejaring.

### Referensi

| Method | Endpoint |
|---|---|
| `refDpho()` | `GET /referensi/dpho` |
| `refObat(jenisObat, tglResep, filter)` | `GET /referensi/obat/...` |
| `refPoli(q)` | `GET /referensi/poli/{q}` |
| `refSpesialistik()` | `GET /referensi/spesialistik` |
| `refSettingApotek(kodeApotek)` | `GET /referensi/settingppk/read/{kode}` |

### Transaksi

| Method | Endpoint |
|---|---|
| `cariSep(noSep)` | `GET /sep/{noSep}` |
| `daftarResep(body)` | `GET /daftarresep` |
| `insertResep(body)` | `POST /sjpresep/v3/insert` |
| `insertObatNonRacikan(body)` | `POST /obatnonracikan/v3/insert` |
| `insertObatRacikan(body)` | `POST /obatracikan/v3/insert` |
| `monitoringKlaim(bulan, tahun, jnsObat, status)` | `GET /monitoring/klaim/...` |

🔑 **Urutannya berjenjang:** terbitkan SJP resep dulu (`/sjpresep/v3/insert`), baru
kirim detail obat (non-racikan/racikan) yang merujuk ke SJP tersebut.

⚠️ Obat harus terdaftar di **DPHO** (Daftar Plafon Harga Obat). Cek lewat
`refDpho()`/`refObat()` sebelum mengirim.

---

## 26. Rekam Medis Elektronik ke BPJS

Service: `Bpjs/BpjsRekamMedisService` · `system_name = REKAM_MEDIS`

| Method | Endpoint |
|---|---|
| `insertForVisit(visitId, force)` | `POST /eclaim/rekammedis/insert` |
| `batchSend(mode, limit)` | Kirim massal |
| `buildBundle(Visit)` | Susun paket RM |
| — | `GET /serial` (nomor seri) |

### 26.1 Enkripsi khusus — berbeda dari VClaim

`Bpjs/BpjsRmCrypto` memakai urutan **tiga langkah**:

```
1. JSON
2. compress  gzip
3. encrypt   AES, key = consId + secretKey + koders   ← koders = kode RS (PPK)
```

🚨 **Kunci memuat `koders` (kode PPK)** — berbeda dari VClaim yang memakai
`consId + secretKey + timestamp`.

⚠️ Dokumentasi UAT BPJS hanya menyebut *"gZip"* + *"encrypt"* **tanpa merinci mode
AES**. Implementasi memakai IV = 16 byte pertama `keyHash`. Bila BPJS menolak,
varian kompresi bisa diganti lewat konstanta: `gzencode` (gzip, default) ·
`gzcompress` (zlib) · `gzdeflate` (raw).

---

## 27. E-Klaim INA-CBG

Service: `InaCbgsService` · `system_name = INACBGS`

🔑 **Bukan API BPJS online.** Ini memanggil `ws.php` aplikasi **E-Klaim lokal**
(terpasang di jaringan RS), dengan enkripsi **AES-256-CBC + HMAC** sendiri.

| Method | Guna |
|---|---|
| `newClaim(data, claimId)` | Buat klaim baru |
| `setClaimData(data, claimId)` | Isi data klaim |
| `grouper(nomorSep, stage, specialCmg)` | Jalankan grouper INA-CBG |
| `claimFinal(nomorSep)` | Finalkan klaim |
| `getClaimStatus(nomorSep)` | Status klaim |
| `getClaimData(nomorSep)` | Ambil data klaim |
| `sendClaimOnline(nomorSep)` | Kirim klaim daring |
| `reeditClaim(nomorSep)` | Buka kembali untuk koreksi |
| `deleteClaim(nomorSep)` | Hapus klaim (`delete_claim`) |
| `sendClaimIndividual(nomorSep)` | Kirim satu klaim (`send_claim_individual`) |
| `sendClaimCollective(startDt, stopDt, jenisRawat, dateType)` | Kirim kolektif per rentang (`send_claim`) |

### Berkas pendukung klaim

| Method | Operasi WS |
|---|---|
| `fileUpload(nomorSep, fileClass, fileName, base64)` | `file_upload` |
| `fileGet(nomorSep)` | `file_get` |
| `fileDelete(nomorSep, fileName)` | `file_delete` |

Berkas dikirim sebagai **base64**; `fileClass` menandai jenis dokumen pendukung.

💡 Seluruh operasi melewati satu pintu: `callWs(method, data, claimId, visitId)` —
yang menangani enkripsi, HMAC, dan pencatatan. Menambah operasi E-Klaim baru cukup
memanggilnya dengan nama method WS yang sesuai.

⚠️ `grouper` punya **dua tahap** (`stage` 1 dan 2). `specialCmg` diisi hanya untuk
kasus dengan CMG khusus.

⚠️ Respons WS bisa **HTTP 200 tetapi gagal** — periksa `data.data.success`, bukan
status HTTP (pernah menyebabkan "false success").

---

---

# BAGIAN IV — RUTE INTERNAL APLIKASI

Bagian sebelumnya menjelaskan endpoint **milik BPJS/Kemenkes**. Bagian ini
menjelaskan rute **milik aplikasi kita sendiri** yang membungkusnya — inilah yang
dipanggil antarmuka dan dilihat operator.

> **Kenapa dipisah.** Saat sesuatu gagal, pertanyaan pertama adalah: gagal di sisi
> kita atau di sisi BPJS? Memetakan dua lapis ini membuat jawabannya cepat
> ketemu. Semua rute di bawah ber-prefiks `/api/v1`.

---

## 28. Rute internal VClaim

Tiga kelompok dengan tujuan berbeda:

| Prefiks | Jumlah | Dipakai oleh |
|---|---|---|
| `/vclaim/*` | 68 | **Portal VClaim** — layar terpadu operator |
| `/integrasi/vclaim/*` | 26 | Menu **Bridging** — uji & operasi teknis |
| `/antrol/*` | 11 | **Mobile JKN** (sisi B, lihat §12) |

### 28.1 Portal VClaim — `/vclaim/*`

Layar kerja harian. Controller: `VClaimPortalController`.

**Daftar & detail**
| Rute | Guna |
|---|---|
| `GET /vclaim/pasien` | Daftar pasien BPJS hari ini / riwayat |
| `GET /vclaim/pasien/{visitId}` | Detail + saran rujukan internal |
| `PUT /vclaim/pasien/{id}` | Perbarui data pasien |
| `GET /vclaim/dokumen-manual` | Dokumen tanpa kunjungan |

**Pencarian (lookup)**
| Rute | Guna |
|---|---|
| `POST /vclaim/cek-peserta` · `/vclaim/lookup-nik` | Peserta by kartu/NIK |
| `POST /vclaim/cek-rujukan` · `/vclaim/rujukan-by-kartu` | Rujukan |
| `POST /vclaim/cek-surat-kontrol` | Surat kontrol |
| `GET /vclaim/lookup/sep` · `/rujukan` · `/surat-kontrol` | Cari dokumen by nomor |
| `GET /vclaim/lookup/rencana-kontrol-by-sep` | Kontrol dari No.SEP |
| `GET /vclaim/pasien/{visitId}/histori-pelayanan` | Riwayat lintas faskes |

**SEP**
| Rute | Guna |
|---|---|
| `POST` · `PUT` · `DELETE` `/vclaim/sep` | Terbitkan · ubah · batalkan |
| `PUT /vclaim/sep/tgl-pulang` | Perbarui tanggal pulang |
| `POST /vclaim/sep/approval-finger` | Approval penjaminan (§6) |
| `POST /vclaim/pasien/{visitId}/konversi-sep-ri` · `…/konversi-sep-rj` | Konversi RJ ⇄ RI |
| `GET /vclaim/sep-updtglplg-list` · `/vclaim/sep-updtglplg-last/{noSep}` | Riwayat ubah tgl pulang |

**SEP backdate** — alur persetujuan bertahap
| Rute | Guna |
|---|---|
| `GET` · `POST` `/vclaim/sep-backdate` | Daftar · ajukan |
| `POST /vclaim/sep-backdate/pengajuan` | Kirim pengajuan ke BPJS |
| `POST /vclaim/sep-backdate/{id}/approval` | Setujui |
| `POST /vclaim/sep-backdate/{id}/terbitkan` | Terbitkan setelah disetujui |
| `GET /vclaim/persetujuan-sep` | Daftar persetujuan BPJS |

**Rujukan · Surat Kontrol · SPRI**
| Rute | Guna |
|---|---|
| `POST` · `PUT` · `DELETE` `/vclaim/rujukan` | Rujukan keluar |
| `GET /vclaim/rujukan/spesialistik` · `/sarana` | Referensi tujuan |
| `GET /vclaim/rujukan-keluar-bpjs` · `/{noRujukan}` | Daftar · detail |
| `POST` · `PUT` · `DELETE` `/vclaim/surat-kontrol` | SKDP |
| `POST /vclaim/spri` · `PUT` · `DELETE` `/vclaim/spri/{spriId}` | SPRI |
| `GET /vclaim/kontrol/poli` · `/list-spesialistik` · `/jadwal-dokter` | Referensi kontrol |
| `GET /vclaim/rencana-kontrol-list` · `POST /vclaim/surat-kontrol-by-kartu` | Daftar |

**Cetak** — dua varian: by kunjungan dan by nomor
| Rute | Keluaran |
|---|---|
| `GET /vclaim/sep/cetak/{visitId}` | PDF |
| `GET /vclaim/sep/cetak-html/{visitId}` | HTML-print (§lihat catatan) |
| `GET /vclaim/sep/cetak-html-by-no/{noSep}` | HTML, tanpa kunjungan |
| `GET /vclaim/surat-kontrol/cetak-html/{visitId}` · `…/cetak-html-by-no/{no}` | SKDP |
| `GET /vclaim/spri/{spriId}/cetak-html` | SPRI |
| `GET /vclaim/rujukan/{rujukanId}/cetak-html` · `/vclaim/rujukan/cetak-html-by-no/{no}` | Rujukan |

💡 **HTML-print vs PDF.** Untuk cetakan yang harus pas ukuran kertasnya, dipakai
HTML-print (`window.print()` + `@page size`) — Chrome menghormati `@page`,
sementara penampil PDF mengabaikannya saat memilih kertas.

**Lain-lain**
| Rute | Guna |
|---|---|
| `PUT /vclaim/kunjungan/{visitId}/dokter` | Ubah DPJP |
| `POST /vclaim/diagnosa/{visitId}/tarik` · `PUT /vclaim/diagnosa/{visitId}` | Diagnosa |
| `GET /vclaim/sep-suplesi` · `/vclaim/sep-kll-induk` · `/vclaim/kll-induk-local` | Jasa Raharja / KLL |
| `GET /vclaim/fingerprint-list` | Daftar fingerprint |
| `GET /vclaim/referensi/{jenis}` | Referensi |
| `POST /vclaim/manual` · `GET /vclaim/manual/context` · `POST /vclaim/manual/finger` | Mode manual |

### 28.2 Mode manual (non-bridging)

`VClaimManualController::dispatch` melayani **9 operasi** lewat satu pintu,
dengan dua pengaman:

```php
isWriteOp($op)   // /\.(insert|update|delete|updtglplg|delete_internal)$/
isProdBpjs()     // deteksi lingkungan produksi
manualWriteAllowed()
```

🚨 **Operasi tulis diblokir di produksi** kecuali diizinkan eksplisit. Mode manual
ditujukan untuk UAT dan pemulihan, bukan operasional harian.

### 28.3 Menu Bridging — `/integrasi/vclaim/*`

Untuk uji teknis & operasi administratif. Butuh izin `integrasi.read`;
yang mengubah data butuh `integrasi.write`.

| Rute | Pemetaan |
|---|---|
| `POST /integrasi/vclaim/cek-peserta` | `checkPeserta` |
| `POST /integrasi/vclaim/cek-rujukan` | `checkRujukan` / `checkRujukanFktp` |
| `GET /integrasi/vclaim/rujukan-peserta/{noKartu}` | `getRujukanByKartu` |
| `POST` · `PUT` · `DELETE` `/integrasi/vclaim/sep` | `generateSep` / `updateSep` / `cancelSep` |
| `POST /integrasi/vclaim/pengajuan-sep` · `/aproval-sep` | §6 |
| `POST /integrasi/vclaim/lpk` | `insertLpk` |
| `GET /integrasi/vclaim/monitoring/{jenis}` | §9 |
| `GET /integrasi/vclaim/referensi/{jenis}` | §10 |
| `PUT /integrasi/vclaim/rujukan` | `updateRujukanKeluar` |
| `GET /integrasi/vclaim/rujukan/spesialistik` · `/sarana` | §4.2 |
| `GET` · `POST` · `DELETE` `/integrasi/vclaim/rujukan-khusus` | §4.3 |
| `GET /integrasi/vclaim/sep-internal/{noSep}` · `DELETE /integrasi/vclaim/sep-internal` | §5 |
| `GET /integrasi/vclaim/rencana-kontrol` · `/spesialistik` · `/jadwal-dokter` | §7 |
| `GET /integrasi/vclaim/sep-suplesi` | §5 |
| `GET /integrasi/vclaim-log` | Log bridging |

⚠️ `GET /vclaim/referensi/{jenis}` sengaja **ada di dua tempat**: versi Portal
tidak menuntut `integrasi.read`, supaya operator pendaftaran tetap bisa mencari
diagnosa/poli tanpa diberi izin menu Bridging.

---

## 29. Rute internal Antrean

Prefiks `/antrean/*` — **14 rute**, membungkus Antrol sisi A (§11).
Jangan tertukar dengan `/antrol/*` (sisi B, §12) atau `/antrean-tv/*` (layar TV).

| Rute | Pemetaan |
|---|---|
| `POST /antrean/add` | `addAntrean` |
| `POST /antrean/updatewaktu` | `updateWaktuAntrean` |
| `POST /antrean/batal` | `batalAntrean` |
| `POST /antrean/list` · `/antrean/sisa` | `getListAntrean` · `getSisaAntrean` |
| `POST /antrean/validate-booking` · `/antrean/by-booking` | `validateBookingCode` · `getAntreanByKodebooking` |
| `GET /antrean/ref-poli` · `/antrean/ref-dokter` | Referensi HFIS |
| `GET /antrean/jadwal-hfis/{kodepoli}/{tanggal}` | `refJadwalDokter` |
| `GET /antrean/ref-poli-fp` · `/antrean/ref-pasien-fp/{jenis}/{no}` | Fingerprint |
| `GET /antrean/dashboard/{jenis}` · `/antrean/waktu-tunggu-rekap` | Dashboard |

---

## Peta Berkas

| Berkas | Isi |
|---|---|
| `app/Services/Bpjs/BpjsClient.php` | Signature, header, retry, dekripsi |
| `app/Services/BpjsVClaimService.php` | Seluruh method VClaim |
| `app/Services/BpjsAntreanService.php` | Antrol sisi A |
| `app/Services/AntrolMobileService.php` | Antrol sisi B (logika) |
| `app/Http/Controllers/AntrolMobileController.php` | Antrol sisi B (endpoint) |
| `app/Services/VClaimPortalService.php` | Portal VClaim (layar terpadu) |
| `app/Services/AdmisiService.php` | Penyusunan payload SEP, normalisasi getSep |
| `app/Models/BpjsVClaimLog.php` | Log bridging BPJS |
| `app/Services/BpjsIcareService.php` | i-Care JKN |
| `app/Services/BpjsAplicareService.php` | Aplicare (tempat tidur) |
| `app/Services/BpjsApotekOnlineService.php` | Apotek Online |
| `app/Services/Bpjs/BpjsRekamMedisService.php` · `BpjsRmCrypto.php` | RM elektronik + enkripsi khusus |
| `app/Services/InaCbgsService.php` | E-Klaim INA-CBG (ws.php lokal) |
| `app/Services/AntrolBuilderService.php` · `AntrolTokenService.php` | Antrol sisi B: penyusun & token |
| `app/Services/Satusehat/SatusehatClient.php` | OAuth2, host, KFA, pembungkus FHIR |
| `app/Services/SatusehatService.php` | Bundle FHIR, IHS, Location, sync/retry/backfill |
| `app/Services/SatuSehatDashboardService.php` | Statistik layar Bridging |
| `app/Services/Laporan/SatuSehatPantauService.php` | Pemantauan untuk pelaporan |
| `app/Models/SatusehatSyncLog.php` · `SatusehatResourceLog.php` | Log Satu Sehat |

---

## Ringkasan Perbandingan

| Aspek | BPJS | Satu Sehat |
|---|---|---|
| Autentikasi | HMAC-SHA256 per-request | OAuth2 bearer token (cached) |
| Enkripsi response | AES-256-CBC + LZString | Tidak ada (JSON FHIR) |
| Identitas orang | No. Kartu BPJS | **IHS** (di-resolve dari NIK) |
| Identitas obat | — | **KFA** (`92`/`93xxxxxx`) |
| Waktu kirim | Saat transaksi | Batch 23:59 + retry 01:00 |
| Saat gagal | Ditolak langsung, terlihat operator | Terkumpul di log; perlu dipantau |
| Tabel log | `bpjs_vclaim_logs` | `satusehat_sync_logs`, `satusehat_resource_logs` |
| Penentu sukses | `is_success` (code `200`) | HTTP 200 + `OperationOutcome` |
| Kegagalan senyap | Code 201 disangka gagal | **Obat tanpa KFA dilewati — sukses semu** |

---

*Disusun dari kode yang berjalan dan catatan uji empiris. Bila dokumentasi resmi
(trustmark BPJS / docs Kemenkes) dan lapangan berbeda, **lapangan yang menang** —
dan perbedaannya dicatat di sini.*
