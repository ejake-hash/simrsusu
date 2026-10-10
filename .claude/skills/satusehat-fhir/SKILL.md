---
name: satusehat-fhir
description: Integrasi SIMRS dengan platform SATUSEHAT Kemenkes (FHIR R4) di Laravel. Gunakan saat membuat, memetakan, atau mengirim resource FHIR (Patient, Practitioner, Encounter, Condition, Observation, MedicationRequest, dll.), mengelola token OAuth SATUSEHAT, atau men-debug respons/error SATUSEHAT.
---

# SATUSEHAT (FHIR R4) — Laravel

> STATUS: KERANGKA. Isi setiap bagian TODO dari dokumentasi resmi SATUSEHAT versi
> yang dipakai RS. Jangan biarkan Claude menebak endpoint, profil, atau kode.

## Aturan keras
- Jangan pernah mengarang endpoint, system URI, atau kode terminologi. Jika tidak ada di skill ini, tanyakan.
- Kredensial (client id/secret, organization id) hanya dari `.env` / config, tidak pernah di kode atau log.
- Semua pengiriman lewat queue job dengan retry + idempotency; jangan kirim sinkron di request user.
- Simpan request/response (tanpa token) ke tabel log integrasi untuk audit.

## Environment
- TODO: base URL staging vs production (dari dokumentasi resmi).
- TODO: alur autentikasi & masa berlaku token; cara cache token di Laravel.

## Pemetaan resource
- TODO: tabel pemetaan tabel/kolom SIMRS → resource & field FHIR yang dipakai RS.
- TODO: identifier (NIK pasien, IHS number, kode faskes) dan system URI resminya.
- TODO: urutan kirim (mis. Encounter sebelum Condition) dan cara menyimpan id balikan.

## Penanganan error
- TODO: daftar error umum dari SATUSEHAT dan tindakan yang benar.

## Struktur kode yang disepakati
- TODO: lokasi service class, job, DTO, dan test (mis. `app/Integrations/Satusehat/...`).
