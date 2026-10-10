---
name: bpjs-bridging
description: Bridging SIMRS dengan layanan BPJS Kesehatan (VClaim/SEP, Antrean Online/Mobile JKN, e-Klaim/INA-CBG) di Laravel. Gunakan saat membuat atau men-debug integrasi BPJS, pembuatan SEP, rujukan, antrean, atau grouping klaim.
---

# Bridging BPJS Kesehatan — Laravel

> STATUS: KERANGKA. Isi dari katalog/spesifikasi API BPJS versi yang berlaku untuk RS
> Anda. Versi API BPJS berubah dari waktu ke waktu — catat versinya di sini.

## Aturan keras
- Jangan mengarang endpoint, header, atau format signature. Jika tidak ada di sini, tanyakan.
- cons-id, secret key, user key hanya dari `.env`; jangan pernah di-log.
- Simpan request/response (tanpa kredensial) untuk keperluan audit dan sengketa klaim.

## Layanan yang dipakai RS
- TODO: VClaim — versi, base URL, operasi yang dipakai (SEP, rujukan, peserta, dll.).
- TODO: Antrean Online — versi, alur update status antrean.
- TODO: e-Klaim / INA-CBG — cara integrasi yang dipakai RS.

## Signature, enkripsi & dekripsi
- TODO: algoritma signature, timestamp, dan cara dekripsi respons sesuai spesifikasi resmi.

## Struktur kode yang disepakati
- TODO: lokasi service class, job, dan test (mis. `app/Integrations/Bpjs/...`).
