# 0001. Cek sandi bocor secara offline

- Status: Diterima
- Tanggal: 2026-10-10
- Terkait: PRD 14.2 (Autentikasi), tiket feat/autentikasi

## Konteks

PRD 14.2 mewajibkan sandi "dicek terhadap daftar sandi bocor". Cara paling umum di Laravel adalah
`Password::uncompromised()`, yang memanggil API Have I Been Pwned (HIBP) dengan k-anonymity
(hanya 5 karakter awal SHA-1 yang dikirim).

Server SIMRS dipasang on-premise di jaringan RS (PRD 10.1). Zona aplikasi belum tentu boleh atau
bisa keluar ke internet, dan kalau bisa pun koneksinya tidak dijamin. Bila cek sandi bergantung pada
HIBP, ada dua pilihan buruk saat internet putus: menolak semua penetapan sandi, atau melewati cek
diam-diam.

## Keputusan

1. Cek utama memakai **daftar lokal** di `backend/app/Modules/Akses/Infrastructure/data/sandi-bocor.txt.gz`:
   gabungan tiga daftar SecLists (MIT), hanya sandi >= 12 karakter (yang lebih pendek sudah ditolak
   aturan panjang minimal), huruf kecil, 73.078 baris, 460 KB terkompresi. Asal-usul dan perintah
   pembaruan ada di `SUMBER.md` di folder yang sama.
2. Pencocokan tidak membedakan huruf besar/kecil (`Indonesia123` ditolak sama seperti `indonesia123`).
3. Bila berkas tidak bisa dibaca, penetapan sandi **gagal** (tertutup), bukan dilewati.
4. HIBP tetap tersedia sebagai **lapis tambahan opsional**: `AUTENTIKASI_SANDI_CEK_HIBP=true`.
   Mati bawaan.
5. Aturan dipasang lewat `Password::defaults()`, jadi setiap tempat yang menetapkan sandi cukup
   memakai `Password::defaults()`.

## Sumber data

| Butir | Nilai |
|---|---|
| Repositori | [danielmiessler/SecLists](https://github.com/danielmiessler/SecLists) |
| Commit | `cae9eab995852c183fbe5e03fc92471fcac82f0e` |
| Berkas | `Passwords/Common-Credentials/Pwdb_top-1000000.txt`, `xato-net-10-million-passwords-1000000.txt`, `100k-most-used-passwords-NCSC.txt` |
| Lisensi | MIT, Copyright (c) 2018 Daniel Miessler. Teks lengkap disertakan di `backend/app/Modules/Akses/Infrastructure/data/LICENSE-SecLists.txt` (syarat MIT untuk salinan) |
| Hasil olahan | `sandi-bocor.txt.gz`, 73.078 baris, SHA-256 `83da79944411de51ade561222c10f39f9ecb0c211fc79fe345b74baabbe439c3` |
| Cara membuat ulang | `backend/app/Modules/Akses/Infrastructure/data/SUMBER.md` |

Saat memperbarui daftar, ganti commit, SHA-256, dan jumlah baris di tabel ini dan di `SUMBER.md`
dalam commit yang sama.

## Alternatif yang dipertimbangkan

| Alternatif | Alasan tidak dipilih |
|---|---|
| HIBP saja (`uncompromised()`) | Bergantung internet; perilaku saat putus tidak bisa diterima |
| Salinan penuh HIBP (± 40 GB hash) | Terlalu besar untuk repo dan server aplikasi; butuh sinkronisasi terpisah |
| Bloom filter dari HIBP penuh | Cakupan terbaik dengan ukuran kecil, tetapi butuh alat pembangun dan proses pembaruan sendiri. Bisa jadi ADR lanjutan bila daftar lokal dinilai kurang |
| Daftar lokal tanpa saring panjang | 2 juta baris, sebagian besar < 12 karakter yang sudah pasti ditolak |

## Konsekuensi

- Tidak ada lalu lintas keluar saat menetapkan sandi; cek selalu jalan.
- Cakupan lebih sempit dari HIBP: sandi bocor yang jarang (di luar ± 2 juta teratas) lolos.
  Diimbangi panjang minimal 12, penguncian akun, dan MFA untuk peran sensitif (tiket 1.1b).
- Daftar harus diperbarui berkala (misalnya tiap rilis besar) dengan perintah di `SUMBER.md`.
- Belum ada cek kontekstual (sandi memuat nama pengguna, nama RS, atau nama produk). Layak
  ditambahkan saat fitur ganti sandi dibuat.
