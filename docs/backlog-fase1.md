# Backlog Fase 1 (Fondasi)

> Sumber tiket untuk semua sesi. Kebutuhan tetap di `docs/prd.md`; file ini hanya urutan, status, dan isi tiket yang belum jalan.
> Alur kerja per halaman (paket halaman → backend & frontend paralel → integrasi → selesai): PRD D.5. Menu ke modul yang belum dibangun: *Segera hadir* (PRD 10.4).
> Format tiket: PRD Lampiran D.6. Definition of Done: PRD D.7. Ubah status lewat PR yang menyelesaikan tiketnya.

| Tiket | Isi singkat | Status | PR |
|---|---|---|---|
| 1.0 | Prefiks `api/v1`, nama produk, PHP 8.3 | Selesai | #3 |
| 1.1 | Autentikasi sandi, cookie Sanctum, Argon2id, kunci akun, audit | Selesai | #4 |
| 1.3 | Audit berpartisi, rantai hash, jangkar per jam | Selesai | #5 |
| 1.3b | Penyamaran log, guard production | Selesai | #6 |
| — | Node 24 | Selesai | #7 |
| — | PRD v0.10.2 kontrak MFA | Selesai | #8 |
| 1.4a | Halaman masuk (frontend), e2e, Retry-After | Selesai | #9 |
| 1.4b | Halaman masuk: penyelarasan visual dengan prototipe (motif, ilustrasi, label), cek sesi tahan server mati. Catatan: ilustrasi peta TT dipertahankan (pola tetap, warna token); jam WIB tidak ditampilkan (produk generik) | Selesai | #10 |
| 1.0b | Kebersihan repo: folder modul, Larastan level 5, aturan worktree. Catatan: `composer test` kini membaca `DB_DATABASE_TES` (bawaan `simrs_test`) agar tiap worktree bisa memakai database tes sendiri | Selesai | #12 |
| 1.1b | MFA TOTP + kode cadangan, backend dan langkah MFA di halaman masuk. Catatan: penghitung gagal baru direset setelah MFA lolos; `AUTENTIKASI_MFA_JARINGAN_RS` kosong = semua dianggap dari luar | Selesai | #15 |
| 1.1c | Pendaftaran MFA hanya dari jaringan RS + superadmin pertama lewat konsol. Catatan: penanda superadmin sementara audit `akses.superadmin_dibuat`; e2e butuh `PROKSI_TEPERCAYA=127.0.0.1` | Selesai | #22 |
| 1.0d | Ganti Redis ke Valkey: dua instans (cache `allkeys-lru`, sesi+antrean `noeviction`), compose, CI, dokumentasi. Catatan: `valkey/valkey:9.1.2-alpine`; CI menjalankan Valkey lewat `docker compose` (kontainer `services:` tidak bisa diberi argumen); skrip install esbuild/fsevents ditolak lewat `allowScripts` | Selesai | #21 |
| **1.0e** | Fondasi ketahanan beban (PRD 14.1.1): batas waktu per role, paginasi berbatas, antrean per jenis, batas laju per pengguna, `/siap`, kerangka k6 | **Siap dikerjakan** | — |
| 1.0c | Nama modul bahasa Indonesia (backend dan frontend). Catatan: panel Spek dev di `docs/html` masih menyebut nama lama (di luar lingkup) | Selesai | #16 |
| 1.2 | Menu, peran, izin, `AccessEvaluator` lapis 1–3, `GET /api/v1/saya/menu` | Menunggu 1.1b | — |
| 1.5 | Lapis 4–5 hak akses (label sensitivitas, SIP/SPK, MFA aksi sensitif) | Menunggu 1.2 | — |
| 1.6 | TTE T1: aktivasi PIN, `TtdModal`, kunci Ed25519 | Menunggu 1.2 | — |
| 1.7 | Notifikasi dasar (PRD 7.53), jenis bawaan pertama: pemberitahuan akun terkunci | Menunggu 1.2 | — |
| 1.8 | Wizard penyiapan instalasi kosong dan paket referensi (FR-54-08…10) | Menunggu 1.2 | — |
| 1.10 | SSO (OIDC/SAML) ke penyedia identitas RS/universitas; tombol tampil hanya bila dikonfigurasi | **Terhambat**: perlu data IdP dari RS/USU | — |
| 1.9 | Gateway integrasi dasar + sandbox BPJS/SATUSEHAT (kriteria keluar fase 1) | Menunggu dokumen resmi | — |

## Utang dan keputusan tertunda

Rincian alasan ada di ADR yang dirujuk; di sini hanya daftarnya agar tidak ada dua versi.

**Fitur yang belum dibangun**
- Pemberitahuan akun terkunci ke pemilik (PRD 14.2) → tiket 1.7, issue #11.
- Pencabutan sesi saat peran berubah (PRD 14.2) → tiket 1.2 (ADR 0002).
- Superadmin dari `akses:superadmin-pertama` ditandai lewat audit `akses.superadmin_dibuat` (tanpa kolom baru) → tiket 1.2 memetakannya ke peran superadmin dan mengganti hitungan batas 2 di `SuperadminPertama`.
- Registri perangkat terdaftar: sampai ada, semua sesi memakai batas diam 15 menit (`PerangkatBelumTerdaftar`). Belum ada tiket (ADR 0002).
- Ganti pengguna cepat di komputer ruangan (kartu pegawai/PIN, PRD 14.2). Belum ada tiket (ADR 0002).
- Cek sandi kontekstual (sandi memuat nama pengguna, nama RS, atau nama produk) saat fitur ganti sandi dibuat (ADR 0001).

**Syarat sebelum go-live**
- Uji beban penulisan audit: semua penulisan antre di satu kunci rantai sampai commit (ADR 0003, PRD 14.1).
- `AUDIT_JANGKAR_DISK` ke penyimpanan terpisah yang tidak bisa ditimpa (mis. MinIO object lock) dan channel log `audit_jangkar` ke SIEM (ADR 0003).
- Cron `php artisan schedule:run` di server (partisi audit, jangkar per jam; ADR 0003).
- `AUTENTIKASI_MFA_JARINGAN_RS` diisi jaringan RS yang sebenarnya (PRD 10.3.2; ditegakkan di tiket 1.1c).
- Daftar sandi bocor diperbarui dari SecLists terbaru (ADR 0001, `SUMBER.md`).

**Repo dan teknis**
- Aturan cabang `main` (merge hanya lewat PR, check `backend`/`frontend`/`e2e` wajib hijau) **belum aktif**: GitHub Free menolak proteksi cabang dan rulesets untuk repo privat. Perlu GitHub Pro atau repo dipindah ke organisasi berpaket Team. Sampai itu, merge setelah CI hijau dijaga lewat kebiasaan kerja.
- Dependensi frontend tertinggal satu-dua versi mayor (per 11 Okt 2026): `vite` 6 → 8, `@vitejs/plugin-vue` 5 → 6, `typescript` 5.8 → 7, `vue-tsc` 2 → 3, `vue-router` 4 → 5, `@vue/tsconfig` 0.7 → 0.9. Naikkan dalam tiket sendiri, bukan sambil lalu.
- Profil RS di frontend sementara dari variabel Vite (`VITE_RS_NAMA`, `VITE_RS_LOGO`, `VITE_RS_MOTIF`, `VITE_RS_FOTO`, `VITE_RS_UNIT_ADMIN`, `VITE_RS_KONTAK_ADMIN`; `frontend/src/app/profilRs.ts`). Nilai contoh RS USU hanya di `frontend/.env.development`; build produksi tanpa nilai ini tidak menampilkan kartu RS, motif, dan foto gedung. Ganti dengan `inti.profil_rs` lewat API di tiket 1.8.
- Dari library PRD 10.4, baru axios yang dipakai. TanStack Query, VeeValidate + Zod, dan Tailwind/Reka UI belum dipasang; pasang saat halaman pertama yang membutuhkannya, bukan sekaligus.
- `AppShell.vue` masih placeholder (judul Beranda, nama pegawai, tombol Keluar) sampai `SidebarMenu` dan `GET /api/v1/saya/menu` di tiket 1.2.
- Tombol, kolom isian, dan pesan galat di halaman masuk masih ditulis per komponen (`LangkahSandi.vue` dan `components/langkah-mfa.css` bergaya sama). Pindahkan ke `frontend/src/design-system/` saat komponen dasar dibuat (DESIGN.md).

Branch `lampiran/pr-1.4b` berisi gambar yang dirujuk deskripsi PR #10; jangan dihapus selama gambar itu masih dibutuhkan.

Motif `docs/brand/motif-usu.svg` dibuat ulang (bukan berkas resmi pedoman); hanya untuk pengembangan sampai dicocokkan dengan `PATTERN DESIGN USU.ai` dari Humas/Promosi USU.

Panel merek halaman masuk memakai foto gedung (`frontend/public/foto/rs-usu.webp`, turunan `docs/brand/RS USU Gedung.jpg`: dipotong 16:10, tanpa EXIF/GPS) menggantikan ilustrasi peta tempat tidur. Prototipe `docs/html/login.html` masih memakai peta tempat tidur. Hak pakai foto untuk materi di luar pengembangan belum dikonfirmasi ke Humas/Promosi USU.

---

## [SIMRS] Akses · 1.1c Pendaftaran MFA hanya dari jaringan RS

```
Tujuan     : Sandi curian tidak bisa dipakai mendaftarkan autentikator dari internet, dan instalasi baru
             tetap bisa membuat superadmin pertama.
FR         : PRD 10.3.2 (aturan pendaftaran MFA), FR-54-08 langkah (1), 14.2
Tabel      : akses.pengguna (tidak ada kolom baru)
API        : POST /api/v1/autentikasi/masuk → 403 mfa_belum_terdaftar dari luar jaringan RS untuk akun tanpa MFA
Konsol     : php artisan akses:superadmin-pertama (membuat akun superadmin bernama, menampilkan URI otpauth dan
             10 kode cadangan SEKALI di terminal; menolak berjalan bila sudah ada 2 superadmin aktif)
Uji penerimaan:
  - Dari luar CIDR RS, akun tanpa MFA → 403 mfa_belum_terdaftar; tidak ada tantangan dibuat; tercatat di audit.
  - Dari dalam CIDR RS, akun tanpa MFA yang wajib → perlu_daftar_mfa (perilaku 1.1b tetap).
  - Dari luar CIDR RS, akun yang sudah ber-MFA → perlu_mfa (tetap bisa masuk).
  - AUTENTIKASI_MFA_JARINGAN_RS kosong → login web biasa ditolak dengan pesan konfigurasi (503 konfigurasi_belum_lengkap);
    perintah konsol tetap berjalan.
  - Perintah konsol: idempoten terhadap superadmin ke-3 (ditolak), tidak menulis rahasia ke log.
  - Frontend: pesan mfa_belum_terdaftar dan konfigurasi_belum_lengkap tampil jelas di halaman masuk (unit tes + e2e).
Di luar lingkup tiket: reset MFA oleh admin (tiket 1.2), WebAuthn.
```

## [SIMRS] Platform · 1.0d Ganti Redis ke Valkey

```
Tujuan     : Komponen cache/antrean berlisensi BSD untuk produk yang dipasang di banyak RS.
FR         : PRD 10.1 (baris Antrean pekerjaan)
Pekerjaan  : docker-compose.yml dan .github/workflows/ci.yml: redis:7-alpine → valkey/valkey dengan versi
             mayor-minor dipatok (bukan tag mengambang). Nama layanan/env tetap (REDIS_HOST dll.) agar konfigurasi
             Laravel tidak berubah. README dan CLAUDE.md: sebut Valkey.
             Dua instans (PRD 14.1.1 butir 6): `valkey-cache` (maxmemory-policy allkeys-lru) untuk cache, dan
             `valkey` (noeviction) untuk sesi dan antrean. config/database.php: koneksi `cache` ke instans cache;
             `default`/sesi/antrean ke instans noeviction.
Uji penerimaan:
  - composer test, e2e, dan Horizon/queue lokal berjalan dengan Valkey; sesi login tersimpan dan dibaca.
  - Tag image tertulis lengkap (mis. 8.x.y-alpine), dicek ada di registry.
  - CONFIG GET maxmemory-policy: allkeys-lru di instans cache, noeviction di instans sesi/antrean (tes otomatis).
Di luar lingkup tiket: Sentinel/HA (tiket infrastruktur produksi).
```

## [SIMRS] Platform · 1.0e Fondasi ketahanan beban

```
Tujuan     : Sebelum modul-modul membuat daftar kerja, sediakan pagar bersama agar daftar ribuan baris,
             lonjakan pengguna, atau sistem luar yang mati tidak menghentikan server.
FR         : PRD 14.1.1 butir 1–4, 7, 8, 10; D.7
Pekerjaan  :
  1. statement_timeout per role di skrip init: simrs_app 5s, simrs_analitik 5min (ALTER ROLE ... SET).
  2. Paginasi bersama di app/Core: helper/trait paginasi keyset (kursor (waktu, id)), ukuran bawaan 50,
     maksimum 200, di atas batas → 422 `ukuran_halaman_melebihi_batas`. Didokumentasikan di CLAUDE.md
     sebagai satu-satunya cara membuat endpoint daftar.
  3. Antrean per jenis: integrasi, notifikasi, dokumen, laporan, bawaan (config/queue.php + Horizon
     supervisor per antrean dengan jumlah pekerja dan batas waktu sendiri). Laravel Horizon dipasang bila belum.
  4. Batas laju per pengguna untuk semua rute api/v1 (bawaan, bisa dilonggarkan per rute), selain batas
     masuk yang sudah ada.
  5. Pemeriksaan kesehatan: /up (hidup) dan /api/v1/siap (DB, Valkey sesi, Valkey cache dapat dijangkau)
     tanpa data sensitif dan tanpa autentikasi, dengan batas laju.
  6. Kerangka uji beban k6 di repo (tests/beban/): skenario masuk + GET /api/v1/saya dengan profil
     PRD 14.1.1 diperkecil; dijalankan manual ke lingkungan lokal/staging, BUKAN di CI.
Uji penerimaan:
  - Query yang sengaja lambat (pg_sleep 6) sebagai simrs_app gagal karena timeout, bukan menggantung.
  - Endpoint contoh memakai helper paginasi: ukuran 201 → 422; kursor bekerja dua halaman berturut-turut.
  - Pekerjaan di antrean integrasi yang macet tidak menunda pekerjaan di antrean notifikasi (tes).
  - Batas laju per pengguna mengembalikan 429 + Retry-After.
  - /api/v1/siap menjawab 503 bila Valkey sesi mati.
  - Skrip k6 berjalan lokal dan menghasilkan ringkasan P95.
Di luar lingkup tiket: circuit breaker per sistem luar (tiket 1.9), pemantauan dan peringatan produksi,
Octane dan PgBouncer di produksi (tiket infrastruktur).
```

## [SIMRS] Akses · 1.10 SSO universitas (terhambat)

```
Tujuan     : Pegawai/peserta didik yang punya akun universitas bisa masuk dengan SSO, tanpa
             melemahkan aturan akun dan MFA SIMRS.
FR         : FR-54-07 (SSO opsional), PRD 14.2 baris Autentikasi, 10.3.2
Butuh dulu : dari RS/USU: protokol IdP (OIDC atau SAML), endpoint/metadata, client id/secret (vault),
             atribut yang dikirim (NIP/NIM, surel), dan apakah IdP sudah menerapkan MFA.
Aturan     : - Tidak ada pembuatan akun otomatis: identitas SSO hanya ditautkan ke akses.pengguna yang
               sudah ada (tautan dibuat admin, atau oleh pengguna setelah masuk dengan sandi).
             - Akun wajib MFA tetap melewati langkah MFA SIMRS, kecuali IdP terbukti mengirim bukti MFA
               (klaim amr/acr) dan RS menyetujuinya tertulis.
             - Tombol "Masuk dengan SSO" hanya dirender bila SSO dikonfigurasi di instalasi itu.
             - Hasil akhirnya tetap sesi cookie yang sama (PRD 10.3.2); audit autentikasi.sso_*.
Uji penerimaan: ditulis saat data IdP tersedia.
Di luar lingkup tiket: pembuatan akun dari IdP, sinkronisasi peran dari IdP.
```
