---
name: regulasi-rme-pdp
description: Aturan kepatuhan rekam medis elektronik dan pelindungan data pribadi untuk SIMRS di Indonesia (Permenkes 24/2022 tentang Rekam Medis, UU 27/2022 tentang PDP, dan kebijakan internal RS). Gunakan saat kode menyentuh data pasien, hak akses, retensi, pelepasan informasi, persetujuan, atau audit trail.
---

# Regulasi RME & PDP

> STATUS: KERANGKA. Isi bersama unit Rekam Medis, tim hukum, dan DPO RS.
> Skill ini panduan untuk Claude, bukan pengganti review hukum.

## Ringkasan kewajiban (diverifikasi oleh tim hukum RS)
- TODO: kewajiban utama Permenkes 24/2022 yang berdampak ke desain sistem.
- TODO: kewajiban UU 27/2022 (dasar pemrosesan, hak subjek data, notifikasi insiden).

## Klasifikasi data RS
- TODO: daftar kategori data (identitas, klinis, keuangan, data SDM) dan tingkat sensitivitasnya.

## Retensi & pemusnahan
- TODO: masa simpan per jenis rekam medis sesuai aturan yang berlaku dan kebijakan RS.

## Pelepasan informasi
- TODO: siapa boleh meminta, dokumen apa, persetujuan siapa, dan apa yang dicatat.

## Aturan implementasi
- Setiap akses baca/tulis data klinis tercatat di audit log append-only.
- Data pasien tidak boleh muncul di log aplikasi, URL, query string, atau pesan error.
