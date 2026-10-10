---
name: koding-icd
description: Koding diagnosis (ICD-10) dan tindakan (ICD-9-CM) di SIMRS, termasuk validasi untuk klaim INA-CBG. Gunakan saat membuat fitur input diagnosis/tindakan, pencarian kode, validasi koding, atau laporan morbiditas.
---

# Koding ICD-10 / ICD-9-CM

> STATUS: KERANGKA. Isi bersama koder/unit rekam medis RS.

## Aturan keras
- Jangan pernah mengarang atau "memperkirakan" kode ICD. Kode hanya berasal dari tabel master.
- Claude boleh membantu UI pencarian dan validasi format, BUKAN memilih kode untuk pasien.

## Sumber data master
- TODO: versi ICD-10 dan ICD-9-CM yang dipakai, sumber resminya, dan tabel master di DB.

## Aturan validasi
- TODO: aturan diagnosis utama vs sekunder, kombinasi yang ditolak, dan aturan dari tim koder.

## Laporan
- TODO: laporan yang wajib dihasilkan dan format yang diminta.
