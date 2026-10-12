# Daftar periksa OWASP ASVS 4.0.3 Level 2 — Fase 1

Tiket 1.12 butir be 3, 12 Okt 2026. Kode yang dinilai: `origin/main` (63e5b2b, sesudah #82) dan cabang
`feat/akses-lapis45` (tiket 1.5, PR #83, belum merge). Butir yang hanya terpenuhi oleh kode #83 ditandai
**bergantung #83**.

Cara menilai: dari kode dan tes, bukan dari klaim dokumen. Bukti ditulis `berkas:baris` relatif terhadap `backend/`
(atau `frontend/src/`, `docs/`). Runbook dirujuk dari cabang `docs/runbook-1.12` (belum merge) dan ditandai
"(runbook)".

Hasil: **Lulus** = terpenuhi dan ada bukti di kode/tes; **Sebagian** = sebagian terpenuhi atau hanya bukti kode
tanpa tes untuk bagian penting; **Tidak** = tidak terpenuhi; **n.a.** = fitur itu tidak ada di fase 1.
Butir L3 tidak dinilai. Temuan yang sudah tercatat di `docs/backlog-fase1.md` bagian "Utang" hanya dirujuk.

Batas penilaian: isi butir mengikuti ASVS 4.0.3 sebatas yang penilai ingat. Butir yang perlu dicocokkan dengan teks
resmi diberi tanda *perlu cek teks ASVS* (lihat bagian akhir).

---

## V2 Authentication

### V2.1 Keamanan sandi

| No. | Butir (ringkas) | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.1.1 | Sandi pilihan pengguna minimal 12 karakter | 1 | Lulus | `app/Modules/Akses/ModuleServiceProvider.php:72` (`max(12, …)`), `app/Modules/Akses/Application/GantiSandi.php:41-42`; tes `tests/Feature/Akses/SandiTest.php:18`, `tests/Feature/Sdm/PegawaiTest.php:95` | Angka konfigurasi tidak bisa menurunkan batas 12 |
| 2.1.2 | Sandi ≥ 64 karakter diizinkan, > 128 ditolak | 1 | Sebagian | `GantiSandi.php:14,42` (`max:200`), `Http/Requests/MasukRequest.php:19` (`max:1024`), `Console/BuatSuperadminPertama.php:50` (`max:1024`) | 64 diizinkan; batas atas 200/1024, bukan 128. Frontend tanpa `maxlength` (`design-system/form/IsianSandi.vue`) |
| 2.1.3 | Tidak ada pemotongan sandi | 1 | Lulus | `config/hashing.php:19` (argon2id, tanpa batas 72 bita seperti bcrypt) | Huruf kecil hanya untuk cek daftar bocor (`Infrastructure/DaftarSandiBocor.php:19`) |
| 2.1.4 | Semua karakter Unicode cetak boleh | 1 | Lulus | aturan hanya `string`, `min`, `max` (`GantiSandi.php:42`) | NUL ditolak umum (`app/Core/Http/TolakKarakterNul.php`), bukan karakter cetak |
| 2.1.5 | Pengguna bisa mengganti sandi | 1 | Lulus | `routes.php:62` `POST /saya/sandi`; tes `PegawaiTest.php:91-103` | |
| 2.1.6 | Ganti sandi meminta sandi lama dan baru | 1 | Lulus | `GantiSandi.php:36-39`; tes `PegawaiTest.php:92` (`sandi_lama_salah`) | Sandi lama salah dibatasi 5/15 menit per akun (`GantiSandi.php:31-38`) |
| 2.1.7 | Sandi dicek ke daftar bocor (saat daftar, masuk, ganti) | 1 | Sebagian | `Application/SandiTidakBocor.php`, `DaftarSandiBocor.php`, ADR 0001; tes `SandiTest.php:24,32` | Dicek di semua tempat sandi ditetapkan (ganti sandi, superadmin pertama). Tidak dicek saat masuk (sandi lama yang kini masuk daftar tidak memaksa ganti). *perlu cek teks ASVS* apakah "login" termasuk |
| 2.1.8 | Pengukur kekuatan sandi | 1 | Tidak | tidak ada di `modules/akses/pages/GantiSandiPage.vue` | Aturan hanya dari pesan server |
| 2.1.9 | Tanpa aturan komposisi | 1 | Lulus | `GantiSandi.php:42` | |
| 2.1.10 | Tanpa rotasi berkala / riwayat sandi | 1 | Lulus | tidak ada kedaluwarsa sandi; PRD 14.2 | |
| 2.1.11 | Tempel dan pengelola sandi diizinkan | 1 | Lulus | tidak ada `paste`/`onpaste` di `frontend/src`; `autocomplete` `current-password`/`new-password` (`IsianSandi.vue:9`, `GantiSandiPage.vue:123,132,135`) | |
| 2.1.12 | Bisa melihat sandi sementara | 1 | Lulus | tombol "Tampilkan kata sandi" `IsianSandi.vue:45,55-62` | |

### V2.2 Keamanan autentikator umum

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.2.1 | Anti-otomasi: penguncian, rate limit; ≤ 100 gagal/jam per akun | 1 | Lulus | `Application/MasukPengguna.php:42-77,112-121`, `Application/PenghitungGagal.php:19-39`, `ModuleServiceProvider.php:78-92`; tes `AutentikasiTest.php:97,123,241,254`, `MfaTest.php:114` | 5 gagal → kunci 15 menit (≤ 20/jam). Nama yang tidak ada ikut terkunci (ADR 0002). Kode MFA salah masuk penghitung yang sama |
| 2.2.2 | SMS/email bukan pengganti autentikator kuat | 1 | Lulus | tidak ada OTP SMS/email; `PesanSingkatLog` hanya notifikasi | |
| 2.2.3 | Notifikasi aman saat detail autentikasi berubah / masuk dari lokasi berisiko | 1 | Sebagian | akun terkunci → notifikasi (`app/Modules/Notifikasi/ModuleServiceProvider.php:46`); masuk sebelumnya + jaringan RS tampil di Beranda (`MasukPengguna.php:83-95`, tes `SayaTest.php:17,88`) | Tidak ada notifikasi saat sandi diganti, MFA didaftarkan/direset, atau masuk dari luar jaringan RS. Lihat S-3 |

### V2.3 Siklus hidup autentikator

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.3.1 | Sandi awal acak, kedaluwarsa singkat, tidak jadi sandi jangka panjang | 1 | Sebagian | `Application/AkunPegawai.php:84-86` (`Str::password(20)`), `wajib_ganti_sandi` (`Http/Middleware/SesiPengguna.php:52-55,75-77`); tes `PegawaiTest.php:64` | Acak dan wajib diganti, tetapi **tidak kedaluwarsa** — sudah tercatat: backlog Utang "[Perlu pemilik] Usulan (1.8d)" |
| 2.3.2 | Mendukung autentikator milik pengguna (FIDO/WebAuthn) | 2 | Tidak | hanya TOTP + kode cadangan (`MfaRequest.php:19`) | PRD 14.2/10.3.2 merencanakan WebAuthn; belum ada tiket. Lihat R-4 |
| 2.3.3 | Pengingat pembaruan autentikator berbatas waktu | 2 | n.a. | tidak ada autentikator berbatas waktu | |

### V2.4 Penyimpanan kredensial

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.4.1 | Sandi di-hash dengan fungsi hash sandi yang disetujui | 2 | Lulus | `config/hashing.php:19,49-54` (argon2id, 64 MiB, t=4, p=1), `rehash_on_login` + `MasukPengguna.php:72-74`; tes `SandiTest.php:13` | Kode cadangan MFA juga argon2id (`VerifikasiMfa.php:83`) |
| 2.4.2 | Salt ≥ 32 bit, unik per kredensial | 2 | Lulus | `password_hash` argon2id membuat salt acak 16 bita per hash | Bukti dari perilaku PHP, bukan tes proyek |
| 2.4.3 | PBKDF2 ≥ 100.000 iterasi | 2 | n.a. | tidak dipakai | |
| 2.4.4 | bcrypt cost ≥ 10 | 2 | n.a. | tidak dipakai (`BCRYPT_ROUNDS` di `.env.example` tidak berpengaruh) | |
| 2.4.5 | Iterasi tambahan dengan salt rahasia (pepper) terpisah | 2 | Tidak | sandi tanpa pepper; PIN TTE sudah memakai pepper `TTD_KUNCI` (`app/Modules/Ttd/Application/KelolaTtd.php:85`) | Lihat R-5 |

### V2.5 Pemulihan kredensial

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.5.1 | Rahasia awal/pemulihan tidak dikirim teks jelas | 1 | Lulus | sandi awal hanya di respons 201 `no-store` ke admin (`Http/Controllers/AkunPegawaiController.php:29-30`), tidak diemail, tidak di audit; tes `PegawaiTest.php:72-77` | |
| 2.5.2 | Tanpa petunjuk sandi / pertanyaan rahasia | 1 | Lulus | tidak ada | |
| 2.5.3 | Pemulihan tidak membuka sandi sekarang | 1 | Lulus | hanya hash yang disimpan | |
| 2.5.4 | Tidak ada akun bersama/bawaan | 1 | Lulus | superadmin pertama lewat konsol dengan sandi pilihan (`Console/BuatSuperadminPertama.php:41-50`); seeder sintetis ditolak di production (`Database/Seeders/AkunSintetisSeeder.php:105`) | |
| 2.5.5 | Pengguna diberi tahu bila faktor autentikasi diganti | 1 | Tidak | `akses.sandi_diganti` (`GantiSandi.php:62`), `akses.mfa_direset` (`Application/KelolaAksesAkun.php:262`), `autentikasi.mfa_didaftarkan` hanya audit, tanpa notifikasi | Lihat S-3 |
| 2.5.6 | Jalur lupa sandi memakai mekanisme aman | 1 | Tidak | belum ada lupa sandi maupun reset sandi oleh admin | Sudah tercatat: backlog "[Perlu pemilik] Usulan (1.8d)" reset sandi oleh admin |
| 2.5.7 | MFA hilang → pembuktian identitas setara pendaftaran | 2 | Lulus | reset MFA beralasan oleh pemegang izin sensitif (`routes.php:57`, `KelolaAksesAkun.php:~262`), sesi diputus, daftar ulang hanya dari jaringan RS (`AutentikasiController.php:70-74`); tes `PengaturanAkunTest.php:285` | |

### V2.6 Lookup secret (kode cadangan)

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.6.1 | Sekali pakai | 2 | Lulus | `Application/VerifikasiMfa.php:142-160` (update atomik `whereNull('dipakai_pada')`); tes `MfaTest.php:187` | |
| 2.6.2 | ≥ 112 bit, atau di-hash dengan salt | 2 | Lulus | 10 karakter dari 31 simbol ≈ 49 bit (`VerifikasiMfa.php:22-23,198-206`), di-hash argon2id (`:83`) | |
| 2.6.3 | Tahan serangan offline / tidak mudah ditebak | 2 | Lulus | `random_int` (`:202`), hash argon2id | |

### V2.7 Out-of-band (SMS, email, push)

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.7.1–2.7.4 | OOB tidak dipakai bawaan, kedaluwarsa 10 menit, sekali pakai, kanal terpisah | 1 | n.a. | tidak ada autentikator OOB | |
| 2.7.5–2.7.6 | Simpan hash kode OOB; kode ≥ 20 bit | 2 | n.a. | idem | |

### V2.8 One-time verifier (TOTP)

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.8.1 | OTP berbatas waktu | 1 | Lulus | `Domain/Totp.php:13,46-61` (30 detik, ±1 periode); tes `MfaTest.php:177`, `tests/Unit/TotpTest.php` | Butuh jam server sinkron: chrony (runbook `instalasi.md:29`) |
| 2.8.2 | Kunci simetris OTP sangat terlindungi (HSM/key store OS) | 2 | Sebagian | rahasia TOTP terenkripsi `APP_KEY` (`Models/Pengguna.php:62`), rahasia sementara terenkripsi di sesi (`Application/MfaChallenge.php:61-70`) | Kunci perangkat lunak di `.env`/vault, bukan HSM. PRD 14.2 "KMS/HSM" untuk kolom sensitif |
| 2.8.3 | Algoritme kriptografi yang disetujui | 2 | Lulus | HMAC-SHA1 RFC 6238, rahasia 160 bit `random_bytes` (`Totp.php:20-40`), `hash_equals` (`:55`) | |
| 2.8.4 | TOTP hanya sekali dalam masa berlaku | 2 | Lulus | `VerifikasiMfa.php:116-133` (`mfa_langkah_terakhir`, update atomik); tes `MfaTest.php:161` | Juga untuk MFA ulang (bergantung #83, memakai `verifikasi()` yang sama) |
| 2.8.5 | TOTP dipakai ulang → dicatat, ditolak, pemilik diberi tahu | 2 | Sebagian | ditolak dan dicatat sebagai `autentikasi.mfa_gagal` (`VerifikasiMfa.php:186-196`) | Tidak dibedakan dari salah ketik, tanpa notifikasi. Lihat S-3 |
| 2.8.6 | Generator OTP bisa dicabut, berlaku seketika di semua sesi | 2 | Lulus | reset MFA mencabut sesi (`Models/Pengguna.php:88-93`, `SesiPengguna.php:96-97`); tes `PengaturanAkunTest.php:285` | Jendela milidetik sudah tercatat di backlog (kecil dari tinjauan #32) |

### V2.9 Cryptographic verifier

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.9.1–2.9.3 | Kunci verifikasi aman, nonce ≥ 64 bit, algoritme disetujui | 2 | n.a. | WebAuthn/kunci kriptografi belum ada | Nilai saat WebAuthn dibuat |

### V2.10 Autentikasi layanan

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 2.10.1 | Rahasia antarlayanan tidak bergantung pada kredensial statis | 2 | Sebagian | DB, Valkey memakai sandi statis dari `.env` (runbook `konfigurasi-env.md:53-73`) | Wajar untuk on-premise; rotasi manual. *perlu cek teks ASVS* (butir ini bertanda "OS assisted") |
| 2.10.2 | Akun layanan bukan kredensial bawaan | 2 | Lulus | runbook meminta sandi sendiri; migrator tidak ditulis di `.env` produksi (`konfigurasi-env.md:57`) | Nilai `simrs_app/simrs_app` di `.env.example` hanya lokal |
| 2.10.3 | Sandi layanan tersimpan terlindung | 2 | Sebagian | kredensial BPJS/SATUSEHAT terenkripsi kunci khusus `KREDENSIAL_KUNCI` (K9); sandi DB/Valkey di `.env` teks jelas | Bergantung izin berkas `.env` di server (runbook) |
| 2.10.4 | Rahasia tidak di kode/repo | 2 | Lulus | `.env.example` kosong; kunci uji di `phpunit.xml`/CI ditandai publik (runbook `konfigurasi-env.md:205`) | |

## V3 Session Management

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 3.1.1 | Token sesi tidak pernah di URL | 1 | Lulus | sesi hanya cookie (`config/session.php`), rute bersesi `auth:web` (ADR 0002 butir 5); tidak ada `localStorage`/`sessionStorage` di frontend | |
| 3.2.1 | Token sesi baru saat autentikasi | 1 | Lulus | `Http/Controllers/PenyelesaiMasuk.php:30-31` (`regenerate`), ganti sandi `GantiSandi.php:70` (`migrate(true)`) | Tes `MfaTest.php:59` membandingkan nilai cookie terenkripsi, yang berubah setiap respons walau ID sama; tes ini lemah. Lihat R-6 |
| 3.2.2 | Token ≥ 64 bit entropi | 1 | Lulus | ID sesi Laravel 40 karakter acak (`Str::random`) | Perilaku framework |
| 3.2.3 | Token hanya di cookie aman / penyimpanan aman | 1 | Lulus | cookie `HttpOnly`, `Secure`, `SameSite=Strict`; tes `AutentikasiTest.php:42-45` | |
| 3.2.4 | Token dibuat dengan kriptografi yang disetujui | 2 | Lulus | `random_bytes` lewat `Str::random` | Perilaku framework |
| 3.3.1 | Keluar/kedaluwarsa membatalkan sesi | 1 | Lulus | `Http/Controllers/AutentikasiController.php:85-95`, `SesiPengguna.php:115-123`; tes `AutentikasiTest.php:160,210` | |
| 3.3.2 | Autentikasi ulang berkala: L2 12 jam atau 30 menit diam | 1/2 | Sebagian | batas diam 15 menit (60 di perangkat terdaftar), polling tidak dihitung aktivitas (`SesiPengguna.php:69-73,92-98`); tes `AutentikasiTest.php:210,222` | **Tidak ada batas umur mutlak**: sesi yang terus dipakai tidak pernah meminta masuk ulang (`SESSION_LIFETIME` bergulir). Lihat S-1. *perlu cek teks ASVS* apakah "atau" cukup salah satu |
| 3.3.3 | Pilihan memutus sesi lain sesudah ganti sandi | 2 | Lulus | otomatis diputus (`GantiSandi.php:61,66-72`); tes `PegawaiTest.php:101-103` | Tanpa pilihan: selalu diputus (lebih ketat) |
| 3.3.4 | Pengguna bisa melihat dan mengakhiri sesi aktifnya | 2 | Tidak | tidak ada daftar sesi (ADR 0002: tanpa indeks sesi per akun) | Beranda hanya menampilkan "masuk di tempat lain". Lihat S-2 |
| 3.4.1 | Cookie `Secure` | 1 | Lulus | `config/session.php:175`, boot ditolak bila tidak (`app/Core/PemeriksaanProduksi.php:22-24`); tes `tests/Unit/PemeriksaanProduksiTest.php:29`, `AutentikasiTest.php:45` | |
| 3.4.2 | Cookie `HttpOnly` | 1 | Lulus | `config/session.php:188`, `app/Core/Http/SesiSpaStateful.php:16`; tes `AutentikasiTest.php:43` | |
| 3.4.3 | Cookie `SameSite` | 1 | Lulus | `SesiSpaStateful.php:17` (`strict`, menimpa `lax` Sanctum); tes `AutentikasiTest.php:44` | |
| 3.4.4 | Prefiks cookie `__Host-` | 1 | Tidak | nama cookie dari `APP_NAME` (`config/session.php:132-135`) → `karsitara-simrs-session` | Syarat `__Host-` (Secure, path `/`, tanpa domain) sudah terpenuhi. Lihat R-1 |
| 3.4.5 | Path cookie setepat mungkin bila berbagi domain | 1 | Lulus | host sendiri, cookie host-only (`SESSION_DOMAIN=null`, runbook `konfigurasi-env.md:92`) | |
| 3.5.1 | Pengguna bisa mencabut token OAuth aplikasi tertaut | 2 | n.a. | tidak ada OAuth | SSO tiket 1.10 terhambat |
| 3.5.2 | Memakai token sesi, bukan rahasia API statis | 2 | Lulus | SPA memakai sesi cookie; token Sanctum hanya mesin-ke-mesin dan belum dipakai rute mana pun (tidak ada `auth:sanctum`) | |
| 3.5.3 | Token stateless ditandatangani/dienkripsi | 2 | n.a. | tidak ada token stateless | |
| 3.7.1 | Sesi penuh/verifikasi ulang sebelum transaksi sensitif | 1 | Sebagian — **bergantung #83** | main: ganti sandi minta sandi lama; TTE minta PIN (tiket 1.6). #83: izin `butuh_mfa` → 403 `mfa_ulang_diperlukan` bila MFA terakhir > 15 menit (`l45: app/Modules/Akses/Application/MfaUlang.php:41-50`, `Http/Controllers/MfaUlangController.php`); tes `l45: tests/Feature/Akses/LapisLimaTest.php:94,123,142,156,172,183` | Dialog MFA ulang di frontend belum ada (1.5-fe); `klien.ts` tidak menangani `mfa_ulang_diperlukan` |

## V4 Access Control

Dasar penilaian: setiap rute di `app/Modules/*/routes.php` dan `app/Core/*/routes.php` ditelusuri sampai pemeriksaan
izinnya (middleware `can:` atau `Gate::allows/authorize` di controller/kelas aplikasi). Tidak ditemukan rute data yang
lolos tanpa pemeriksaan. Rute publik yang disengaja hanya: `autentikasi/masuk`, `autentikasi/mfa*`, `GET profil-rs`,
`GET profil-rs/berkas/{jenis}`, `GET siap`, dan `/` (`routes/web.php`).

Hal penting: `can:<kode>` memanggil Gate **tanpa** cakupan, sehingga `AccessEvaluator::boleh()` lolos bila akun punya
izin itu di cakupan mana pun (`app/Modules/Akses/Application/AccessEvaluator.php:53-58`). Cakupan hanya ditegakkan bila
controller memanggil `Gate::allows($kode, Cakupan::rs())` atau membandingkan unit objek. Modul MasterData, SDM,
Integrasi, Notifikasi, TTE, ProfilRs, dan Instalasi melakukannya; modul Akses untuk pengelolaan akun dan peran tidak
(lihat T-1).

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 4.1.1 | Kontrol akses ditegakkan di server | 1 | Lulus | `ModuleServiceProvider.php:57-67` (`Gate::before` → `AccessEvaluator`), `can:` di rute; frontend hanya menyembunyikan | Respons dibentuk sesuai izin: NIK hanya dengan `sdm.pegawai.identitas.lihat` (`app/Modules/Sdm/Http/Controllers/PegawaiController.php:60-63`), kredensial integrasi hanya 4 karakter terakhir (K9) |
| 4.1.2 | Atribut kebijakan tidak bisa diubah pengguna | 1 | Lulus | izin dihitung dari DB (`AccessEvaluator.php:99-159`), cache di server (`Application/CacheHakAkses.php`) | |
| 4.1.3 | Hak paling kecil: hanya fungsi/data yang diizinkan | 1 | Sebagian | lapis 1–3 berjalan; tes `tests/Feature/Akses/HakAksesMenuTest.php:43,74,91,105,136,149,160,174`, `PengaturanAkunTest.php:27` | (1) **Cakupan izin `pengaturan.akun.*`/`pengaturan.peran.*` tidak ditegakkan** → T-1. (2) PRD 14.2 meminta "tidak ada endpoint tanpa kebijakan akses (diuji otomatis di CI)"; tes itu belum ada (yang ada hanya `RateLimitTest.php:77` untuk rate limit) → S-5 |
| 4.1.5 | Kontrol akses gagal dengan aman, termasuk saat exception | 1 | Lulus | `Gate::before` menolak argumen selain `Cakupan` dan pengguna selain `Pengguna` (`ModuleServiceProvider.php:61-64`); ability tanpa titik jatuh ke Gate bawaan tanpa definisi (ditolak); akun nonaktif/terkunci = hak kosong (`AccessEvaluator.php:39-41`) | #83: lapis 4 tanpa relasi perawatan selalu ditolak (`l45: Infrastructure/TanpaRelasiPerawatan.php`) |
| 4.2.1 | Data dan API sensitif terlindung dari IDOR | 1 | Sebagian | per objek benar di: persetujuan (`Application/AlurPersetujuan.php:148-153,251-262`), notifikasi/tugas (`app/Modules/Notifikasi/Http/Controllers/NotifikasiSayaController.php:41`, `KueriKotakKerja` terlihat/milik), unit (`app/Modules/MasterData/Http/Controllers/UnitOrganisasiController.php:72-74`), dokumen izin praktik (`app/Modules/Sdm/Http/Controllers/KredensialController.php:58,66,108`), TTE (`Penandatangan` `alasanTidakBoleh`), label #83 (`LabelAkunController` disaring `pengguna_id`; tes `l45: LabelSensitivitasTest.php:196`) | `akun/{akun}` dan turunannya tidak membandingkan cakupan penyunting dengan akun sasaran → T-1. Tugas pelaksana `peran` terlihat oleh semua pemegang peran tanpa melihat cakupan unit (R-14) |
| 4.2.2 | Anti-CSRF kuat untuk fungsi terautentikasi; anti-otomasi/CSRF untuk yang tidak | 1 | Lulus | Sanctum stateful + `ValidateCsrfToken`, cookie `SameSite=Strict`; masuk hanya dari domain SPA (`PenyelesaiMasuk.php:43-48`); tes `AutentikasiTest.php:178,188` dengan `tests/Support/CsrfTetapDiperiksa.php` | 419 dipetakan ke 403 `csrf_tidak_valid` (`ErrorHandler.php:41`) |
| 4.3.1 | Antarmuka admin memakai MFA | 1 | Sebagian | superadmin dan pemegang izin sensitif wajib MFA, tidak bisa dibebaskan (`Application/KebijakanMfa.php:50-77`); tes `HakAksesMenuTest.php:265`, `PengaturanAkunTest.php:329` | Izin `pengaturan.{peran,akun}.lihat` (daftar akun, Lihat sebagai) belum sensitif sehingga di jaringan RS bisa tanpa MFA — sudah tercatat di backlog Utang (1.2c/1.2d, "timbang di 1.5") |
| 4.3.2 | Directory browsing mati; metadata berkas (.git, .DS_Store) tidak terbuka | 1 | Lulus | nginx: `root` = `frontend/dist`, `location ~ /\. { deny all; }`, `/horizon` 404 (runbook `nginx-php.md` §2); pemeriksaan `curl …/.env` (§7) | Bergantung runbook (belum merge) |
| 4.3.3 | Otorisasi tambahan (step-up) / pemisahan tugas untuk fungsi bernilai tinggi | 2 | Lulus — sebagian **bergantung #83** | kontrol ganda izin sensitif dan perubahan superadmin (`AlurPersetujuan`, K3/K4/K13; tes `PersetujuanAksesTest.php`), usulan data induk tiga orang (`MesinUsulan.php:95-97,117-121,148-169`), PIN TTE; #83: MFA ulang `butuh_mfa`, SIP/RKK (`l45: LapisLimaTest.php:49,67,78,94`) | #83: `butuh_mfa`/`butuh_kewenangan_klinis` bawaannya `false` untuk semua izin, jadi lapis 5 belum aktif tanpa perubahan data; dan kode penolakan lapis 5 hanya sampai ke klien lewat `can:`/`Gate::authorize` (S-6) |

## V5 Validation, Sanitization and Encoding

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 5.1.1 | Tahan HTTP parameter pollution | 1 | Lulus | masukan dibaca lewat `validate()`/`validated()`; untuk JSON isi body didahulukan dari query (perilaku `Request::input`) | Perilaku framework |
| 5.1.2 | Perlindungan mass assignment | 1 | Lulus | tidak ada `$request->all()`/`fill($request…)`; `fill()` selalu dari daftar kunci (mis. `Application/KelolaPeran.php:95`, `app/Modules/Sdm/Application/KelolaPegawai.php:145`); `Pengguna::$fillable` tanpa kolom keamanan (`Models/Pengguna.php:49`) | `$guarded = []` hanya `app/Modules/Notifikasi/Models/Notifikasi.php:33`, diisi array buatan server |
| 5.1.3 | Semua masukan divalidasi dengan allow-list | 1 | Lulus | `FormRequest`/`validate()` di controller yang ditelusuri, `whereUuid`/`whereIn` di rute (`Akses/routes.php:22`, `MasterData/routes.php:20,68,72`), NUL ditolak (`TolakKarakterNul.php`) | Penilaian dari penelusuran rute, bukan uji fuzz |
| 5.1.4 | Data terstruktur bertipe kuat dan divalidasi skema | 1 | Lulus | UUID, `Rule::in`, `max`, pola kode (`IsianOrganisasi::KODE_LOKASI`), kursor divalidasi (`app/Core/Http/Paginasi.php:100-103`), `batas` maks 200; tes `PaginasiTest.php`; uji kontrak OpenAPI (`tests/Support/KontrakApi.php`) | |
| 5.1.5 | Redirect hanya ke tujuan allow-list | 1 | Lulus | backend tanpa redirect dari masukan; frontend `?kembali=` disaring `tujuanSetelahMasuk` (harus `/`, tolak `//` dan `/\`) `frontend/src/modules/akses/alurMasuk.ts:46-52` | Belum ada uji untuk varian seperti `/%09/…` (R-12) |
| 5.2.1 | HTML dari editor WYSIWYG disanitasi | 1 | n.a. | tidak ada masukan HTML | |
| 5.2.2 | Data tak terstruktur disanitasi (karakter, panjang) | 1 | Lulus | `max:` di semua isian teks, NUL ditolak | |
| 5.2.3 | Masukan disanitasi sebelum ke sistem email | 1 | Lulus | email lewat Laravel Mail (Symfony Mailer), alamat dari `akses.pengguna.email` | Perilaku framework |
| 5.2.4 | Tanpa `eval`/eksekusi kode dinamis | 1 | Lulus | tidak ada `eval`, `new Function` (backend dan frontend) | |
| 5.2.5 | Tahan template injection | 1 | Lulus | tidak ada templat dari masukan pengguna | |
| 5.2.6 | Tahan SSRF | 1 | Lulus | alamat integrasi hanya dari konfigurasi (`app/Modules/Integrasi/Infrastructure/PanggilanHttp.php:20-48`), `kode` sistem `whereIn` (`Integrasi/routes.php:9-10`) | |
| 5.2.7 | SVG dari pengguna disanitasi | 1 | Lulus | allow-list elemen/atribut, tolak `on*`, `href` luar, DOCTYPE; simpan hasil serialisasi ulang (`app/Core/ProfilRs/PemeriksaBerkas.php:84-155`); sajian dengan CSP `default-src 'none'` + `nosniff` (`app/Core/ProfilRs/Http/ProfilRsController.php:75-81`); frontend menampilkan lewat `<img>` | `qr_svg` MFA juga lewat `<img src=data:>` (`LangkahDaftarMfa.vue:39-40,142`) |
| 5.2.8 | Konten skrip/templat dari pengguna (Markdown, CSS, XSL) disanitasi | 1 | n.a. | tidak ada | |
| 5.3.1 | Encoding keluaran sesuai interpreter dan konteks | 1 | Sebagian | JSON dan Vue meng-encode otomatis | (1) motif dipasang ke CSS `url(${…})` tanpa kutip (`frontend/src/layouts/AuthLayout.vue:41`) → R-11; (2) penetralan formula CSV saat ekspor — sudah tercatat di backlog (tinjauan #46) |
| 5.3.2 | Encoding menjaga set karakter dan locale | 1 | Lulus | JSON UTF-8 | |
| 5.3.3 | Escape otomatis cegah XSS | 1 | Lulus | Vue `{{ }}`; satu-satunya `v-html` berisi ikon statis (`frontend/src/design-system/ikon/Ikon.vue:22`, `ikon.ts:6,55-57`); tanpa `innerHTML` | |
| 5.3.4 | Query DB terparameter | 1 | Lulus | SQL mentah hanya dengan nilai dari kode atau binding (`Paginasi.php:49-51`, `MasterData/Application/DataIntegrasi.php:27-31`) | |
| 5.3.5 | Escape kontekstual bila tidak ada parameterisasi | 1 | Lulus | `ILIKE` memakai `App\Core\Database\Like::memuat` (escape `%`, `_`, `\`) | |
| 5.3.6 | Tahan JSON injection / JSON eval | 1 | Lulus | `JsonResponse`, axios `JSON.parse` | |
| 5.3.7 | Tahan LDAP injection | 1 | n.a. | tidak ada LDAP | SSO 1.10 terhambat |
| 5.3.8 | Tahan OS command injection | 1 | Lulus | tidak ada `exec`/`shell_exec`/`proc_open`/`system`/`passthru` | |
| 5.3.9 | Tahan LFI/RFI | 1 | Lulus | berkas identitas: `jenis` `whereIn` dan jalur dari DB (`ProfilRs/routes.php:7`, `ProfilRsController.php:69-73`); dokumen SDM dari baris DB tersaring pegawai | |
| 5.3.10 | Tahan XPath/XML injection | 1 | Lulus | satu-satunya parser XML: SVG (5.2.7) | |
| 5.4.1 | String/memori aman (overflow) | 2 | n.a. | PHP/TypeScript berbahasa terkelola | |
| 5.4.2 | Format string konstan | 2 | Lulus | `sprintf` hanya dengan format konstan dari kode | |
| 5.4.3 | Cegah integer overflow (tanda, rentang) | 2 | Lulus | `integer`/`min`/`max` di validasi (mis. `batas` ≤ 200, menit eskalasi) | |
| 5.5.1 | Objek terserialisasi diberi cek integritas/enkripsi | 1 | Lulus | cookie terenkripsi + MAC (`EncryptCookies`); data sesi dan job di Valkey sisi server | |
| 5.5.2 | Parser XML dikonfigurasi aman (tanpa XXE) | 1 | Lulus | `loadXML($isi, LIBXML_NONET)` tanpa `NOENT`/`DTDLOAD`, DOCTYPE ditolak (`PemeriksaBerkas.php:88-95`) | |
| 5.5.3 | Deserialisasi data tak tepercaya dihindari | 1 | Lulus | tidak ada `unserialize` atas data luar; respons BPJS didekripsi lalu `json_decode` | |
| 5.5.4 | `JSON.parse` di peramban, bukan `eval` | 1 | Lulus | axios bawaan | |

## V7 Error Handling and Logging

| No. | Butir | L | Hasil | Bukti | Catatan |
|---|---|---|---|---|---|
| 7.1.1 | Tidak mencatat kredensial; token sesi hanya bentuk hash | 1 | Lulus | `bootstrap/app.php:36` (`dontFlash` sandi/PIN), audit tanpa sandi/kode/URI otpauth; kunci rate limit memakai SHA-256 ID sesi (`ModuleServiceProvider.php:91`); tes `AutentikasiTest.php:277`, `MfaTest.php:284` | |
| 7.1.2 | Tidak mencatat data sensitif lain (UU PDP) | 1 | Lulus | `app/Core/Log/SqlMasker.php`, `MaskingProcessor.php` dipasang ke semua channel (`app/Providers/AppServiceProvider.php:35-41`), `zend.exception_ignore_args` (`:33`); tes `tests/Feature/MaskingLogTest.php:53,68,79,90,101` | Masking hanya menyasar error SQL dan `bindings`; pesan log buatan tangan bergantung disiplin pengembang |
| 7.1.3 | Mencatat kejadian keamanan: autentikasi berhasil/gagal, kegagalan kontrol akses, kegagalan validasi | 2 | Sebagian | autentikasi: `autentikasi.masuk/gagal/terkunci/mfa_*/sesi_berakhir/keluar` (`MasukPengguna.php:94,100`, `PenghitungGagal.php:35`, `VerifikasiMfa.php:44,188`, `SesiPengguna.php:120`) | **Penolakan akses (403) dan kegagalan validasi tidak dicatat** di mana pun. Lihat S-4 |
| 7.1.4 | Setiap kejadian memuat informasi untuk investigasi linimasa | 2 | Lulus | `app/Core/Audit/CatatAktivitas.php:34-46` (waktu UTC mikrodetik, pengguna, aksi, subjek, IP, perangkat) | |
| 7.2.1 | Semua keputusan autentikasi dicatat tanpa token/sandi | 2 | Lulus | lihat 7.1.3; tes `AutentikasiTest.php:52,277` | |
| 7.2.2 | Semua keputusan kontrol akses bisa dicatat, yang gagal selalu dicatat | 2 | Tidak | `Gate::before` (`ModuleServiceProvider.php:57-67`) dan `ErrorHandler.php:42` tidak menulis audit/log | Lihat S-4 |
| 7.3.1 | Komponen log meng-encode data agar tidak ada log injection | 2 | Sebagian | audit berbentuk kolom dan `jsonb` (aman); log berkas Laravel memakai `LineFormatter` yang membolehkan baris baru di pesan | Risiko kecil: pesan log aplikasi tidak memuat masukan pengguna (hasil pencarian `Log::` di `app/`) |
| 7.3.3 | Log keamanan terlindung dari akses dan perubahan | 2 | Sebagian | `simrs_app` hanya `SELECT, INSERT` di `audit` (`docker/postgres/init/sql/02-database.sql:32,40`), rantai hash + anchor per jam (ADR 0003); tes `RantaiAuditTest.php:56,67,78,242` | Anchor ke penyimpanan terpisah dan ke SIEM = syarat go-live yang sudah tercatat di backlog Utang. Izin berkas `storage/logs` belum diatur runbook |
| 7.3.4 | Sumber waktu tersinkron, zona jelas | 2 | Lulus | chrony wajib (runbook `instalasi.md:29,45`), `date.timezone = UTC` (runbook `nginx-php.md` §5), audit UTC (`CatatAktivitas.php:45`) | Bergantung runbook (belum merge) |
| 7.4.1 | Pesan umum saat error tak terduga | 1 | Lulus | `app/Core/Http/ErrorHandler.php:51` (500 `kesalahan_server`), debug ditolak di production (`PemeriksaanProduksi.php:30-32`); tes `MaskingLogTest.php:79,90` | Tanpa ID kejadian untuk dukungan (opsional di ASVS). Lihat R-7 |
| 7.4.2 | Penanganan exception di seluruh kode | 2 | Lulus | `bootstrap/app.php:35-50`, `ApiError` dipakai konsisten | |
| 7.4.3 | Penangan error "jalan terakhir" | 2 | Lulus | `bootstrap/app.php:45-49` → `ErrorHandler::render` untuk `api/*`, `sanctum/*` | |

---

## Ringkasan

### 1. Jumlah per hasil per bab (L1 + L2)

| Bab | Lulus | Sebagian | Tidak | n.a. | Jumlah |
|---|---:|---:|---:|---:|---:|
| V2 Authentication | 27 | 8 | 5 | 12 | 52 |
| V3 Session Management | 12 | 2 | 2 | 2 | 18 |
| V4 Access Control | 6 | 3 | 0 | 0 | 9 |
| V5 Validation, Sanitization and Encoding | 25 | 1 | 0 | 4 | 30 |
| V7 Error Handling and Logging | 8 | 3 | 1 | 0 | 12 |
| **Total** | **78** | **17** | **8** | **18** | **121** |

Butir yang hasilnya bergantung #83: 3.7.1 (Sebagian), 4.3.3 (Lulus sebagian), 2.8.4 (MFA ulang memakai jalur yang sama).
Bila #83 tidak merge, 3.7.1 tetap Sebagian (hanya sandi lama dan PIN TTE).

### 2. Temuan Tinggi (wajib diperbaiki sebelum tiket 1.12 selesai)

#### T-1. Cakupan izin pengelola akun dan peran tidak ditegakkan (ASVS 4.1.3, 4.2.1)

**Status: diperbaiki di #86** (5c55e48). Middleware `can.rs:<izin>` (`app/Modules/Akses/Http/Middleware/IzinSeluruhRs.php`)
mewajibkan izin bercakupan seluruh RS di semua rute `pengaturan.akun.*`/`pengaturan.peran.*`. Tes
`PengaturanAkunTest::test_pengelola_bercakupan_unit_tidak_bisa_mengelola_akun_dan_peran` dan tes struktural
`test_semua_rute_pengelolaan_akun_dan_peran_memakai_can_rs` (rute baru, termasuk label 1.5, ikut diperiksa). Uraian
asli temuan di bawah dipertahankan sebagai catatan.

**Yang terjadi.** Rute pengelolaan akun dan peran hanya memakai `can:pengaturan.akun.*` / `can:pengaturan.peran.*`
(`app/Modules/Akses/routes.php:42-61,74`). `can:` memanggil Gate tanpa cakupan, jadi `AccessEvaluator::boleh($data = null)`
lolos bila akun punya izin itu **di cakupan mana pun** (`AccessEvaluator.php:53-58`). Controller dan kelas aplikasinya
(`AkunController.php:38-68`, `AksesAkunController`, `KelolaAksesAkun.php:47-50,319-333`, `KelolaPeran`,
`PermintaanAksesController.php:38`) tidak memeriksa cakupan lagi. Penugasan peran boleh bercakupan `unit`
(`KelolaAksesAkun.php:322-333`), dan layar Akun & akses menawarkan "Unit kerja".

**Akibat.** Peran yang memuat `pengaturan.akun.input/perbaiki` yang ditugaskan dengan cakupan **unit X** (setelah
disetujui superadmin lain, K13) membuat pemegangnya bisa:
- melihat semua akun RS dan "Lihat sebagai" akun mana pun;
- menugaskan peran non-sensitif **bercakupan seluruh RS** ke akun mana pun, yang langsung berlaku tanpa persetujuan;
- menonaktifkan, mengaktifkan, atau mereset MFA akun di luar unit X.

Penyetuju melihat "cakupan: unit X" dan wajar mengira kuasanya terbatas. Ini eskalasi hak.

**Hubungan dengan backlog.** Utang 1.8a/1.2d sudah mencatat "cakupan pemberi belum dibandingkan … aman sekarang karena
`pengaturan.akun.input` sensitif dan hanya superadmin; wajib bila admin delegasi per unit dibuat". Asumsi "hanya
superadmin" **tidak ditegakkan kode**. Selain itu masalahnya lebih luas dari "cakupan pemberi": membaca dan mengubah
akun di luar cakupan juga lolos. Karena itu dinaikkan ke Tinggi.

Mitigasi yang ada: izin ini sensitif (`MenuBawaan::IZIN_SENSITIF`, `Database/MenuBawaan.php:49-53`), jadi pemberiannya
lewat persetujuan dan pemegangnya wajib MFA. Penyalahgunaan butuh satu pemberian bercakupan unit yang disetujui.

**Usulan perbaikan (tutup rapat sampai admin delegasi per unit, FR-09-07, dirancang):**
1. `app/Modules/Akses/routes.php` dan `PermintaanAksesController.php:38`: semua pemeriksaan `pengaturan.akun.*` dan
   `pengaturan.peran.*` mewajibkan cakupan `rs`. Ada dua cara:
   - middleware kecil di modul Akses (mis. `Http/Middleware/IzinSeluruhRs`, alias `can.rs:<kode>`) yang memanggil
     `Gate::authorize($kode, Cakupan::rs())`;
   - atau panggilan `Gate::authorize(…, Cakupan::rs())` di awal setiap aksi, seperti `IzinController.php:26`.

   `Gate::authorize` juga meneruskan kode penolakan lapis 5 di #83 (S-6). Cakupan berlaku sama untuk rute #83:
   `GET akun/{akun}/label` dan `POST akun/{akun}/label/{id}/batal`.
2. `Application/KelolaAksesAkun.php` (`tugaskan`, `buatIzinKhusus`): tolak `422 cakupan_harus_rs` bila peran/izin yang
   diberikan memuat izin pengelola akses (`pengaturan.akun.*`, `pengaturan.peran.*`) dengan cakupan selain `rs`. Tujuannya
   agar tidak ada pemberian yang tampak terbatas tetapi tidak berfungsi. `UnitOrganisasiController::IZIN_LIHAT` (yang
   memakai `pengaturan.akun.input` bercakupan unit untuk pohon unit) disesuaikan.
3. Tes baru di `tests/Feature/Akses/PengaturanAkunTest.php`: pemegang `pengaturan.akun.input/perbaiki/lihat` bercakupan
   unit mendapat 403 di `GET /akun`, `GET /akun/{id}`, `POST /akun/{id}/penugasan`, `POST /akun/{id}/nonaktifkan`,
   `POST /akun/{id}/mfa/reset`. Penugasan peran berizin pengelola dengan cakupan unit ditolak 422.
4. Ubah butir backlog Utang terkait: "aman karena hanya superadmin" diganti "ditegakkan kode (cakupan wajib rs)".

Catatan: ini **memperketat** aturan dan tidak menambah aturan PRD baru (PRD 9.2.5: cakupan harus memuat data). Kode baru
`cakupan_harus_rs` masuk kontrak (`docs/api/openapi.yaml`, endpoint penugasan yang sudah ada).

Tidak ada temuan Tinggi lain. Semua jalur autentikasi, sesi, CSRF, IDOR di modul lain, SQL, XML/SVG, dan masking yang
diperiksa lulus atau hanya punya kekurangan tingkat Sedang/Rendah.

### 3. Temuan Sedang dan Rendah untuk backlog

**Sedang**

| # | Butir ASVS | Temuan | Usulan |
|---|---|---|---|
| S-1 | 3.3.2 | Tidak ada batas umur sesi mutlak. Sesi yang terus dipakai tidak pernah meminta masuk ulang; `SESSION_LIFETIME` bergulir | `SesiPengguna::alasanBerakhir` (`Http/Middleware/SesiPengguna.php:87-101`): akhiri sesi bila `MASUK_PADA` lebih tua dari `autentikasi.sesi.umur_maks_jam` (mis. 12). Angka baru = **keputusan pemilik produk** (PRD 14.2 hanya menyebut batas diam) |
| S-2 | 3.3.4 | Pengguna tidak bisa melihat atau mengakhiri sesi aktifnya | Minimal "keluar dari semua perangkat" untuk akun sendiri memakai `Pengguna::cabutSesi()` (sudah ada). Butuh endpoint baru → kontrak dulu |
| S-3 | 2.2.3, 2.5.5, 2.8.5 | Tidak ada notifikasi saat sandi diganti, MFA didaftarkan atau direset, TOTP dipakai ulang, atau masuk dari luar jaringan RS. Hanya penguncian yang diberitahukan | Event `SandiDiganti`, `MfaDidaftarkan`, `MfaDireset`, `MasukDariLuarJaringan` di modul Akses → `Notifikasi/Application/PendengarKejadian.php` (in-app + email, K2). Bedakan `autentikasi.mfa_dipakai_ulang` dari `mfa_gagal` di `VerifikasiMfa::terimaTotp` |
| S-4 | 7.1.3, 7.2.2 | Penolakan akses (403) dan kegagalan validasi (422) tidak dicatat. Padahal PRD 14.2 Pemantauan meminta peringatan "akses di luar cakupan biasa" | Catat di channel log keamanan terpisah (JSON, ke SIEM), bukan di `audit.log_aktivitas` (menghindari antrean kunci rantai, ADR 0003). Isi: `pengguna_id`, kode izin/kode error, nama rute, IP, waktu; tanpa isi permintaan. Tempat: `Gate::after` di `Akses/ModuleServiceProvider.php` untuk hasil `false`, dan `ErrorHandler` untuk 403/422 |
| S-5 | 4.1.3 | PRD 14.2 meminta uji CI "tidak ada endpoint tanpa kebijakan akses"; belum ada | Tes seperti `RateLimitTest::test_semua_rute_api_v1_berrate_limit`: setiap rute `api/v1` ber-`auth:web` dipanggil oleh akun tanpa izin dan harus 403/404. Pengecualian hanya daftar rute "milik akun sendiri" yang tertulis di tes |
| S-6 | 3.7.1, 4.3.3 (**#83**) | Kode `mfa_ulang_diperlukan`/`izin_praktik_tidak_berlaku` hanya sampai ke klien lewat `can:` atau `Gate::authorize`. Banyak controller memakai `abort_unless(Gate::allows(…), 403)` sehingga klien menerima `akses_ditolak` umum dan tidak bisa memicu MFA ulang. Frontend belum punya dialog MFA ulang (1.5-fe) | Ganti dengan `Gate::authorize(…)` di `PegawaiController.php:111`, `KredensialController.php:115`, `IntegrasiController.php:41,57,88,98`, `TtdController.php:47`, `JenisTugasController.php:92`, `MasterData/.../IzinOrganisasi.php:16`, `KsmController.php:78`, `ProfilRsController.php:36,59`, `PenyiapanController.php:19,26`, `UsulanDataIndukController.php:145`, `MesinUsulan.php:412`, `AkunPegawaiController.php:21`. Sebelum lapis 5 dinyalakan untuk izin mana pun (bawaan `false`) |

**Rendah**

| # | Butir ASVS | Temuan | Usulan |
|---|---|---|---|
| R-1 | 3.4.4 | Cookie sesi tanpa prefiks `__Host-` | `SESSION_COOKIE=__Host-karsitara-session` di runbook dan bawaan `config/session.php`. Syarat (Secure, path `/`, tanpa domain) sudah terpenuhi. Mengubahnya memutus sesi yang berjalan, jadi lakukan saat rilis |
| R-2 | 2.1.2 | Batas atas sandi 200 (ganti) / 1024 (masuk, konsol), bukan 128; frontend tanpa `maxlength` | `max:128` di `GantiSandi.php:14,42`, `Console/BuatSuperadminPertama.php:50`; `maxlength` di `IsianSandi.vue`. Masuk boleh tetap longgar untuk sandi lama |
| R-3 | 2.1.8 | Tidak ada pengukur kekuatan sandi | Indikator sederhana di `GantiSandiPage.vue`. Pustaka seperti zxcvbn = dependensi baru → perlu pemilik |
| R-4 | 2.3.2 | WebAuthn/FIDO belum ada (direncanakan PRD 14.2/10.3.2) | Buat tiket fase berikutnya |
| R-5 | 2.4.5 | Sandi tanpa pepper | Pertimbangkan HMAC berkunci (`vault`) sebelum argon2id, seperti PIN TTE; butuh migrasi hash bertahap. Keputusan arsitektur (ADR) |
| R-6 | 3.2.1 (tes) | `MfaTest.php:59` membandingkan nilai cookie terenkripsi, yang selalu berubah; tes lolos walau ID sesi tidak diganti | Bandingkan `session()->getId()` sebelum dan sesudah masuk (sandi dan MFA) |
| R-7 | 7.4.1 | Error 500 tanpa ID kejadian untuk dukungan | Opsional: `rincian` berisi ID acak yang juga ditulis ke log |
| R-8 | 7.1.2 | `MAIL_MAILER=log` (bawaan `config/mail.php:17`, `.env.example`) menulis alamat dan isi email ke log aplikasi bila terbawa ke produksi | `PemeriksaanProduksi`: tolak `log`/`array` di production, atau runbook memeriksanya |
| R-9 | 7.1.2 | `MaskingProcessor` hanya menyamarkan error SQL dan `bindings`. Exception lain yang dilaporkan (`report($e)` di `KotakKerja.php:108`, `KelolaPegawai.php:90`) tidak disaring | Pedoman: pesan exception tanpa data pribadi. Pertimbangkan masking pola NIK (16 digit) di `MaskingProcessor` sebelum modul pasien (fase 2) |
| R-10 | 7.1.2 / PRD 14.2 | Nama/NIP di query string `?cari=` (`AkunController.php:31`, `PegawaiController.php:30`) masuk log akses nginx | Data pegawai, bukan pasien. Sebelum pencarian pasien (fase 2) tetapkan pola: format log nginx tanpa query string, atau pencarian lewat `POST` |
| R-11 | 5.3.1 | Motif dipasang ke CSS `url(${…})` tanpa kutip (`frontend/src/layouts/AuthLayout.vue:41`) | Beri kutip dan escape (`CSS.escape` atau `JSON.stringify`) |
| R-12 | 5.1.5 | Penyaring `?kembali=` belum diuji untuk varian seperti `/%09/…`, `/%2F%2F…` | Tambah uji unit `alurMasuk.ts` |
| R-13 | 7.3.1 | Log berkas memakai `LineFormatter` yang membolehkan baris baru | Channel `daily` dengan `JsonFormatter` (sekaligus siap SIEM) |
| R-14 | 4.2.1 | Tugas dengan pelaksana `peran` terlihat oleh semua pemegang peran tanpa melihat cakupan unit penugasannya (`Notifikasi/Application/PenerimaTugas.php:69-73`) | Saring dengan cakupan penugasan saat jenis tugas bercakupan unit |
| R-15 | 2.1.7 | Sandi tidak dicek ke daftar bocor saat masuk | Opsional: saat masuk berhasil dan sandi ada di daftar, set `wajib_ganti_sandi` |
| R-16 | (#83) | `MfaUlangController` mereset `percobaan_gagal` sesudah verifikasi ulang berhasil, padahal `PenghitungGagal` menyatakan reset hanya saat masuk selesai | Pertahankan atau tulis alasannya di docstring; dampak kecil (butuh kode MFA benar) |
| R-17 | (#83) | `AccessEvaluator::boleh()` dipanggil langsung (`MasterData/Application/LingkupUnit.php:49`, `KelolaPermintaanAkses.php:37`) melewati lapis 5 | Lewat Gate, atau `boleh()` dijadikan privat untuk lapis 1–3 dan `periksa()` sebagai pintu umum |
| R-18 | (#83) | `penyetuju_peran_id` pengajuan izin label dibekukan saat diajukan; setelah penyetuju label diganti, peran lama masih bisa memutuskan pengajuan yang menunggu | Baca penyetuju terkini saat memutuskan, atau batalkan pengajuan menunggu saat penyetuju diganti |
| R-19 | 4.2.1 | Binding model terjadi sebelum cek izin (404 lawan 403); `kirim-ulang` 404 sebelum 403 (`IntegrasiController.php:86-88`) | Info: oracle keberadaan UUIDv7, tanpa isi. Urutkan cek izin dulu bila mudah |
| R-20 | — | Kepala/ketua unit (`kepala_pegawai_id`) bisa diubah dengan `master_data.organisasi.perbaiki` (tidak sensitif), termasuk oleh pemegang cakupan unit itu sendiri. Kepala unit menentukan penyetuju reset TTE (`Ttd/Application/AtasanLangsung.php`) | Keputusan produk: jadikan perubahan kepala unit lewat usulan berjenjang (1.8c) atau izin terpisah |

**Sudah tercatat di backlog Utang (dirujuk, tidak diulang):** sandi awal tanpa masa berlaku dan reset sandi oleh admin
(2.3.1, 2.5.6); izin `pengaturan.{peran,akun}.lihat` belum sensitif (4.3.1); anchor audit ke penyimpanan terpisah, SIEM,
dan cron (7.3.3); jendela milidetik reset MFA (2.8.6); penetralan formula CSV saat ekspor (5.3.1); pemindaian antivirus
unggahan; rate limit `GET /profil-rs` per IP di balik NAT.

## Butir yang perlu dicek ulang

- **Teks ASVS.** Isi butir ditulis dari ingatan penilai atas ASVS 4.0.3. Yang paling perlu dicocokkan dengan teks resmi:
  - 2.1.7: apakah "login" termasuk saat cek sandi bocor;
  - 3.3.2: apakah L2 meminta batas mutlak 12 jam **dan** batas diam 30 menit, atau cukup salah satu;
  - 2.10.1: syarat "OS assisted";
  - nomor butir yang sengaja tidak dinilai: 4.1.4 dan 7.3.2 (dihapus di 4.0.3, duplikat), serta butir L3 (2.2.4–2.2.7,
    2.8.7, 3.6.x).
- **Perilaku framework, bukan tes proyek:** 2.4.2 (salt argon2id), 3.2.2/3.2.4 (ID sesi), 5.1.1, 5.2.3.
- **Bergantung runbook yang belum merge** (`docs/runbook-1.12`): 4.3.2, 7.3.4, header keamanan (CSP, HSTS,
  X-Frame-Options, Referrer-Policy), dan TLS 1.2+. Semuanya hanya di konfigurasi nginx contoh, tidak ditegakkan
  aplikasi.
- **#83:**
  - lapis 5 belum aktif karena `butuh_mfa`/`butuh_kewenangan_klinis` bawaannya `false`;
  - `PemeriksaLabel::bentuk` (penanda "Terbatas") belum dipakai endpoint mana pun, jadi belum bisa dinilai ujung ke ujung;
  - dialog MFA ulang di frontend belum ada.
- **5.1.3** dinilai dari penelusuran semua rute dan controller, bukan uji fuzz.
