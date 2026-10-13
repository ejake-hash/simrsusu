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

## Penguncian nama pengguna yang tidak ada

Supaya status `akun_terkunci` tidak membocorkan apakah akun ada (PRD 14.2), nama pengguna yang
tidak ada juga "terkunci" setelah 5 kali gagal. Hitungannya tidak bisa disimpan di
`akses.pengguna` karena barisnya tidak ada, jadi:

| Butir | Akun nyata | Nama yang tidak ada |
|---|---|---|
| Tempat simpan | `akses.pengguna.percobaan_gagal`, `terkunci_sampai` (PostgreSQL) | Cache Laravel lewat `RateLimiter`, store sesuai `CACHE_STORE` (Redis di server; `array` saat tes) |
| Kunci | baris akun | `autentikasi:gagal-tanpa-akun:` + SHA-256 nama pengguna (huruf kecil). Nama aslinya tidak disimpan |
| Masa berlaku | sampai `terkunci_sampai` | TTL = durasi kunci (15 menit) sejak gagal pertama dalam jendela itu |
| Bertahan setelah restart aplikasi | Ya | Ya selama Redis tidak dikosongkan |
| Audit | `autentikasi.gagal` dan `autentikasi.terkunci` dengan `pengguna_id` | `autentikasi.gagal` tanpa `pengguna_id`; nama pengguna tidak dicatat |

Konsekuensi: bila cache Redis dikosongkan (`cache:clear`, restart Redis tanpa persistensi), nama
fiktif yang sedang "terkunci" bisa dicoba lagi lebih awal, sedangkan akun nyata tetap terkunci. Selisih
ini hanya terlihat oleh penyerang yang bisa memicu pengosongan cache, jadi diterima. Redis di
`docker-compose.yml` sudah memakai `appendonly yes`. Hitungan gagal juga tetap dibatasi oleh batas
laju per nama pengguna dan per IP (`config/autentikasi.php`).

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
- Pencabutan saat **peran** berubah (PRD 14.2): lihat bagian "Pencabutan sesi oleh admin" di bawah (tiket 1.2c).
- Saat registri perangkat dibangun, cukup ganti binding `PendaftaranPerangkat`. Sumber kebenaran
  harus di server (misalnya sertifikat klien MDM atau token perangkat yang diterbitkan admin).
- Belum ada "ganti pengguna cepat" di komputer ruangan (PRD 14.2); menyusul bersama registri perangkat.

## Pencabutan sesi oleh admin (tiket 1.2c, 11 Okt 2026)

Tetap tanpa indeks sesi per akun. Kolom `akses.pengguna.sesi_dicabut_pada` (mikrodetik) menandai waktu
cabut, dan `SesiPengguna::mulai()` menyimpan waktu masuk sesi (`autentikasi.masuk_pada`, mikrodetik).
Setiap permintaan, sesi yang dimulai sebelum `sesi_dicabut_pada`, atau sesi lama tanpa waktu masuk,
diakhiri dengan `401 sesi_dicabut`. Pemeriksaannya tanpa query tambahan, karena baris akun memang
sudah dimuat guard.

| Kejadian | Sesi akun diputus? | Alasan |
|---|---|---|
| Reset MFA (`POST /akun/{id}/mfa/reset`) | Ya | Autentikator lama dianggap bocor atau hilang; akun mendaftar ulang dari jaringan RS |
| Wajib MFA dinyalakan untuk akun yang belum punya MFA | Ya | Sesi berjalan belum pernah lolos MFA |
| Wajib MFA dinyalakan untuk akun yang sudah punya MFA | Tidak | Kewajiban berlaku saat masuk berikutnya |
| Peran, isi peran, penugasan, atau pengecualian per akun berubah | Tidak | Izin dihitung ulang tiap permintaan (`AccessEvaluator`); cukup cache hak akses dihapus, jadi perubahan berlaku pada permintaan berikutnya |
| Akun dinonaktifkan | Ya (sudah sejak awal) | Status dibaca ulang tiap permintaan |

Celah yang diterima: peran yang `wajib_mfa`-nya dinyalakan tidak memutus sesi pemegangnya yang belum
punya MFA; kewajiban berlaku saat masuk berikutnya. MFA ulang untuk aksi sensitif menyusul di tiket 1.5
(keputusan bawaan K11).


## Perangkat tepercaya sebagai registri perangkat (tiket 1.13, 13 Okt 2026)

Butir 4 di atas kini diisi `PerangkatTepercayaSesi`, menggantikan `PerangkatBelumTerdaftar`. "Perangkat terdaftar" =
perangkat tepercaya yang dipercaya pemilik akun sesudah MFA (keputusan pemilik di tiket 1.13), bukan registri MDM:

- Sesudah MFA berhasil dari jaringan RS, pengguna boleh mencentang "Percayai perangkat ini". Server menyimpan hash
  SHA-256 token acak di `akses.perangkat_tepercaya`; tokennya di cookie HttpOnly, Secure, SameSite=Strict dengan path
  `/api/v1/autentikasi`, terpisah dari sesi.
- Langkah sandi dari jaringan RS dengan token sah untuk akun itu melewati MFA. Sesi itu, dan sesi yang baru mempercayai
  perangkat, memakai batas diam 60 menit. MFA ulang (K11) tetap diminta, karena sesi yang masuk lewat perangkat tidak
  dicatat sebagai sudah MFA.
- Masa percaya efektif = nilai terkecil dari kebijakan instalasi (`akses.kebijakan_keamanan`), `akses.peran.masa_percaya_hari`,
  dan batas konfigurasi (umum 30, superadmin 7, peran/izin sensitif 14 hari). Masa ini dihitung ulang saat token dipakai.
- Ganti sandi, reset sandi, reset MFA, dan akun nonaktif mencabut semua perangkat akun itu.

Konsekuensi yang diterima (sesuai tiket): perangkat bersama di ruangan juga bisa dipercaya bila pengguna mencentangnya.
Selama token akun itu ada di peramban tersebut, orang yang tahu sandinya bisa masuk dari jaringan RS tanpa MFA, dan batas
diamnya 60 menit. Kebijakan instalasi atau peran bisa diturunkan ke 0 untuk unit yang memakai komputer bersama.
Ganti pengguna cepat di komputer ruangan (PRD 14.2) tetap belum ada.
