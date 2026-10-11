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
| 1.0e | Fondasi ketahanan beban (PRD 14.1.1): batas waktu per role, paginasi berbatas, antrean per jenis, batas laju per pengguna, `/siap`, kerangka k6. Catatan: paginasi tidak memakai `cursorPaginate()` (mikrodetik hilang); Horizon 5, dasbor ditolak di luar local sampai 1.2; batas laju bawaan 120/menit per akun, layar kerja 600 | Selesai | #23 |
| 1.0c | Nama modul bahasa Indonesia (backend dan frontend). Catatan: panel Spek dev di `docs/html` masih menyebut nama lama (di luar lingkup) | Selesai | #16 |
| 1.0f | Antrean setelah commit, penghitung batas laju di instans `noeviction`, aturan bersih-bersih tiket di CLAUDE.md. Catatan: store `batas-laju` = `cache.limiter`; tes memakai `array` (`CACHE_LIMITER_STORE`) agar `travel()` berlaku | Selesai | #25 |
| 1.2a | Backend akses: tabel menu/peran/izin/penugasan, seeder menu dari prototipe, `AccessEvaluator` lapis 1–3, `GET /api/v1/saya/menu`, superadmin sementara → peran. Catatan: menu bawaan di `MenuBawaan` (dipasang migrasi), halaman yang sudah dibangun ditandai `TERSEDIA`; izin lewat `can:<menu>.<sub_menu>.<aksi>`; e2e kerangka kini ke backend asli dengan `contoh.dokter` | Selesai | #27 |
| 1.2b | Frontend kerangka aplikasi: `AppShell` + `SidebarMenu` dinamis (kontrak `docs/api/openapi.yaml`), `SegeraHadirPage`, responsif PRD 5.5. Catatan: menu aktif tanpa halaman frontend juga ke `SegeraHadirPage`; jalur di luar menu = *Halaman tidak ditemukan* (tidak dibedakan dari tidak diizinkan); e2e masih memakai menu tiruan (lihat utang) | Selesai | #26 |
| — | PRD v0.10.9 (penyelarasan 1.2a, aturan layar peran/akun), kontrak `openapi.yaml` v0.2.0, paket halaman 1.2c | Selesai | #28 |
| 1.0g | Istilah umum: ganti kata baku yang jarang dipakai (galat, surel, jangkar, dll.) dengan istilah yang lazim, di kode, kontrak, PRD, dan layar; glosarium di PRD 10.3.3. Catatan: PRD v0.10.11 dipisah ke #39 (menunggu pemilik; sampai itu glosarium = badan tiket ini), kontrak v0.2.1; kolom `akses.pengguna.email`; kode menu `master_data.*`; variabel .env berganti nama (daftar di PR #29); migrasi lama dan ADR 0003 tetap memuat istilah lama sebagai sejarah | Selesai | #29 |
| 1.2c-be | Backend Pengaturan → Peran & izin, Akun & akses: pindah kode menu ke `pengaturan.*`, endpoint kontrak v0.2.0, wajib MFA per peran/akun, reset MFA, larangan diri sendiri. Kode lain yang semula `data_induk.*` kini `master_data.*` (1.0g). Catatan: kontrak v0.2.2; izin/sub-menu yang dicabut dari peran berstatus batal (kolom pembatalan di `peran_izin`/`peran_menu`); versi peran = kolom `revisi`; pencabutan sesi lewat `akses.pengguna.sesi_dicabut_pada` (ADR 0002); akun `contoh.superadmin` + `AKUN_SINTETIS_SUPERADMIN_MFA_RAHASIA` untuk e2e | Selesai | #32 |
| **1.2c-fe** | Frontend halaman Peran & izin, Akun & akses (`masterdata.html`, temuan MD-1…MD-4), navigasi sub-menu di halaman induk modul | **Siap dikerjakan** setelah PR dokumen (paralel dengan 1.2c-be, data tiruan dari kontrak) | — |
| 1.2d | Persetujuan akses: izin sensitif oleh pemilik data, peran superadmin oleh superadmin lain, permintaan akses dari layar "Akses ditolak", pembuatan/penonaktifan akun | Menunggu 1.2c | — |
| **1.2e** | Beranda: modul saya, keamanan akun, pencarian menu, aksesibilitas (`home.html`); tanpa angka tiruan | Menunggu 1.2c-fe (be kecil bisa setelah 1.2c-be) | — |
| 1.5 | Lapis 4–5 hak akses (label sensitivitas, SIP/SPK, MFA aksi sensitif) | Menunggu 1.2 | — |
| 1.6 | TTE T1: aktivasi PIN, `TtdModal`, kunci Ed25519 | Menunggu 1.2 | — |
| 1.7 | Notifikasi dasar (PRD 7.53), jenis bawaan pertama: pemberitahuan akun terkunci | Menunggu 1.2 | — |
| 1.8 | Wizard penyiapan instalasi kosong dan paket referensi (FR-54-08…10) | Menunggu 1.2 | — |
| 1.10 | SSO (OIDC/SAML) ke penyedia identitas RS/universitas; tombol tampil hanya bila dikonfigurasi | **Terhambat**: perlu data IdP dari RS/USU | — |
| 1.9 | Gateway integrasi dasar + sandbox BPJS/SATUSEHAT (kriteria keluar fase 1). Rujukan: `docs/integrasi/catatan-lapangan-bridging.md` (catatan lapangan aplikasi lain, bukan spesifikasi; perlu verifikasi). Catatan: dibangun dengan server tiruan sampai kredensial sandbox RS mitra tersedia | Menunggu dokumen resmi | — |

## Utang dan keputusan tertunda

Rincian alasan ada di ADR yang dirujuk; di sini hanya daftarnya agar tidak ada dua versi.

**Fitur yang belum dibangun**
- Pemberitahuan akun terkunci ke pemilik (PRD 14.2) → tiket 1.7, issue #11.
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
- Dari library PRD 10.4, baru axios dan TanStack Query (1.2b) yang dipakai. VeeValidate + Zod dan Tailwind/Reka UI belum dipasang; pasang saat halaman pertama yang membutuhkannya, bukan sekaligus.
- Hak akses 1.2a yang belum lengkap: `peran_menu.tingkat_akses/urutan_khusus/label_khusus` (PRD 11.2, layar 9.3) belum dibuat, aksi diambil dari `peran_izin`; cakupan `pasien_dirawat` belum dievaluasi (butuh relasi perawatan); `inti.modul_aktif.unit_organisasi_id` belum berkunci tamu dan modul per unit belum dipakai (menunggu `inti.unit_organisasi`); persetujuan pemilik data untuk izin sensitif dan perubahan peran superadmin di 1.2d (sampai itu ditolak dengan 409 `butuh_persetujuan`/`peran_bawaan_terkunci`, bukan disimpan diam-diam).
- Sesudah 1.0g: worktree yang dibuat sebelum #29 merge (mis. 1.2c-fe) perlu rebase ke `main` dan mengganti `GalatApi`/`galat` → `ApiError`/`error`, `lencana` → `jumlah_tugas`, `tantangan_id` → `challenge_id`, rute `/data-induk` → `/master-data`, ikon `data_induk` → `master_data`; `.env` lokal mengganti variabel yang berganti nama (daftar di PR #29). Berkas anchor audit lokal lama ada di `storage/app/audit/jangkar`: pindahkan isinya ke `storage/app/audit/anchor` bila ingin tetap diverifikasi.
- Sesudah 1.2c-be: data tiruan dan uji unit frontend (`frontend/src/modules/akses/tiruan/menuSaya.ts`, `menu.spec.ts`, `SidebarMenu.spec.ts`, `HalamanMenu.spec.ts`, `usePermission.spec.ts`) masih memakai `master_data.peran|akun` dan rute `/master-data/peran|akun`; backend kini `pengaturan.*` → diganti di 1.2c-fe. Peran yang `wajib_mfa`-nya dinyalakan tidak memutus sesi pemegang tanpa MFA (ADR 0002) → MFA ulang di tiket 1.5 (K11). Penugasan peran berizin sensitif dan pengakhiran penugasan superadmin ditolak 409 sampai tiket 1.2d.
- Izin pengelola akses `pengaturan.{peran,akun}.{input,perbaiki}` ditandai sensitif oleh `MenuBawaan::IZIN_SENSITIF` (1.2c, dari tinjauan #32; PRD 9.2.4): tidak bisa dibagikan lewat peran biasa atau per akun sampai 1.2d, dan pemegangnya wajib MFA. Tiket 1.2d perlu menetapkan peran penyetujunya (K3); admin delegasi per unit (FR-09-07) memakai izin terpisah, bukan izin ini.
- Kecil dari tinjauan #32: `MenuBawaan::pindahKode` melewati diam-diam bila kode lama dan baru sama-sama ada (node lama tetap aktif; hanya mungkin di DB yang disunting manual). `akun_terdampak`/`jumlah_akun` hanya menghitung penugasan yang berlaku sekarang, bukan yang akan berlaku. `berlaku_dari` penugasan boleh diisi mundur (dasar SK lama) — tinjau ulang di 1.2d.
- Teks yang sengaja masih memuat istilah lama: migrasi yang sudah berjalan (sejarah, maju saja), ADR 0003 (diberi catatan nama baru), `docs/fase0/`, prototipe `docs/html/`, badan tiket lama di backlog, dan bagian "Kerja berkelanjutan" di CLAUDE.md (teks disetujui pemilik repo apa adanya).
- Nama ikon yang dikenal frontend = kode menu prototipe (`frontend/src/design-system/ikon/ikon.ts`: `beranda`, `pendaftaran`, `igd`, `rawat_jalan`, `rawat_inap`, `kamar_operasi`, `penunjang`, `farmasi`, `rekam_medis`, `kasir`, `casemix`, `mutu`, `sdm`, `logistik`, `penelitian`, `pendidikan`, `integrasi`, `dasbor`, `master_data`, `pengaturan`). Seeder menu 1.2a memakai nama ini; nama lain tampil sebagai ikon umum.
- Sidebar ringkas 64 px (tablet mendatar) tidak menampilkan sub-menu. Halaman induk setiap modul wajib menampilkan navigasi sub-menunya sendiri (komponen bersama dibuat di 1.2c-fe, wajib dipakai modul berikutnya).
- Pemilih unit kerja dan lonceng notifikasi di header prototipe belum dibuat: unit kerja menunggu `inti.unit_organisasi` (cakupan penugasan per unit sudah ada sejak 1.2a), notifikasi di tiket 1.7.
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

## [SIMRS] Platform · 1.0f Pagar transaksi dan batas laju

```
Tujuan     : Pekerjaan antrean tidak jalan untuk data yang belum tersimpan, dan batas laju tidak hilang
             saat memori cache penuh. Tiket kecil, wajib sebelum 1.2a (modul pertama yang menulis data bisnis).
FR         : PRD 14.1.1 butir 4, 6, 7
Pekerjaan  :
  1. config/queue.php: after_commit = true untuk koneksi antrean aplikasi. Tes: pekerjaan yang dikirim di
     dalam transaksi yang di-rollback tidak pernah dijalankan; yang di-commit dijalankan.
  2. Penghitung RateLimiter (batas laju dan kunci nama pengguna fiktif) disimpan di store berbasis instans
     `valkey` (noeviction), bukan `valkey-cache`. Tes: store limiter menunjuk instans dengan
     maxmemory-policy noeviction.
  3. CLAUDE.md, bagian "Kerja paralel", aturan baru "Tiket selesai = sudah bersih":
     setelah PR merge dan CI main hijau → git worktree remove <jalur lengkap>; git branch -D <branch> dan
     hapus branch remote; DROP DATABASE tes/e2e milik tiket; flushdb Redis/Valkey DB milik tiket;
     docker compose -p <proyek> down untuk stack tambahan; laporan akhir menyertakan `git worktree list`
     (hanya folder utama) dan daftar database yang tersisa.
Uji penerimaan: dua tes di atas lulus dan uji balik (after_commit=false / limiter di cache) membuat tes gagal;
  composer test, analyse, e2e hijau.
Di luar lingkup tiket: modul baru, frontend.
```

## [SIMRS] Akses · 1.2a Backend akses: menu, peran, izin, AccessEvaluator

```
Tujuan     : Server menentukan menu, bagian, dan aksi yang boleh untuk tiap akun (tolak kecuali diberikan),
             dan menyediakan menu efektif untuk sidebar.
FR         : FR-09-01…11, FR-54-04, PRD 9.2.1–9.2.5 (lapis 1–3), 9.2.4, 10.3.2 (aturan MFA), 3.4 (modul aktif)
Kontrak    : docs/api/openapi.yaml → GET /api/v1/saya/menu (bentuk respons wajib sama; uji kontrak)
Tabel      : akses.menu, akses.peran, akses.izin, akses.peran_izin, akses.peran_menu, akses.pengguna_peran,
             akses.pengguna_izin_khusus (PRD 11.1); kolom akses.peran.wajib_mfa (boolean); inti.modul_aktif
Pekerjaan  :
  1. Migrasi tabel di atas (UUIDv7, *_pada, tanpa SoftDeletes, pembatalan lewat status).
  2. Seeder menu dari sidebar prototipe docs/html: grup Pelayanan / Pendapatan / Tata Kelola, semua menu dan
     sub-menunya (kode Indonesia stabil, mis. igd.papan), fase dari PRD D.3. Modul aktif awal: beranda, data_induk.
  3. AccessEvaluator lapis 1 (menu/bagian), 2 (aksi), 3 (cakupan data) dengan rumus PRD 9.2.5:
     cabut per akun selalu menang; tanpa pemberian = ditolak. Cache per akun di Valkey cache, dihapus saat
     peran/penugasan/izin khusus berubah.
  4. GET /api/v1/saya/menu sesuai kontrak, ETag dari `versi`; status "segera" hanya bila
     config menu.tampilkan_segera_hadir = true (bawaan true di local, false di production).
  5. Peran bawaan sistem: superadmin (wajib_mfa, tidak bisa membuka RM/rupiah bawaan, PRD 9.2.4) dan peran
     contoh untuk akun sintetis (admisi, perawat, dokter). Akun yang dibuat akses:superadmin-pertama dipindah ke
     peran superadmin; penanda audit sementara tidak dipakai lagi.
  6. Kebijakan MFA: wajib bila peran mana pun yang aktif wajib_mfa ATAU pengguna.wajib_mfa ATAU akses dari luar
     jaringan RS. Tiga hal yang TIDAK bisa dimatikan admin: superadmin, peran sensitif, akses dari luar jaringan RS.
Uji penerimaan:
  - Akun tanpa penugasan → /saya/menu hanya Beranda; endpoint data induk 403.
  - Peran memberi sub-menu, akun dicabut satu bagian → bagian itu tidak ada di menu/izin dan API 403 (cabut menang).
  - Penugasan kedaluwarsa (berlaku_sampai lewat) → akses hilang tanpa tindakan admin.
  - production: tidak ada item status "segera" di respons; local: ada.
  - Respons /saya/menu lulus validasi skema openapi.yaml.
  - Superadmin dari akses:superadmin-pertama punya peran superadmin dan wajib MFA.
  - Mengubah peran menghapus cache menu akun terkait (versi berubah).
Di luar lingkup tiket: halaman pengelolaan peran/akun (1.2c), lapis 4–5 (1.5), Lihat sebagai, admin delegasi,
persetujuan pemilik data untuk izin sensitif (tiket lanjutan).
```

## [SIMRS] Platform · 1.2b Kerangka aplikasi: AppShell, sidebar dinamis, Segera hadir

```
Tujuan     : Setelah masuk, pengguna melihat kerangka aplikasi seperti prototipe (sidebar, header, area kerja)
             dengan menu dari server, dan menu modul yang belum ada membuka halaman Segera hadir.
FR         : PRD 5.2, 5.4 (AppShell, SidebarMenu, PermissionGate/v-can), 5.5 (responsif), 10.4, 9.2.5
Kontrak    : docs/api/openapi.yaml → GET /api/v1/saya/menu. Sampai 1.2a merge, pakai data tiruan yang
             dibuat dari contoh di kontrak (MSW atau modul tiruan di mode dev/test saja).
Acuan      : sidebar dan header di docs/html/home.html (wajib: grup, label, ikon, menu aktif, kartu RS, kolom
             cari pasien sebagai placeholder nonaktif bertanda "segera"; dibuang: angka lencana palsu)
Pekerjaan  :
  1. AppShell + SidebarMenu: dirender dari /saya/menu (TanStack Query, ETag); menu aktif mengikuti rute;
     lencana hanya bila `lencana` bukan null.
  2. Rute dinamis dari menu: item status "segera" → SegeraHadirPage (nama menu, fase rencana, tombol Kembali ke
     Beranda) dan penanda "Segera hadir" di sidebar.
  3. v-can / PermissionGate dari daftar `izin`.
  4. Responsif PRD 5.5: ≥1280 sidebar 248 px; 1024–1279 sidebar ikon 64 px + tooltip; 768–1023 menu laci;
     <768 menu laci, hanya Beranda/notifikasi/akun. Target sentuh ≥ 44 px di kelas tablet.
  5. Pasang TanStack Query (pertama kali dipakai) dan ikon design-system yang dibutuhkan; tanpa HEX di komponen.
Uji penerimaan:
  - Unit: menu dari respons tiruan dirender per grup; item "segera" membuka SegeraHadirPage; v-can menyembunyikan.
  - e2e di 1440, 1024, 768, 390 px: sidebar sesuai kelas layar; tidak ada gulir horizontal halaman.
  - Setelah 1.2a merge: e2e ke backend asli dengan contoh.dokter (menu sesuai perannya).
  - Tangkapan layar berdampingan dengan docs/html/home.html (sidebar dan header) di PR.
Di luar lingkup tiket: isi beranda/kotak kerja (1.7), pencarian pasien global (fase 2), halaman data induk (1.2c).
```

## [SIMRS] Platform · 1.0g Istilah umum

```
Tujuan     : Nama di kode, API, dan layar memakai kata yang dipakai orang sehari-hari di RS dan tim IT, supaya
             pengembang baru dan pengguna tidak perlu menebak arti kata baku yang jarang dipakai.
Aturan     : 1. Istilah pelayanan dan administrasi RS tetap bahasa Indonesia (pasien, kunjungan, tagihan, peran,
                izin, antrean pasien, kata sandi). Itu bahasa pengguna kita.
             2. Istilah teknis memakai kata yang paling lazim. Bila padanan bakunya jarang dipakai, pakai istilah
                serapan/Inggris yang umum (error, email, cache, token, log, queue, rate limit).
             3. Daftar tetap di PRD 10.3.3 (glosarium istilah) dan dirujuk CLAUDE.md. Istilah baru yang ragu →
                tambahkan ke glosarium lewat PR, jangan diputuskan per berkas.
             4. Hindari di layar dan kode: galat, surel, daring/luring, peladen, pranala, tembolok, kuki, tetikus,
                awakutu.
Glosarium awal (lama → baru):
  galat (JSON `galat`, GalatApi, PemetaGalat, GalatMfa, GalatIsian, variabel galat) → error
      (JSON `{"error": {"kode", "pesan", "rincian"}}`; ApiError, ErrorHandler, MfaError, FieldError)
  surel (kolom akses.pengguna.surel, field API, label) → email
  tantangan (MFA; `tantangan_id`, TantanganMfa) → challenge (`challenge_id`, MfaChallenge)
  jangkar (audit; tabel/kolom, AUDIT_JANGKAR_DISK, channel audit_jangkar) → anchor (AUDIT_ANCHOR_DISK, audit_anchor)
  penyamaran (PenyamarSql, ProsesorPenyamaran, PasangPenyamaran) → masking (SqlMasker, MaskingProcessor, InstallMasking)
  batas laju (limiter `batas-laju`, CACHE_LIMITER_STORE tetap) → rate limit (`rate-limit`)
  proksi tepercaya (ProksiTepercaya, PROKSI_TEPERCAYA) → trusted proxy (TrustedProxies, TRUSTED_PROXIES bawaan Laravel)
  antrean pekerjaan (JenisAntrean, nama queue) → queue (JenisQueue). Kata "antrean" khusus antrean pasien.
  lencana (field menu `lencana`) → jumlah_tugas (artinya jelas: angka tugas terbuka)
  data induk (kode menu `data_induk`, rute /data-induk) → master_data, /master-data (label sudah "Master Data")
  Tetap (sudah lazim): kata sandi/sandi, sesi, kursor, peran, izin, cakupan, berkas, unggah/unduh, dasbor, Segera hadir.
Pekerjaan  :
  1. PRD: salin prd-v0.10.10.md (md5 4f8cb9cf7e1a8dab6cd9acf674234998) sebagai dasar, tambah 10.3.3 Glosarium
     istilah berisi aturan + tabel di atas, ganti istilah lama di teks normatif (10.3.1 format error, 10.3.2
     challenge_id, audit anchor, dll.), naikkan ke v0.10.11. Sisipkan juga tiket-1.2e-beranda.md ke backlog.
  2. Kontrak openapi → v0.2.1: skema Galat → Error (kunci `error`), Menu.lencana → jumlah_tugas, kode menu
     data_induk → master_data. Uji kontrak diperbarui.
  3. Backend: rename kelas/berkas/config/env; migrasi kolom surel → email (RENAME COLUMN, tanpa kehilangan data);
     kode menu data_induk → master_data lewat migrasi data yang aman diulang (pola yang sama dengan 1.2c-be);
     .env.example dan README.
  4. Frontend: tipe dan variabel galat → error, field lencana → jumlah_tugas, surel → email, rute /master-data;
     teks layar yang memakai "galat" diganti kalimat biasa ("Terjadi kesalahan…" atau pesan dari server).
  5. CLAUDE.md: aturan istilah merujuk PRD 10.3.3; .env lokal di folder utama: daftar variabel yang berganti nama
     ditulis di deskripsi PR agar pemilik repo bisa menyesuaikan.
Uji penerimaan:
  - `grep -rniE "galat|surel|jangkar|penyamar|tantangan|lencana|tepercaya"` di backend/app, backend/config,
    frontend/src, docs/api tidak menemukan apa pun (kecuali kutipan sejarah di ADR).
  - composer test, analyse, unit, e2e hijau; login + MFA + sidebar tetap berjalan.
  - Migrasi berjalan di DB lama (1.2a) tanpa kehilangan data; `migrate:rollback` satu langkah juga berjalan.
Di luar lingkup tiket: mengganti nama modul domain (Akses, Pendaftaran, dll.), istilah klinis.
```

## [SIMRS] Akses · 1.2c Paket halaman: Pengaturan → Peran & izin, Akun & akses

Sumber kebenaran: PRD v0.10.9 (9.1, 9.2, 9.3 "Aturan layar…", 10.3.2), kontrak `docs/api/openapi.yaml` v0.2.0.
Acuan visual: `docs/html/masterdata.html` bagian Hak akses dan Akun (bukan acuan data atau perilaku; PRD > prototipe).

**Letak menu (keputusan 17.2 #21):** sub-menu pindah dari Master Data ke Pengaturan Sistem, sesuai PRD 9.1.

| Lama (1.2a) | Baru | Label | Rute |
|---|---|---|---|
| `data_induk.peran` | `pengaturan.peran` | Peran & izin | `/pengaturan/peran` |
| `data_induk.akun` | `pengaturan.akun` | Akun & akses | `/pengaturan/akun` |
| `data_induk.audit` | `pengaturan.audit` | Jejak audit | `/pengaturan/audit` (tetap Segera hadir) |

Izin yang dipakai: `pengaturan.peran.lihat|input|perbaiki`, `pengaturan.akun.lihat|input|perbaiki` (dari `AKSI_DASAR`).

**Dari prototipe: dipertahankan / diubah / dibuang**

| Bagian prototipe | Keputusan |
|---|---|
| Pohon grup → menu → sub-menu → bagian yang bisa dilipat, penanda *ikut induk*, label jenis node | Dipertahankan |
| Matriks dengan semua peran sebagai kolom | **Diubah**: satu peran per suntingan, kolom = aksi (lihat/input/perbaiki + aksi khusus yang terdaftar di node). Alasan: puluhan peran tidak terbaca di 1024 px (PRD 5.5) |
| Tingkat L/I/P per sel | **Diubah**: jalan pintas di layar, yang dikirim tetap daftar kode izin. Bagian dinonaktifkan selama sub-menu induk belum diberi *lihat* (MD-3) |
| Tombol Simpan langsung | **Diubah**: dialog ringkasan (ditambah, dicabut, akun terdampak) + kolom *dasar perubahan* wajib (MD-2); If-Match, 412 → "Peran ini diubah orang lain, muat ulang" |
| Antrean "menunggu persetujuan pemilik data" + tombol "Peragaan: setujui" | **Dibuang** dari 1.2c (1.2d). Menambah izin sensitif → pesan 409 `butuh_persetujuan` yang jelas (MD-4) |
| Daftar akun + panel rincian, tab Penugasan / Per akun / Lihat sebagai | Dipertahankan; tab ke-4 **MFA** ditambahkan |
| Tombol "×" pada pengecualian per akun | **Diubah**: tombol *Akhiri* → dialog dengan alasan wajib dan kalimat akibat; baris tetap tampil sebagai riwayat (MD-1) |
| Tab "Uji akses", label sensitivitas, permintaan akses, uji akses darurat, KPI berangka (2.418 akun, dll.) | **Dibuang** (lapis 4 = 1.5, permintaan akses = 1.2d, KPI tanpa data asli dilarang) |
| Cakupan bebas teks ("Bangsal Penyakit Dalam") | **Diubah**: 1.2c hanya cakupan *Seluruh RS*; pilihan lain tampil nonaktif "menunggu data unit" |
| Catatan superadmin tidak membuka RM/rupiah | Dipertahankan sebagai catatan di rincian akun superadmin |

**Temuan heuristik yang wajib selesai di tiket ini**
- MD-1 Mengakhiri pencabutan mengembalikan akses tanpa konfirmasi → dialog + alasan + riwayat.
- MD-2 Matriks tersimpan tanpa dasar → dasar wajib + ringkasan sebelum simpan.
- MD-3 Baris anak ambigu saat induk tidak diberikan → sel bagian nonaktif + keterangan; server menolak `induk_belum_diberikan`.
- MD-4 Superadmin menyetujui permintaan orang lain → tidak ada tombol setujui di 1.2c; alur persetujuan di 1.2d dengan penyetuju yang ditunjuk.

**Pembagian sesi:** backend (1.2c-be) dan frontend (1.2c-fe) paralel dari kontrak yang sama. Frontend memakai data tiruan dari contoh dan skema kontrak sampai 1.2c-be merge, lalu e2e ke backend asli.

## [SIMRS] Akses · 1.2c-be Backend Peran & izin, Akun & akses

```
Tujuan     : Superadmin bisa menyusun peran, menugaskan peran, memberi/mencabut per akun, mengatur wajib MFA,
             dan mereset MFA lewat API yang menegakkan semua batas PRD 9.2.4 dan 10.3.2 di server.
FR         : FR-09-01…04, 08, 09, 10, 13; FR-54-04; PRD 9.2.3, 9.2.4, 9.3 (aturan layar), 10.3.2
Kontrak    : docs/api/openapi.yaml v0.2.0 → /menu, /peran*, /akun* (uji kontrak semua respons sukses dan galat)
Tabel      : tidak ada tabel baru. Migrasi data: ganti kode menu/izin data_induk.{peran,akun,audit} →
             pengaturan.* (induk_id ikut pindah), aman diulang, tanpa DELETE.
Pekerjaan  :
  1. MenuBawaan: pindahkan peran/akun/audit ke menu pengaturan; TERSEDIA dan SUB_MENU_SUPERADMIN ikut;
     migrasi baru yang mengganti kode di DB yang sudah terpasang (UPDATE akses.menu dan akses.izin).
     Cari semua rujukan `data_induk.peran|akun` (rute can:, tes, e2e, frontend) dan ganti.
  2. GET /menu (pohon + izin per node, modul_aktif, tersedia).
  3. Peran: GET daftar (Paginasi, jumlah_akun, memuat_izin_sensitif), POST (salin_dari_id tanpa izin
     sensitif), GET rinci (ETag = versi), PATCH (If-Match), PUT /peran/{id}/izin (If-Match, ganti utuh,
     turunkan peran_menu dari izin lihat, validasi lihat_wajib / induk_belum_diberikan / izin_tidak_dikenal,
     tolak utuh bila ada izin sensitif baru → 409 butuh_persetujuan, superadmin → 409 peran_bawaan_terkunci,
     peran yang dipegang penyunting → 403 peran_sendiri). Respons memuat ditambah/dicabut/akun_terdampak.
  4. Akun: GET daftar (Paginasi; filter cari/status/peran_id/mfa; tanpa NIP/surel), GET rinci, GET
     /akun/{id}/menu (Lihat sebagai = MenuSaya untuk akun itu, audit akses.lihat_sebagai).
  5. Penugasan dan izin khusus: POST + POST .../batal. Hanya cakupan `rs` (lainnya 422
     cakupan_belum_didukung). Sasaran sensitif tanpa berlaku_sampai → 422. Tambah izin sensitif → 409.
     Penugasan peran superadmin dari API → 409 peran_bawaan_terkunci (tetap lewat konsol sampai 1.2d).
  6. MFA: PUT /akun/{id}/wajib-mfa; POST /akun/{id}/mfa/reset (kosongkan rahasia, mfa_aktif_pada,
     mfa_langkah_terakhir; batalkan kode cadangan; putus semua sesi akun itu). PATCH peran wajib_mfa=false
     ditolak untuk superadmin/peran berizin sensitif. sumber_wajib dihitung dari KebijakanMfa yang sama
     dengan login (jangan dua rumus).
  7. Larangan diri sendiri (403 diri_sendiri) di semua endpoint ubah /akun/{id}/*, dan `diri_sendiri: true`
     di rincian.
  8. Pencabutan sesi (utang ADR 0002): reset MFA dan wajib MFA baru untuk akun tanpa MFA memutus sesi akun
     itu. Perubahan peran/penugasan cukup menghapus cache (izin diperiksa per permintaan); tulis ini di ADR 0002.
  9. Audit catat() di akhir transaksi untuk setiap perubahan: akses.peran_dibuat|diubah|izin_diganti,
     akses.penugasan_dibuat|dibatalkan, akses.izin_khusus_dibuat|dibatalkan, akses.wajib_mfa_diubah,
     akses.mfa_direset, akses.lihat_sebagai; sebelum/sesudah + dasar; tanpa rahasia.
 10. Cache menu efektif dihapus untuk semua akun terdampak (pemegang peran yang diubah; akun yang
     penugasan/izin khususnya berubah). Hindari N+1: hapus cache per batch.
 11. AkunSintetisSeeder (lokal/staging saja): akun `contoh.superadmin` berperan superadmin dengan MFA aktif,
     rahasia TOTP dari env `AKUN_SINTETIS_SUPERADMIN_MFA_RAHASIA` (kosong = acak, ditampilkan sekali seperti
     contoh.mfa), dan peran contoh tanpa izin sensitif untuk e2e frontend. Tidak pernah berjalan di production.
Uji penerimaan:
  - Semua endpoint: tanpa izin → 403; respons lulus skema openapi (sukses dan galat utama).
  - contoh.dokter tidak melihat menu Pengaturan; superadmin melihat pengaturan.peran dan pengaturan.akun.
  - Migrasi kode: DB lama (1.2a) → semua izin/peran_menu/peran_izin lama menunjuk node pengaturan.*;
    menjalankan ulang tidak menggandakan baris.
  - PUT izin: aksi tanpa lihat → 422 lihat_wajib; bagian tanpa induk → 422 induk_belum_diberikan; dua
    penyunting dengan versi sama → yang kedua 412; izin sensitif baru → 409 dan tidak ada yang tersimpan.
  - Superadmin mengubah peran yang ia pegang → 403; menugaskan/menambah izin ke dirinya → 403.
  - Cabut per akun lalu batal → akses kembali sesuai peran, baris lama status batal dengan alasan.
  - Reset MFA: akun yang tadinya masuk kehilangan sesinya; masuk dari luar jaringan RS → 403
    mfa_belum_terdaftar; dari jaringan RS → perlu_daftar_mfa.
  - wajib_mfa=false pada superadmin → 422; sumber_wajib akun superadmin memuat jenis superadmin
    dengan dapat_dimatikan=false.
  - Daftar akun 1.000 akun sintetis: P95 < 300 ms lokal dengan batas 50 (factory, bukan seeder default).
  - composer test, analyse, e2e yang ada tetap hijau.
Di luar lingkup tiket: alur persetujuan (1.2d), cakupan selain rs, admin delegasi, pembuatan akun,
label sensitivitas (1.5), personalisasi urutan/label menu per peran.
```

## [SIMRS] Akses · 1.2c-fe Frontend Peran & izin, Akun & akses

```
Tujuan     : Superadmin mengelola peran dan akses akun dari layar yang aman dipakai (tidak ada perubahan
             akses tanpa dasar dan konfirmasi), di desktop dan tablet.
FR         : PRD 9.3 (aturan layar, MD-1…MD-4), 5.1 butir 9–17, 5.5, FR-09-08 (Lihat sebagai)
Kontrak    : docs/api/openapi.yaml v0.2.0. Sampai 1.2c-be merge: data tiruan dibuat dari skema/contoh kontrak
             (mode dev/test saja), termasuk respons galat 403/409/412/422.
Acuan      : docs/html/masterdata.html bagian Hak akses dan Akun + tabel "dipertahankan/diubah/dibuang" di paket 1.2c
Pekerjaan  :
  1. Komponen bersama NavigasiSubMenu (design-system): halaman induk modul (/pengaturan) menampilkan
     sub-menu dari /saya/menu sebagai kartu/tab; wajib karena sidebar 64 px tidak menampilkan sub-menu.
  2. /pengaturan/peran: daftar peran (cari, status, jumlah akun, penanda bawaan/MFA) + editor per peran:
     pohon menu (lipat/buka), kolom aksi, tingkat L/I/P sebagai jalan pintas, sel bagian nonaktif bila induk
     belum diberi lihat, penanda izin sensitif (gembok). Peran terkunci (alasan_terkunci) = mode baca dengan
     keterangan. Buat peran / salin peran (dialog).
  3. Simpan matriks: dialog ringkasan perubahan (ditambah, dicabut, akun terdampak) + dasar perubahan wajib;
     tangani 412 (muat ulang tanpa kehilangan suntingan diam-diam: tampilkan perbedaan), 409 butuh_persetujuan
     (sebut izin sensitif mana), 422 (sorot node yang salah).
  4. /pengaturan/akun: daftar akun (cari, filter status/peran/MFA, paginasi kursor "Muat lagi") + rincian
     dengan tab Penugasan, Per akun, Lihat sebagai, MFA. Akun diri_sendiri: semua tombol ubah tidak dirender,
     ada keterangan "Perubahan akun sendiri dilakukan superadmin lain".
  5. Penugasan: tambah (peran aktif, cakupan Seluruh RS; pilihan lain nonaktif "menunggu data unit", berlaku
     dari/sampai, dasar wajib) dan Akhiri (dialog, alasan wajib). Riwayat batal/kedaluwarsa dilipat di bawah.
  6. Per akun: tambah/cabut dengan pemilih node dari /menu (cari, jalur label), berlaku sampai wajib untuk
     sasaran sensitif; Akhiri = dialog dengan kalimat akibat (MD-1), bukan "×".
  7. Lihat sebagai: render sidebar dari GET /akun/{id}/menu memakai SidebarMenu yang sama (mode pratinjau,
     tanpa navigasi), plus daftar izin aksi.
  8. MFA: status, daftar sumber kewajiban (yang tidak bisa dimatikan diberi gembok dan alasannya), sakelar
     wajib MFA akun (alasan wajib), Reset MFA (dialog: akibat = sesi diputus, daftar ulang dari jaringan RS;
     alasan + dasar wajib). Teks tetap: "Dari luar jaringan RS, MFA selalu wajib."
  9. Pasang VeeValidate + Zod (form pertama yang membutuhkannya) dan primitif dialog/tab yang aksesibel
     (Reka UI atau setara; fokus terjebak, Esc, kembali fokus). Tanpa HEX di komponen.
 10. Rute v-can: tombol tambah/ubah mengikuti izin input/perbaiki; tanpa izin lihat → Halaman tidak ditemukan.
Uji penerimaan:
  - Unit: L/I/P → himpunan kode benar; bagian nonaktif saat induk tidak diberi; dialog simpan menolak tanpa
    dasar; 412/409/422 menampilkan pesan yang benar; Akhiri pengecualian butuh alasan; diri_sendiri
    menyembunyikan tombol ubah.
  - e2e (1440, 1024, 768): superadmin membuat peran, memberi sub-menu, menugaskan ke akun sintetis, akun itu
    melihat menu baru (masuk sebagai akun itu); cabut per akun lalu akhiri; reset MFA contoh.mfa lalu
    contoh.mfa diminta mendaftar ulang. 390 px: halaman pengaturan menampilkan pesan "gunakan tablet/desktop"
    (PRD 5.5 kelas terbatas).
  - Tidak ada gulir horizontal halaman di 1024 dan 768; matriks boleh bergulir di dalam kotaknya.
  - Tangkapan layar berdampingan dengan masterdata.html di PR.
Di luar lingkup tiket: antrean persetujuan dan permintaan akses (1.2d), label sensitivitas, bandingkan dua peran,
favorit menu.
```

## [SIMRS] Akses · 1.2d Persetujuan akses (garis besar, dirinci setelah 1.2c)

```
Isi        : persetujuan_akses (izin sensitif → pemilik data; peran/penugasan superadmin → superadmin lain),
             permintaan_akses dari layar "Akses ditolak", pembuatan/penonaktifan akun terhubung sdm.pegawai,
             saran jadikan peran (FR-09-15). Menggantikan 409 butuh_persetujuan dan peran_bawaan_terkunci 1.2c.
Butuh dulu : siapa "pemilik data" per izin sensitif (data induk), notifikasi 1.7 untuk penyetuju.
```

## [SIMRS] Platform · 1.2e Beranda

```
Tujuan     : Setelah masuk, pengguna langsung tahu modul apa yang ia punya, bisa membukanya dalam satu klik atau
             lewat keyboard, dan melihat informasi keamanan akunnya. Modul yang belum dibangun membuka Segera hadir.
FR         : PRD 5.1 butir 9–17, 5.4 (AppShell), 5.5 (Beranda wajib berfungsi di <768 px), 9.2.5, 10.4, FR-54-10
Acuan      : docs/html/home.html. Dipertahankan: salam, kisi modul berkartu (ikon, nama, ringkasan sub-menu),
             blok Kotak kerja, Pintasan, Ctrl K. Diubah: kisi modul hanya berisi menu milik akun (dari /saya/menu),
             bukan semua "modul peragaan". Dibuang: angka KPI/tugas tiruan, tombol "Peragaan · lihat sebagai",
             tautan anjungan/display/halaman masuk (bukan menu kerja).
Backend (be, kecil):
  1. Kolom akses.pengguna.masuk_sebelumnya_pada dan masuk_sebelumnya_dari_jaringan_rs (boolean), diisi dari nilai
     login_terakhir_pada SEBELUM ditimpa saat masuk berhasil. Alasan: login_terakhir_pada sekarang selalu = sesi
     ini, jadi tidak berguna untuk "apakah ini Anda?".
  2. GET /api/v1/saya ditambah: peran (nama peran yang berlaku), masuk_sebelumnya_pada,
     masuk_sebelumnya_dari_jaringan_rs. Endpoint /saya dipindahkan ke docs/api/openapi.yaml (skema Saya) dengan
     uji kontrak; tidak mengirim surel bila tidak dipakai layar.
Frontend (fe):
  1. Salam (pagi/siang/sore/malam dari jam perangkat), nama, peran yang berlaku, tanggal.
  2. "Modul saya": kartu per menu dari /saya/menu, urutan grup sidebar; ringkasan = 3 label sub-menu pertama
     + "dan N lainnya". Menu status segera → kartu dengan lencana "Segera hadir · fase N" yang membuka
     SegeraHadirPage. Klik sub-menu langsung dari kartu (tanpa harus membuka sidebar, penting di tablet 64 px).
  3. Kotak kerja: keadaan kosong jujur ("Tugas dan pengingat tampil di sini setelah modul notifikasi aktif"),
     tanpa angka. Komponen disiapkan agar 1.7 tinggal mengisi data.
  4. Keamanan akun: "Masuk sebelumnya: <waktu>, dari jaringan RS / dari luar jaringan RS", status MFA, dan
     tautan "Bukan Anda? Hubungi <unit admin>" dari profil RS. Pertama kali masuk → "Ini masuk pertama Anda".
  5. Pencarian menu Ctrl/Cmd+K (dan tombol di header): cari di sub-menu yang diizinkan saja; panah atas/bawah,
     Enter, Esc. Kolom cari pasien tetap nonaktif bertanda Segera hadir.
  6. Informasi umum: kartu profil RS (nama, kontak unit admin/helpdesk dari profilRs) dan pita DEMO bila
     instalasi demo (FR-54-10). Tanpa pengumuman (butuh tabel dan pemilik konten; tiket 1.7).
  7. Aksesibilitas: tautan "Lompat ke konten", landmark (header/nav/main), urutan heading h1→h2, fokus terlihat,
     target sentuh ≥ 44 px di kelas tablet, lencana Segera hadir dibacakan pembaca layar, prefers-reduced-motion,
     zoom 200 % tanpa gulir horizontal.
Uji penerimaan:
  - contoh.dokter dan contoh.admisi melihat kisi modul berbeda sesuai perannya; akun tanpa penugasan hanya melihat
    Beranda dengan keterangan "Belum ada modul yang diberikan, hubungi <unit admin>".
  - Kartu Segera hadir membuka SegeraHadirPage; kartu aktif membuka halamannya.
  - Masuk dua kali: kali kedua menampilkan waktu masuk pertama, bukan waktu sekarang.
  - Ctrl/Cmd+K hanya menemukan sub-menu yang diizinkan; dapat dipakai penuh dengan keyboard.
  - e2e 1440/1024/768/390 tanpa gulir horizontal; @axe-core/playwright tanpa pelanggaran serius/kritis di Beranda.
  - Tidak ada angka tiruan di bundle produksi (pemeriksaan yang sama dengan 1.2c-fe).
  - Tangkapan layar berdampingan dengan home.html di PR.
Di luar lingkup tiket: isi kotak kerja dan lonceng (1.7), pemilih unit kerja (menunggu inti.unit_organisasi),
favorit menu, pencarian pasien (fase 2).
```

<!-- Catatan untuk tiket 1.8 (wizard penyiapan): langkah 3 kini mencakup lokasi fisik bertingkat (PRD FR-54-11…14)
     dan kamar/TT dengan atribut standar (FR-08-16…19); impor CSV lokasi wajib. -->

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
