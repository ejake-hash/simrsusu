---
name: rs-pendidikan-akses
description: Model peran, hak akses, dan supervisi klinis untuk RS Pendidikan (DPJP, PPDS/residen, dokter muda/koas, perawat, mahasiswa) di SIMRS Laravel. Gunakan saat membuat fitur otorisasi, Policy/Gate, co-sign/validasi DPJP, atau data untuk riset/pendidikan.
---

# RS Pendidikan — Peran, Akses & Supervisi

> STATUS: KERANGKA. Isi bersama Komite Medik, bagian Diklat, dan unit Rekam Medis.

## Matriks peran
- TODO: tabel peran × modul × aksi (lihat / tulis / validasi / cetak).

## Supervisi & co-sign
- TODO: catatan mana yang ditulis peserta didik dan wajib divalidasi DPJP; batas waktu validasi.
- Sistem selalu menyimpan "ditulis oleh" dan "divalidasi oleh" sebagai dua field terpisah.

## Implementasi di Laravel
- Otorisasi lewat Policy/Gate; jangan cek peran langsung di Blade/controller.
- TODO: keputusan apakah Row Level Security PostgreSQL juga dipakai (lihat CLAUDE.md).

## Data untuk riset & pendidikan
- TODO: aturan de-identifikasi dan persetujuan sebelum data keluar dari sistem.
