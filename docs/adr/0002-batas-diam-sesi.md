# 0002. Batas diam sesi per sesi, bukan umur cookie

- Status: Diterima
- Tanggal: 2026-10-10
- Terkait: PRD 14.2 (Sesi), tiket feat/autentikasi

## Konteks

PRD 14.2: sesi berakhir setelah tidak aktif **15 menit** di komputer bersama dan **60 menit** di
perangkat pribadi terdaftar, dan sesi **dicabut seketika** saat akun dinonaktifkan.

`SESSION_LIFETIME` Laravel berlaku sama untuk semua sesi, jadi tidak bisa membedakan 15 dan 60
menit. Registri perangkat (MDM / data perangkat terdaftar) juga belum ada.

## Keputusan

1. Middleware `SesiPengguna` (modul Akses) dipasang sesudah `auth:web` di semua rute bersesi. Saat
   login, batas diam disimpan di sesi (15 atau 60 menit). Di setiap permintaan:
   - akun dibaca ulang dari DB oleh guard; bila `status` bukan `aktif`, sesi diakhiri dengan kode
     `sesi_dicabut`;
   - bila jeda sejak permintaan terakhir melebihi batas, sesi diakhiri dengan kode `sesi_kedaluwarsa`;
   - selain itu waktu aktivitas terakhir diperbarui.
2. Angka ada di `config/autentikasi.php` (`AUTENTIKASI_BATAS_DIAM_MENIT`,
   `AUTENTIKASI_BATAS_DIAM_PERANGKAT_TERDAFTAR_MENIT`).
3. `SESSION_LIFETIME` (umur data sesi di Redis) dibuat **lebih lama** dari batas terpanjang (120
   menit), supaya sesi diam selalu diakhiri middleware dengan kode yang jelas, bukan hilang diam-diam
   menjadi `belum_masuk`.
4. Penentuan "perangkat terdaftar" lewat kontrak `PendaftaranPerangkat`. Implementasi bawaan
   `PerangkatBelumTerdaftar` **selalu menjawab tidak**, jadi semua sesi memakai 15 menit sampai
   registri perangkat dibangun. Gagal ke arah yang lebih aman.
5. Rute bersesi memakai guard `web` (cookie), bukan `auth:sanctum`, supaya token pribadi Sanctum
   tidak bisa dipakai untuk endpoint SPA.

## Alternatif yang dipertimbangkan

| Alternatif | Alasan tidak dipilih |
|---|---|
| `SESSION_LIFETIME=15` untuk semua | Tidak memenuhi 60 menit di perangkat terdaftar |
| Batas diam hanya di frontend (timer JS) | Bisa dilewati; server tetap harus menolak |
| Mencabut sesi dengan menghapus kunci Redis milik akun | Butuh indeks sesi per akun; pemeriksaan status per permintaan cukup dan selalu konsisten |
| Menganggap perangkat terdaftar dari header atau cookie buatan klien | Bisa dipalsukan; harus berasal dari registri yang dikelola RS |

## Konsekuensi

- Satu query tambahan per permintaan bersesi (memuat akun), yang memang sudah dilakukan guard.
- Pencabutan berlaku pada permintaan berikutnya. Sesi lain milik akun yang sama tetap ada di Redis
  sampai kedaluwarsa, tetapi tidak bisa dipakai.
- Pencabutan saat **peran** berubah (PRD 14.2) belum ditangani; menyusul di tiket menu/peran (1.2).
- Saat registri perangkat dibangun, cukup ganti binding `PendaftaranPerangkat`. Sumber kebenaran
  harus di server (misalnya sertifikat klien MDM atau token perangkat yang diterbitkan admin).
- Belum ada "ganti pengguna cepat" di komputer ruangan (PRD 14.2); menyusul bersama registri perangkat.
