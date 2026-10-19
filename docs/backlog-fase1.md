# Backlog Fase 1 (Fondasi)

> **Fase 1 selesai di kode** kecuali 1.10 (terhambat data IdP). Tiket aktif: [`docs/backlog-fase2.md`](backlog-fase2.md). Tabel "Jalur kerja" di bawah adalah catatan sejarah; aturan kerja yang berlaku ada di skill `kerja-tiket`.

> Sumber tiket untuk semua sesi. Kebutuhan tetap di `docs/prd.md`; file ini hanya urutan, status, dan isi tiket yang belum jalan.
> Alur kerja per halaman (paket halaman → backend & frontend paralel → integrasi → selesai): PRD D.5. Menu ke modul yang belum dibangun: *Segera hadir* (PRD 10.4).
> Format tiket: PRD Lampiran D.6. Definition of Done: PRD D.7. Ubah status lewat PR yang menyelesaikan tiketnya.

> Tiket **selesai** dipindah ke [`docs/arsip/backlog-fase1-selesai.md`](arsip/backlog-fase1-selesai.md). Di sini hanya tiket yang masih berjalan, keputusan bawaan, dan utang.

| Tiket | Isi singkat | Status | PR |
|---|---|---|---|
| 1.13-be / 1.13-fe | Perangkat tepercaya: MFA sekali per perangkat per periode, kebijakan diatur di layar | be: Selesai (kontrak #99, backend #107). Catatan: `akses.perangkat_tepercaya` (hash token), `akses.kebijakan_keamanan`, `akses.peran.masa_percaya_hari`; `KelolaPerangkat` (masa percaya efektif = terkecil instalasi/peran/batas superadmin 7/sensitif 14); cookie HttpOnly/SameSite=Strict path `/api/v1/autentikasi`; langkah 1 dari jaringan RS melewati MFA (audit `mfa_dilewati`); MFA ulang K11 tak dilewati; pencabutan saat ganti/reset sandi, reset MFA, nonaktif; sub-menu `pengaturan.keamanan` (perbaiki sensitif + `butuh_mfa`); ADR 0002 diperbarui. fe: Selesai (#103 kotak "Percayai perangkat" di MFA/pendaftaran; #108 menu akun `/perangkat`, tab Perangkat, Pengaturan → Keamanan, masa percaya di info peran; sub-menu TERSEDIA migrasi `2026_10_17_100000`) | #99, #103, #107, #108 |
| 1.14-be / 1.14-fe | Reset sandi oleh admin + perintah konsol darurat | be: Selesai (kontrak #97, backend #98). Catatan: `Akses\Application\ResetSandi` (sandi sementara `max(20, panjang_minimal)`, juga untuk sandi awal akun baru; wajib ganti, sesi diputus, kunci karena sandi salah dibuka, MFA tetap); `akses:reset-sandi` ditolak bila masih ada superadmin aktif lain kecuali `--walau-ada-superadmin-lain` (konfirmasi kedua, audit `superadmin_lain_aktif`), audit mencatat `SUDO_USER` dan pengguna efektif; sesi lama mendapat 401 `belum_masuk` (hash sandi berubah) atau `sesi_dicabut`; fe: Selesai (#101). Catatan fe: tombol "Reset sandi" di Pengaturan → Akun & akses (dialog alasan + dasar, `SandiSekali` bersama Buat akun, sandi sementara sekali tampil + salin), label "Sandi dari admin" di layar ganti sandi wajib, "Lupa sandi?" di halaman masuk | #97, #98, #101 |
| 1.15-be / 1.15-fe | Tema bawaan dari pedoman identitas RS mitra; halaman masuk dapat diatur (teks, foto, motif) | be: Selesai (kontrak #97, backend #104, potong foto 16:10 + buang EXIF #105 `ext-gd`). Catatan: `inti.profil_rs.halaman_masuk` (jsonb, null = bawaan), `TeksHalamanMasuk` (teks biasa, panjang code point sesudah dibersihkan), `ProfilRs::WARNA_BAWAAN` #006937/#F3C300. fe: Selesai (#95 palet pedoman + `PanelMerek`, #100 tab Halaman masuk) | #95, #97, #100, #104, #105 |
| 1.10 | SSO (OIDC/SAML) ke penyedia identitas RS/universitas; tombol tampil hanya bila dikonfigurasi | **Terhambat**: perlu data IdP dari RS/USU | — |

## Jalur kerja sampai Fase 1 selesai

Sesi 1 = backend, kontrak, dokumen. Sesi 2 = frontend. Tiket `-be` dan `-fe` dengan nomor sama berjalan paralel dari
kontrak yang sama. Bila jalur sendiri kosong, ambil tiket jalur lain atau tinjau PR.

| Urutan | Sesi 1 (backend) | Sesi 2 (frontend) | Prasyarat |
|---|---|---|---|
| 1 | 1.0g Istilah umum | Persiapan 1.2c-fe | — |
| 2 | 1.2c-be | 1.2c-fe | 1.0g |
| 3 | 1.2e-be | 1.2e-fe Beranda | 1.2c |
| 4 | 1.8a-be Profil RS, unit, KSM | 1.11 Design system | 1.2c |
| 5 | 1.2d-be Persetujuan akses | 1.8a-fe | 1.8a-be (kontrak) |
| 6 | 1.8b-be Lokasi, ruang, TT | 1.2d-fe | 1.8a |
| 7 | 1.8d-be Pegawai, izin praktik, akun dari pegawai | 1.8b-fe | 1.8a |
| 8 | 1.7-be Notifikasi, tugas, eskalasi | 1.8d-fe | 1.8a, 1.8d, 1.2d |
| 9 | 1.6-be TTE T1 | 1.7-fe | 1.7 |
| 10 | 1.8c-be Usulan data induk berjenjang | 1.6-fe | 1.7 |
| 11 | 1.5-be Lapis 4–5 hak akses | 1.8c-fe | 1.8d, 1.2d, 1.6 |
| 12 | 1.8e-be Paket referensi + 1.8f-be Wizard | 1.5-fe | 1.8a–d |
| 13 | 1.9-be Gateway integrasi + simulator | 1.8f-fe Wizard | 1.8b, 1.8f |
| 14 | 1.12 Gerbang keluar Fase 1 (be) | 1.9-fe Integrasi | semua di atas |
| 15 | — | 1.12 (fe: e2e menyeluruh, axe) | — |
| 16 | 1.16 Siapkan .env lokal → 1.14-be → 1.13-be → 1.15-be | 1.15-fe (dari kontrak) → 1.14-fe → 1.13-fe | 1.12 |

**Kontrak dulu.** Setiap tiket `-be` dimulai dengan PR kontrak `docs/api/openapi.yaml` berisi endpoint yang tercantum di
tiket. PR kontrak ditinjau sesi 2 (pemakai) + subagen, lalu boleh merge sendiri karena mengikuti tiket yang disetujui.
Endpoint di luar tiket = "[Perlu pemilik]". Sesi 2 memakai data tiruan dari kontrak sampai `-be` merge.

## Keputusan bawaan (berlaku kecuali pemilik produk mengubahnya)

| # | Pokok | Keputusan bawaan | Alasan / risiko |
|---|---|---|---|
| K1 | Notifikasi real-time | Fase 1: polling 30 detik dengan ETag; Reverb (WebSocket) di fase 2 bersama papan IGD/peta TT | Lebih sedikit komponen; terlambat ≤ 30 detik untuk notifikasi non-kritis |
| K2 | Kanal notifikasi fase 1 | In-app + email (SMTP dari konfigurasi). WhatsApp/SMS: antarmuka adapter dengan driver `log` saja | Gateway WA/SMS butuh kontrak pihak ketiga |
| K3 | Pemilik data izin sensitif | Ditetapkan per izin sensitif sebagai **peran penyetuju** di data induk. Izin sensitif tanpa penyetuju tidak bisa diberikan | PRD 9.2.4; tanpa penyetuju = kontrol ganda tidak mungkin |
| K4 | Perubahan terkait superadmin | Disetujui superadmin lain (bukan pengaju); permintaan kedaluwarsa 7 hari | PRD 9.2.4 |
| K5 | Pembuatan akun | Akun dibuat dari data pegawai (1.8d). Akun peserta didik menunggu modul 46 (fase 5) | Satu akun = satu pegawai (11.2) |
| K6 | Wizard langkah 6–7 (penjamin, tarif, formularium) | Kerangka langkah dibuat di 1.8f, isinya di awal fase 2 (sebelum pendaftaran). Instalasi tetap "belum bisa melayani pasien" sampai langkah itu lengkap | Tarif adalah modul besar milik fase 2 |
| K7 | Paket referensi | Dikemas: wilayah, UCUM, subset LOINC (atribusi), kode variabel RME. ICD-10/ICD-9-CM: **tidak dikemas**, diimpor dari berkas milik RS (mis. dari E-Klaim) | Lisensi WHO perlu verifikasi (FR-54-09) |
| K8 | Kriteria keluar Fase 1 tanpa RS | Sandbox BPJS/SATUSEHAT diganti **simulator + uji kontrak** berbasis `/Users/ejake/Documents/SIMRS Tipe B/catatan-lapangan-bridging.md` (di luar repo). Sambungan sandbox nyata dipindah menjadi syarat **masuk pilot fase 2** bersama SUS dan design partner. PRD 16/16.1 sudah diubah di PRD v0.10.12 (disetujui pemilik) | Kredensial sandbox diberikan ke faskes, bukan vendor [Medium] |
| K9 | Kredensial integrasi | Tabel `integrasi.kredensial_api` terenkripsi dengan **kunci khusus** `KREDENSIAL_KUNCI` (bukan APP_KEY, bisa dirotasi), tidak pernah dikirim ke layar (hanya 4 karakter terakhir), antarmuka `PenyimpanRahasia` agar vault bisa dipasang kemudian | Menyelaraskan FR-54-08 ("vault") dengan 11.2 (`kredensial_api`) untuk instalasi on-premise tanpa vault |
| K10 | TTE fase 1 | T1 (PIN + Ed25519) penuh. T2/PSrE: antarmuka adapter + simulator saja; penyedia dipilih saat kontrak PSrE | PRD 7.57.3: API PSrE belum diverifikasi |
| K11 | MFA ulang untuk aksi sensitif | Izin `butuh_mfa` meminta TOTP bila verifikasi MFA terakhir di sesi > 15 menit | Lapis 5 (9.2.2); angka di konfigurasi |
| K12 | Akses darurat (break-glass) | Ditunda ke fase 2 bersama RME (FR-26-12), karena butuh data pasien | Tidak ada data pasien di fase 1 |
| K13 | Penyetuju izin pengelola akses | Izin `pengaturan.peran.*` dan `pengaturan.akun.*` aksi input/perbaiki (sensitif sejak 1.2c, `MenuBawaan::IZIN_SENSITIF`): penyetujunya **superadmin selain pengaju**. Dipasang sebagai data awal di 1.2d | Ketentuan pemilik produk 11 Okt 2026; kuasa setara superadmin hanya diberikan dengan kontrol ganda (PRD 9.2.4) |

Bila pemilik produk mengubah keputusan, perubahan ditulis ke tabel ini lewat PR dan tiket terkait disesuaikan.

## Utang dan keputusan tertunda

Rincian alasan ada di ADR yang dirujuk; di sini hanya daftarnya agar tidak ada dua versi.

**Fitur yang belum dibangun**
- Reset sandi superadmin lewat persetujuan (kontrol ganda K4/K13) bila kebijakan RS mewajibkannya; bawaan sekarang langsung oleh superadmin lain (tiket 1.14, kontrak #97).
- Pemberitahuan akun terkunci ke pemilik (PRD 14.2) → tiket 1.7, issue #11.
- Superadmin dari `akses:superadmin-pertama` ditandai lewat audit `akses.superadmin_dibuat` (tanpa kolom baru) → tiket 1.2 memetakannya ke peran superadmin dan mengganti hitungan batas 2 di `SuperadminPertama`.
- Registri perangkat terdaftar: sampai ada, semua sesi memakai batas diam 15 menit (`PerangkatBelumTerdaftar`). Belum ada tiket (ADR 0002).
- Ganti pengguna cepat di komputer ruangan (kartu pegawai/PIN, PRD 14.2). Belum ada tiket (ADR 0002).
- Cek sandi kontekstual (sandi memuat nama pengguna, nama RS, atau nama produk) saat fitur ganti sandi dibuat (ADR 0001) → tiket 1.8d.

**Syarat sebelum go-live**
- ~~Uji beban penulisan audit~~ **selesai 1.12** (#87): kunci rantai berskala bila `catat()` di akhir transaksi; terkunci ±174/detik bila ditahan selama kerja (`docs/operasional/hasil-uji-beban.md`).
- `AUDIT_ANCHOR_DISK` ke penyimpanan terpisah yang tidak bisa ditimpa (mis. MinIO object lock) dan channel log `audit_anchor` ke SIEM (ADR 0003).
- Cron `php artisan schedule:run` di server (partisi audit, anchor per jam; ADR 0003).
- `AUTENTIKASI_MFA_JARINGAN_RS` diisi jaringan RS yang sebenarnya (PRD 10.3.2; ditegakkan di tiket 1.1c).
- Daftar sandi bocor diperbarui dari SecLists terbaru (ADR 0001, `SUMBER.md`).

**Repo dan teknis**
- Penyelarasan PRD dengan paket 1.13–1.16 (aturan sudah disetujui pemilik di tiket; PR penyelarasan dari sesi 1): 10.3.2 (reset sandi oleh admin, perangkat tepercaya di langkah masuk), 14.2 (sesi di perangkat tepercaya), 11.2 (`akses.perangkat_tepercaya`, `kebijakan_keamanan`, `peran.masa_percaya_hari`, `inti.profil_rs.halaman_masuk`), 5.3.2 (panel merek halaman masuk dapat diatur), 5.3.1 dan 17.2 #19–20 (palet bawaan #006937/#F3C300; bersama 1.15-be yang juga memperbarui `ProfilRs::WARNA_BAWAAN` dan `ProfilRsTest`).
- **Repo publik sejak 12 Okt 2026** (keputusan pemilik; aturan di CLAUDE.md "Repo publik"). Proteksi cabang dan rulesets
  kini bisa diaktifkan di GitHub Free untuk repo publik: **perlu pemilik** menyalakan aturan `main` (merge hanya lewat PR,
  check `backend`/`frontend`/`e2e` wajib hijau). Sampai itu dijaga lewat kebiasaan kerja.
- Private vulnerability reporting diaktifkan (SECURITY.md). LICENSE: hak cipta, bukan open source.
- **e2e `pengaturan.spec.ts` flake "izin label"** (#110): ditambal — `pilihAkun` memakai kondisi URL (bukan `isVisible()` tanpa tunggu)
  dan uji itu diberi batas 120 dtk (uji terpanjang berkas, 30 dtk bawaan sempit). Bila muncul lagi: pecah per akun atau
  pindahkan ke e2e-nightly.
- Utang 1.15-be (dari tinjauan #105, tidak memblokir): `PotongFoto::potong()` tidak punya test untuk tipe `image/webp`
  (satu dari tiga tipe didukung; `imagewebp()` return value tidak dicek). Tambahkan kasus unggah WebP ke
  `HalamanMasukTest` saat menyentuh berkas itu lagi.
- **[Perlu pemilik] Riwayat git** masih memuat berkas yang dihapus di PR kebersihan repo publik (logo/foto/motif RS USU,
  `docs/html/`, catatan lapangan bridging) dan tangkapan layar di branch `lampiran/*` yang sudah dihapus (commit lama
  tetap bisa diakses lewat SHA atau deskripsi PR lama). Membersihkannya butuh penulisan ulang riwayat + force-push
  (merusak clone dan PR terbuka) atau permintaan ke GitHub Support; keputusan pemilik.
- Dependensi frontend tertinggal satu-dua versi mayor (per 11 Okt 2026): `vite` 6 → 8, `@vitejs/plugin-vue` 5 → 6, `typescript` 5.8 → 7, `vue-tsc` 2 → 3, `vue-router` 4 → 5, `@vue/tsconfig` 0.7 → 0.9. Naikkan dalam tiket sendiri, bukan sambil lalu.
- Dari library PRD 10.4 sudah dipakai: axios, TanStack Query (1.2b), VeeValidate + Zod dan Reka UI (1.2c-fe). VeeValidate 4.15 membaca zod 4 lewat adapter sementara `design-system/form/skemaZod.ts`: hapus saat vee-validate 5 stabil (Standard Schema). Zod klasik ~47 KB gzip di chunk formulir; pertimbangkan `zod/mini` di 1.11. Tailwind belum dipasang (komponen memakai CSS bertoken `dasar.css`).
- Hak akses 1.2a yang belum lengkap: `peran_menu.tingkat_akses/urutan_khusus/label_khusus` (PRD 11.2, layar 9.3) belum dibuat, aksi diambil dari `peran_izin`; cakupan `pasien_dirawat` belum dievaluasi (butuh relasi perawatan); `inti.modul_aktif.unit_organisasi_id` belum berkunci tamu dan modul per unit belum dipakai (menunggu `inti.unit_organisasi`); persetujuan pemilik data untuk izin sensitif dan perubahan peran superadmin di 1.2d (sampai itu ditolak dengan 409 `butuh_persetujuan`/`peran_bawaan_terkunci`, bukan disimpan diam-diam).
- Hak akses 1.2a yang belum lengkap: `peran_menu.tingkat_akses/urutan_khusus/label_khusus` (PRD 11.2, layar 9.3) belum dibuat, aksi diambil dari `peran_izin`; cakupan `pasien_dirawat` belum dievaluasi (butuh relasi perawatan); `inti.modul_aktif.unit_organisasi_id` belum berkunci tamu dan modul per unit belum dipakai (menunggu `inti.unit_organisasi`, tiket 1.8a); larangan memberi akses ke diri sendiri dikerjakan di 1.2c-be; persetujuan pemilik data untuk izin sensitif dan perubahan peran superadmin di 1.2d (sampai itu ditolak dengan 409 `butuh_persetujuan`/`peran_bawaan_terkunci`, bukan disimpan diam-diam).
- Sesudah 1.0g: worktree yang dibuat sebelum #29 merge (mis. 1.2c-fe) perlu rebase ke `main` dan mengganti `GalatApi`/`galat` → `ApiError`/`error`, `lencana` → `jumlah_tugas`, `tantangan_id` → `challenge_id`, rute `/data-induk` → `/master-data`, ikon `data_induk` → `master_data`; `.env` lokal mengganti variabel yang berganti nama (daftar di PR #29). Berkas anchor audit lokal lama ada di `storage/app/audit/jangkar`: pindahkan isinya ke `storage/app/audit/anchor` bila ingin tetap diverifikasi.
- Peran yang `wajib_mfa`-nya dinyalakan tidak memutus sesi pemegang tanpa MFA (ADR 0002) → MFA ulang di tiket 1.5 (K11). Penugasan peran berizin sensitif dan pengakhiran penugasan superadmin ditolak 409 sampai tiket 1.2d (sejak 1.2d-be: 202 persetujuan).
- Frontend 1.2c-fe: server tiruan kontrak `frontend/src/modules/akses/tiruan/serverTiruan.ts` (unit test dan `VITE_API_TIRUAN=true` tanpa backend) mengikuti kontrak v0.2.2; perbarui bersama kontrak, jangan dijadikan acuan aturan. Pesan hasil di dekat konten (`role=status`) dipertahankan karena hasilnya terlihat di tempat; `NotifikasiToast` (1.11) untuk hasil yang tidak terlihat di tempat. Konfirmasi meninggalkan editor peran dengan suntingan belum disimpan masih `window.confirm`. Daftar akun dan peran masih tabel/daftar sendiri: pindahkan ke `DataTable` (1.11) saat layar itu disentuh lagi. Superadmin yang berpindah akun/peran dengan cepat bisa mendekati rate limit API 120/menit (e2e memberi jeda otomatis); bila terjadi di pemakaian nyata, pertimbangkan `throttle:api-layar-kerja` untuk GET daftar dan rincian `/akun`, `/peran` (keputusan backend). e2e `pengaturan` adalah proyek Playwright terakhir karena mengubah akses `contoh.admisi` dan mereset MFA `contoh.mfa`: jalankan ulang seeder sebelum run lokal berikutnya.
- Isi Kotak kerja Beranda (`modules/beranda/components/KotakKerja.vue`, slot bawaan) diisi tiket 1.7.
- Izin pengelola akses `pengaturan.{peran,akun}.{input,perbaiki}` ditandai sensitif oleh `MenuBawaan::IZIN_SENSITIF` (1.2c, dari tinjauan #32; PRD 9.2.4): tidak bisa dibagikan lewat peran biasa atau per akun sampai 1.2d, dan pemegangnya wajib MFA. Tiket 1.2d perlu menetapkan peran penyetujunya (K3); admin delegasi per unit (FR-09-07) memakai izin terpisah, bukan izin ini.
- Kecil dari tinjauan #32: `MenuBawaan::pindahKode` melewati diam-diam bila kode lama dan baru sama-sama ada (node lama tetap aktif; hanya mungkin di DB yang disunting manual). `akun_terdampak`/`jumlah_akun` hanya menghitung penugasan yang berlaku sekarang, bukan yang akan berlaku. `berlaku_dari` penugasan boleh diisi mundur (dasar SK lama) — tinjau ulang di 1.2d. Reset MFA masih menyisakan jendela beberapa milidetik: login yang commit tepat sebelum reset bisa memulai sesi sedikit sesudah waktu cabut. Izin `pengaturan.{peran,akun}.lihat` (daftar akun, Lihat sebagai) belum sensitif — timbang di 1.2d.
- PRD 11.2 belum memuat kolom yang ditambah 1.2c/1.2e: `akses.pengguna.sesi_dicabut_pada`, `login_terakhir_dari_jaringan_rs`, `masuk_sebelumnya_pada`, `masuk_sebelumnya_dari_jaringan_rs`, dan kolom pembatalan `akses.peran_izin`/`peran_menu` (`status`, `alasan_batal`, `dibatalkan_oleh`, `dibatalkan_pada`). Diajukan di PR PRD v0.10.13 "[Perlu pemilik]" (bersama kolom 1.2d/1.8a/1.8d dan 10.3.2/9.1 dari 1.8d).
- Sesudah 1.8a-be (#37 kontrak, PR backend):
  - Berkas paket identitas disimpan lewat disk `IDENTITAS_DISK` (bawaan lokal privat). Menyambung MinIO (adaptor S3 + layanan compose) belum dikerjakan agar tidak menambah dependensi di luar tiket → runbook 1.12. Pemindaian antivirus unggahan (PRD 14.2) belum ada.
  - **Butir terbuka (pemilik produk):** tafsiran kontras FR-54-08 langkah 2 — brand vs putih ≥ 4,5:1; accent vs tinta `#1D1D1B` ≥ 4,5:1 (`App\Core\ProfilRs\KontrasWarna`).
  - `kop` (PRD 3.4/11.2) belum ada di profil RS. Kepala unit dan ketua KSM menyusul 1.8d (respons `null`).
  - Kontrak: setelah backend 1.8a merge, kembalikan `Penugasan.cakupan_label` ke `required`.
  - `App\Core\ProfilRs` memakai `App\Modules\Akses\Domain\Cakupan` (lapis 3 cakupan `rs`); pindahkan `Cakupan` ke Core bila ketergantungan Core → modul mengganggu.
  - Lapis 3 cakupan `unit` kini dievaluasi dengan pohon unit (kueri rekursif tanpa cache); belum terukur di uji beban lokal 1.12 (semua akun uji bercakupan RS) → ukur di staging.
  - **[Perlu pemilik]** `GET /profil-rs` dan berkasnya dari tamu (halaman masuk) kena rate limit 120/menit per IP. Di RS dengan satu NAT kuota ini bisa habis saat banyak orang membuka halaman masuk bersamaan. Melonggarkan rate limit menunggu keputusan pemilik (catatan sesi 2, tinjauan #38).
  - Penugasan ke unit yang sedang dinonaktifkan bersamaan bisa lolos (cek unit aktif di Akses tanpa kunci pohon MasterData); rendah, tinjau saat 1.2d menyentuh penugasan.
  - Untuk 1.2d: saat memberi penugasan, cakupan pemberi belum dibandingkan dengan cakupan yang diberikan — **ditutup sementara 1.12 (temuan ASVS 4.1.3):** rute `pengaturan.akun.*`/`pengaturan.peran.*` memakai `can.rs:` (izin wajib bercakupan seluruh RS), sehingga pemegang bercakupan unit ditolak; perbandingan cakupan pemberi vs yang diberikan tetap wajib saat admin delegasi per unit dibuat.
  - `AccessEvaluator` kini butuh binding `DataUnit` dari modul MasterData (modul 54, selalu aktif).
- 1.2d (kontrak #41): penanda jumlah "perlu saya putuskan" untuk penyetuju (lencana Beranda/menu) ditunda ke 1.7 (notifikasi dan kotak kerja); sebelum itu penyetuju membuka sub-menu `pengaturan.persetujuan` sendiri. `POST /permintaan-akses` dengan kode acak membedakan ada/tidaknya kode menu (422 `sasaran_tidak_dikenal`); kode menu bukan rahasia, diterima. Layar "Akses ditolak" hanya untuk halaman yang benar-benar ada (catatan sesi 2).
- **[Perlu pemilik] Usulan:** sub-menu `pengaturan.persetujuan` diberikan otomatis oleh server kepada semua pemegang peran penyetuju (tanpa admin memberikannya lewat peran), agar pemilik data non-admin langsung melihat antrean. Ditunda karena menambah sumber izin di luar rumus PRD 9.2.5 ("diberikan eksplisit"); sampai diputuskan, admin memberikan sub-menu itu ke peran penyetuju (tinjauan #41).
- 1.2d-be: pemberian berisiko menjadi persetujuan menunggu (`AlurPersetujuan`, diterapkan di transaksi keputusan terakhir oleh `PenerapPersetujuan`); kedaluwarsa lewat `akses:kedaluwarsakan-persetujuan` (harian 01.00) dan juga ditolak saat diputuskan; data awal K13 di `MenuBawaan::pasangPenyetujuBawaan()`. Sisa:
  - Dari utang 1.2c yang belum disentuh 1.2d: `berlaku_dari` boleh mundur dan izin `pengaturan.{peran,akun}.lihat` belum sensitif (timbang di 1.5); penugasan ke unit yang dinonaktifkan bersamaan; cakupan pemberi vs cakupan yang diberikan (wajib saat admin delegasi per unit).
  - Dari tinjauan #41: permintaan berstatus `ditindaklanjuti` begitu pemberiannya diajukan, walau persetujuannya nanti ditolak, dan permintaan tidak punya kedaluwarsa (usul: tautan permintaan → persetujuan, dibahas di 1.2d-fe/1.7). Kartu rinci belum memuat izin sensitif terkait untuk penugasan peran dan daftar penyetuju dalam grup (cukup `grup.jumlah/disetujui` untuk 1.2d-fe).
  - Penggantian penyetuju yang masih menunggu belum tampil di `NodeMenu` (catatan sesi 2; layar memakai tab "Yang saya ajukan").
  - Superadmin ikut mendapat `pengaturan.persetujuan.input/perbaiki` dari `AKSI_DASAR` walau layar itu hanya memakai `lihat`.
  - Dari tinjauan #43 (non-penghalang): `catat()` audit dipanggil di tengah transaksi pada `ajukan`/`tindaklanjuti`/penerapan (bukan di akhir, ADR 0003) sehingga kunci rantai dipegang lebih lama; pemegang peran penyetuju bercakupan unit tetap bisa memutuskan pemberian bercakupan RS (cakupan penyetuju belum dibandingkan, bersama admin delegasi); pemeriksaan `penerima_sendiri` tanpa kunci baris peran (balapan sempit, tetap butuh kolusi dua orang); `lihat_wajib` tidak diperiksa ulang saat izin peran diterapkan.
- Utang 1.8b (dari tinjauan #44): arti `bangsal.kelas_layanan` di PRD 11.2 (kelas bawaan bangsal? kelas layanan VIP?) perlu
  dijelaskan pemilik; TT terisi saat akan ditutup ditolak 409, padahal FR-08-17 meminta "akan ditutup setelah kosong" →
  bersama penempatan pasien (fase 2); impor lokasi lewat antrean `dokumen` untuk berkas > 5.000 baris butuh endpoint
  status impor (di luar daftar API tiket) → usulan bila dibutuhkan RS besar.
  Dari tinjauan sesi 2 #44 (dikerjakan di #46 kecuali): penanda unit warisan di `Lokasi` (unit disalin saat dibuat, tidak
  dinamis); PATCH TT tidak mengembalikan `peringatan` (kelas/ekstra tidak mengubah jumlah TT kamar).
  Dari tinjauan subagen #46 (sisa): nama berawalan `=`/`+`/`-`/`@` diterima impor/API → wajib dinetralkan saat ekspor CSV
  pertama dibuat (injeksi formula); nomor baris kesalahan impor = nomor rekaman CSV (berbeda bila sel memuat baris baru).
- PRD 1.8d (10.3.2 `403 wajib_ganti_sandi`, 9.1 bagian `sdm.pegawai.identitas`): selesai di PRD v0.10.13 (#52).
- PRD v0.10.14 mendokumentasikan 1.6, 1.7, 1.8c, 1.8e. Selisih kode dengan aturan PRD yang tertulis (Idempotency-Key,
  polling vs WebSocket, "100% lewat usulan", TTE pengesahan, `*_versi`, query lintas modul, K1/K2/K7/K10 di 17.2, dll.)
  diusulkan di PR [Perlu pemilik] #72 (PRD v0.10.15); 1.8f (`inti.penyiapan_konfirmasi`, `profil_rs.siap_melayani_sejak`)
  didokumentasikan di PRD berikutnya.
- **Utang 1.9 (dibangun terhadap simulator, K8; dicocokkan dengan dokumen resmi sebelum pilot fase 2, DoD D.7):**
  - SATUSEHAT (butir be 4, catatan lapangan §15–§19): sistem identifier Organization/Location/NIK (`config/integrasi.php`
    `satusehat.*`, bisa diganti lewat env), letak kode 20002 di OperationOutcome (kini dikenali dari `issue.code =
    duplicate` atau kode `20002` berdiri sendiri), bentuk respons KFA (`items.data`/`items`/`data`), dan semantik REST yang
    diasumsikan (catatan §19 hanya memuat nama method): `POST {jenis}`, `PUT {jenis}/{id}`, `GET {jenis}?identifier=
    system|value`, isian `managingOrganization`/`mode`/`description` — *perlu verifikasi*. Keunikan identifier di server
    nyata belum diketahui atomik; kiriman satu data diserialkan dengan sewa agregat. Kode
    physicalType/organization-type dari terminologi HL7. Location: situs → TT (FR-49-06), identifier = id lokal,
    managingOrganization = Organization RS (bukan unit); lokasi yang ditutup sebelum terdaftar tidak didaftarkan.
    Organization: unit akar `partOf` Organization RS. Practitioner: pegawai aktif berprofesi nakes, NIK kosong =
    tertahan (pemilik unit pegawai). Induk yang ditutup/nonaktif sebelum terdaftar membuat anaknya tertahan
    `induk_ditutup`. Pemicu = sinkron tiap jam (`integrasi:sinkron-satusehat`): data yang sidiknya (ringkasan isi yang
    dikirim, `id_eksternal.sidik_sumber`) berbeda; status TT diturunkan dari masa berlaku; kiriman gagal diulang sesudah
    24 jam atau sesudah kredensial diubah (penolakan permanen, mis. nakes belum terdaftar di SATUSEHAT, membentuk satu
    baris gagal per hari per data — bukan regresi). Kiriman lokasi/unit yang ditutup sebelum pernah terdaftar selesai
    tanpa memanggil SATUSEHAT (catatan: hasil ini tetap dihitung `SirkuitIntegrasi::berhasil()`, bisa menutup sirkuit
    setengah terbuka tanpa SATUSEHAT dihubungi; perlu penanda "tanpa panggilan" → `lepasJatah`). Sidik belum memuat `organization_id` kredensial dan sistem identifier config: bila
    keduanya diganti, resource yang sudah terdaftar tidak dikirim ulang otomatis. Belum kejadian per perubahan. Id produksi juga ditulis ke kolom tampilan
    (`inti.lokasi`/`inti.tempat_tidur.satusehat_lokasi_id`, `sdm.pegawai.ihs_practitioner_id`); id sandbox hanya di
    `integrasi.id_eksternal`; penulisan kolom tampilan ini (hasil integrasi, bukan suntingan pengguna) tidak dicatat di
    `audit.log_aktivitas`, jejaknya di `kotak_keluar.id_luar` dan `log_api`. IHS pasien (`LayananSatusehat::ihsPasien`,
    mengembalikan lingkungan agar IHS sandbox tidak disimpan sebagai `pasien.ihs_id`) dan KFA (`cariKfa`) belum punya
    pemakai sampai pendaftaran/farmasi fase 2; cache IHS pasien = `pasien.ihs_id` (fase 2). Simulator (Infrastructure)
    membaca kredensial lewat `KelolaKredensial` (Application): arah dependensi terbalik, khusus simulator.
  - Aplicares (butir be 5 dibangun terhadap simulator, keputusan pemilik K8; belum dicocokkan dengan dokumen resmi):
    alamat per lingkungan (`INTEGRASI_APLICARES_*` kosong), nama isian `bed/update`/`bed/create` selain
    `kodekelas`/`koderuang` (`namaruang`, `kapasitas`, `tersedia*`), kode sukses (`1`/`200`) dan kode "ruang belum
    terdaftar" (kini setiap penolakan update dicoba `bed/create` sekali) — *perlu verifikasi*, DoD D.7. Satu ruang
    Aplicares = bangsal × kode kelas Aplicares (kelas rawat berkode sama dijumlah); TT ekstra tidak dihitung; ruang yang
    hilang (kelas diubah, kamar pindah, kode diubah) dikirim kapasitas 0 dari `integrasi.ruang_aplicares`. `tersedia*` =
    kapasitas sampai keterisian TT ada (penempatan pasien, fase 2); karena itu kiriman **produksi ditahan**
    (`isian_belum_diverifikasi`) sampai `INTEGRASI_APLICARES_TERVERIFIKASI=true` — syarat masuk pilot fase 2. Urutan
    kiriman per agregat (catatan #76): sewa per bangsal (`integrasi.sewa_agregat`) + baca keadaan terkini saat dikirim. Belum memicu
    kiriman langsung (menunggu sinkron harian 00.15): perubahan aturan jenis kelamin TT, nama/kode bangsal. Kode PPK yang
    diubah tidak menurunkan ruang di PPK lama. Batas waktu job queue `integrasi` (60 detik) lebih pendek dari satu kiriman
    Aplicares terlama (putaran × ruang × 2 panggilan × 20 detik): bila BPJS lambat, pekerja bisa dihentikan di tengah dan
    kiriman diulang sesudah sewa 10 menit; batasi total waktu per kiriman atau naikkan batas job (tinjauan #82).
  - Kode BPJS untuk "data tidak ditemukan" di simulator (`201`) asumsi; sukses VClaim = `200` (catatan §1.4).
  - Butir be 8 (catatan lapangan "Selisih dengan PRD" no. 1 merujuk K9): catatan lapangan kini milik pemilik di luar repo
    (repo publik), jadi tidak bisa diubah dari repo; **perlu pemilik** memperbarui catatannya bila ingin. Di repo, K9
    tercatat di backlog dan diterapkan di kode (`integrasi.kredensial_api`, KREDENSIAL_KUNCI).
  - PRD 11.2 `integrasi.*` belum memuat kolom yang dibangun (`kredensial_api.isian/rahasia/rahasia_ringkas/id_kunci`,
    `status_sistem`, `log_api.lingkungan`, `kotak_keluar.lingkungan/kunci_idempotensi/penyebab_tertahan_*`) dan
    `organization_id` per lingkungan (PRD menyebut `profil_rs.satusehat_org_id`), `id_eksternal.lingkungan/sidik_sumber/disinkron_pada`,
    tabel `ruang_aplicares` dan `sewa_agregat`; menyusul PRD penyelarasan berikutnya.
- **Utang 1.5** (dari tinjauan #64 dan pembangunan 1.5-be; tidak memblokir):
  - Belum ada izin dengan `butuh_mfa`/`butuh_kewenangan_klinis` = true dan belum ada layar/API untuk menandainya; aksi
    klinis pertama (fase 2) menandai izinnya di `MenuBawaan`, dan keputusan izin mana yang butuh MFA ulang perlu RS/pemilik.
  - Kewenangan praktik hanya membaca jenis `sip`/`spk` (PRD 9.2.2). Perlu verifikasi RS apakah SIK (perawat/bidan) ikut.
  - Perpanjangan izin label belum ada: `409 label_masih_berlaku` memaksa pencabutan dulu (jeda tanpa akses).
  - Controller di luar modul Akses masih `abort_unless(Gate::allows(..))`/`Gate::denies` → 403 `akses_ditolak` tanpa kode
    lapis 5 (mis. ProfilRs, Integrasi, MasterData, Sdm); ganti ke `Gate::authorize()` bila izinnya diberi syarat lapis 5,
    agar klien bisa memunculkan dialog MFA ulang (tinjauan #83). Label dan penyetuju izin sudah memakai `authorize`.
  - RKK hanya diperiksa bila Gate diberi `TindakanKlinis`; aksi klinis fase 2 wajib mengirimnya (tinjauan #83).
  - Komentar blok `/persetujuan-akses` di kontrak belum menyebut `izin_label` (penerima = akun sasaran) dan
    `penyetuju_label` (penerima = pemegang peran yang ditunjuk); perilakunya sudah diuji (`penerima_sendiri`).
  - Rincian akun (`AkunRinci`) belum memuat izin label / pengajuan label yang menunggu; layar memakai GET /akun/{id}/label.
  - (1.5-fe) Dialog MFA ulang belum diuji e2e terhadap backend: belum ada izin `butuh_mfa` dan batas 15 menit tidak bisa
    dipercepat per uji; diuji unit (pencegat klien + dialog). Uji e2e menyusul saat aksi `butuh_mfa` pertama (fase 2).
  - (1.5-fe) `PenandaTerbatas` belum dipakai layar mana pun (endpoint data pasien fase 2); baru tampil di katalog komponen.
  - ~~PRD 11.2 `label_sensitivitas`/`pengguna_label`~~ **selesai di PRD v0.10.19** (penyelarasan 1.5 dan #86).
- Utang arsitektur (dari penyelarasan PRD, #72 B-13/B-14): modul membaca tabel modul lain secara langsung, padahal PRD
  10.3 meminta lewat antarmuka modul: `MasterData/Application/MesinUsulan.php` → `ttd.tanda_tangan`,
  `Ttd/Application/AtasanLangsung.php` dan `Ttd/Http/Resources/TandaTanganResource.php` → `sdm.pegawai`/`inti.unit_organisasi`,
  `Notifikasi/Application/PendengarKejadian.php` → `akses.peran_izin`/`akses.izin`, `Notifikasi/Application/Pemberitahu.php`
  → `akses.pengguna`, `Core/Instalasi/Penyiapan.php` → `sdm.*`/`inti.*`. Ditegakkan atau dilonggarkan sesuai keputusan
  pemilik di #72. Folder kosong `backend/app/Shared/{Reporting,Signing,Terminology,Notification,FormBuilder,Workflow}`
  (hanya `.gitkeep`) dihapus sesudah #72 diputuskan.
- Utang 1.8d: layar persetujuan K4 belum memperingatkan bila superadmin diberikan ke akun pegawai `akan_keluar`; dokumen
  izin praktik yang sudah tertulis ke disk bisa tertinggal bila transaksi gagal sesudahnya (jalur privat, tanpa rujukan);
  modul SDM membaca `akses.pengguna`/`inti.unit_organisasi` langsung untuk daftar pegawai (belum lewat antarmuka modul);
  APP_KEY dirotasi = sidik NIK dihitung ulang (perintah belum ada). Usulan sesi 2: path terpisah `GET /pegawai/{id}/nik`
  (di luar API tiket; kini `?tampilkan_nik=true`).
- Utang tes 1.8d (kode sudah ditinjau benar, tesnya belum): 429 sesudah `sandi_lama_salah` berulang, IDOR dokumen izin
  praktik antarpegawai, `berkas_terlalu_besar`, NIK tidak muncul di log aplikasi. Runbook: `upload_max_filesize` dan
  `post_max_size` PHP ≥ 12 MB agar batas paket referensi 10 MB, dokumen 5 MB, dan impor 2 MB tercapai sebelum batas PHP. Indeks
  `sdm.pegawai (dibuat_pada, id)` bila daftar pegawai membesar.
- Utang 1.8e: **isi paket resmi belum dikemas** (wilayah Kemendagri, UCUM, subset LOINC, kode variabel RME) — menunggu
  berkas dari pemilik/RS (`backend/database/referensi/SUMBER.md`); sampai itu langkah 4 wizard 1.8f tidak bisa selesai.
  Kecil: `paket_referensi.dimuat_oleh` tanpa FK dan `sistem` tanpa CHECK; nomor baris kesalahan = nomor rekaman CSV
  (bergeser bila sel memuat ganti baris, sama dengan impor 1.8b).
- **[Perlu pemilik] Usulan (1.8d):** reset sandi oleh admin (sandi awal baru sekali tampil, wajib ganti) dan masa berlaku
  sandi awal; di luar API tiket 1.8d. Sampai itu sandi awal yang hilang tidak bisa dipulihkan.
- **[Perlu pemilik] Usulan (1.8b):** daftar periksa baku atribut kamar (FR-08-19) sebagai data induk dengan endpoint
  sendiri (mis. `/atribut-kamar`: kode, label, jenis nilai, berlaku untuk KRIS/kelas lama). Endpoint ini tidak tercantum
  di API tiket 1.8b, jadi menunggu pemilik; sampai itu `Kamar.atribut_standar` berupa pasangan kunci-nilai bebas
  (kunci snake_case, paling banyak 50).
- Sesudah backend 1.2d merge: field baru kontrak v0.4.0 di skema lama (`izin_menunggu`, `menunggu_persetujuan`, `penyetuju_peran`, 202) dijadikan `required`/satu-satunya, dan teks "sebelum 1.2d" serta 409 `butuh_persetujuan`/`peran_bawaan_terkunci` (penugasan) dihapus dari kontrak.
- Sesudah 1.2d-fe:
  - `HalamanModul` kini menampilkan "Akses ditolak" + "Minta akses" untuk halaman yang dibangun tetapi tidak diberikan (sebelumnya "Halaman tidak ditemukan"); jalur di luar menu tetap tidak ditemukan. Sasaran permintaan = kode sub-menu dari izin gerbang (`<menu>.<sub>.lihat` → `<menu>.<sub>`).
  - Izin sensitif yang menunggu persetujuan tampil tercentang dan terkunci di matriks (tingkat dan *lihat* node itu juga dikunci) dan selalu ikut dikirim di PUT izin; backend menarik izin menunggu yang tidak dikirim. Menarik pengajuan lewat Persetujuan akses atau rincian akun, bukan dari matriks.
  - Riwayat "yang sudah saya putuskan" belum ada (tab perlu_saya_putuskan hanya berisi yang menunggu); lencana jumlah ke 1.7.
  - Server tiruan akses mengikuti kontrak v0.4.0 (persetujuan, permintaan, penyetuju izin; akun `contoh.superadmin.dua`, `contoh.kabid` dan peran Contoh Kabid Keuangan sebagai pemilik data kasir hanya di tiruan). e2e memakai `E2E_SUPERADMIN_DUA_MFA_RAHASIA`.
- Sesudah 1.6-fe:
  - TtdModal presentasional: tindakan lewat handler `tandatangani(pin)` dari pemanggil (`useTandaTangan`); modal menafsirkan `pin_wajib`, `pin_salah` (`sisa_percobaan`), `ttd_terkunci` (Retry-After), `ttd_belum_aktif`, dan `objek_tidak_bisa_ditandatangani` per butir. Mode massal diuji unit tetapi belum punya pemanggil di fase 1 (belum ada daftar "yang saya putuskan"); mode "minta tanda tangan" hanya komponen (tanpa endpoint, kontrak v0.8.0).
  - Tanda tangan keputusan tidak dibuka otomatis sesudah setujui/tolak (persetujuan beruntun tidak terpotong); tombol "Tandatangani keputusan" tampil di bagian tanda tangan bila akun ini pemutusnya dan belum menandatangani isi saat ini.
  - PIN satu kolom (`inputmode=numeric`, 6 angka) alih-alih enam kotak prototipe, demi pembaca layar dan tempel; aturan PIN tetap diperiksa server.
  - Tombol "Ajukan reset TTE" di rincian pegawai hanya bila `akun` terkirim (`pengaturan.akun.lihat`) dan `sdm.kredensial.perbaiki`; status TTE akun sasaran belum diketahui layar (usulan `Pegawai.akun.ttd`, utang 1.6), jadi 409 `ttd_belum_aktif` tampil sebagai pesan.
  - e2e: aktivasi `contoh.ttd.baru`, penguncian lewat ganti PIN `contoh.ttd` (bukan contoh.superadmin.dua), tanda tangan keputusan di uji kontrol ganda (PIN, lalu tanpa PIN dalam sesi). Pengulangan lokal butuh `db:seed` ulang dan `.env` dengan `TTD_KUNCI`, `AKUN_SINTETIS_TTD_PIN`, `TTD_KUNCI_MENIT`.
  - Utang dari tinjauan #62 (tidak memblokir): konfirmasi peringatan di mode massal dicatat per indeks butir, bukan id objek; kotak centang konfirmasi belum `aria-invalid`; uji unit `pin_wajib` menghapus sesi tanda tangan basi; tombol tanda tangan masih tampil saat `tanda_tangan_tidak_sah`; panduan instalasi perlu catatan pengelola sandi peramban di komputer bersama (N4).
- Sesudah 1.7-fe:
  - Polling memakai `useKueriBerPolling`: muat awal dan muat ulang karena tindakan pengguna tanpa `X-Permintaan-Latar` (memperpanjang sesi); detak 30 detik dan saat tab kembali terlihat memakai header itu dan If-None-Match. Polling hanya berjalan saat tab terlihat; kotak kerja hanya di Beranda, lonceng di semua layar AppShell.
  - Tautan tugas dari backend berbentuk jalur (`/pengaturan/persetujuan/{id}`, `/pengaturan/akun?permintaan={id}`, `/sdm/pegawai/{id}?tab=kredensial`), sedangkan layar memakai query. Router frontend mengalihkannya (permintaan → Persetujuan akses tab Permintaan, belum memilih permintaannya). Usul untuk PR kontrak/backend berikutnya: samakan `tautan_layar` dengan rute layar, atau kontrak menetapkan pola tautan.
  - Kotak kerja menampilkan 20 tugas teratas per tab dengan keterangan "Menampilkan N dari M"; belum ada halaman kotak kerja penuh.
  - Halaman Jenis tugas: waktu diisi dalam jam (desimal boleh), dikirim dalam menit; superadmin tidak memegang `master_data.jenis_tugas` (utang 1.7-be), jadi e2e memakai contoh.data.induk.
  - Belum ada seeder tugas/notifikasi sintetis; e2e membuat tugas dari kejadian nyata (permintaan akses contoh.dokter → tugas superadmin).
- Sesudah 1.8d-fe:
  - NIK utuh hanya lewat tombol "Tampilkan NIK" (bila `nik_dapat_dilihat` dan NIK ada): diminta tersendiri, disimpan di state komponen saja (tidak di cache kueri), disembunyikan lagi sesudah 30 detik atau saat pegawai berganti. Uji jalur ini hanya unit test (contoh.sdm tidak memegang bagian identitas).
  - Daftar pegawai tampil dalam urutan server (terbaru dicatat); klien tidak mengurutkan ulang antarhalaman kursor.
  - Ganti sandi: klien hanya memeriksa isian kosong dan pengulangan; panjang minimal dan aturan kontekstual dari pesan `rincian` server (angka tidak dikodekan ganda). 403 `wajib_ganti_sandi` dari endpoint mana pun mengarahkan ke `/ganti-sandi`.
  - Sandi awal hanya hidup di state dialog Buat akun (tersembunyi bawaan, tombol salin); hilang saat dialog ditutup.
  - Kepala unit/ketua KSM ditetapkan di dialog Ubah unit/KSM, hanya bila akun juga memegang `sdm.pegawai.lihat` (tanpa izin silang); yang sudah/akan keluar ditandai di rincian unit dan daftar KSM.
  - Belum ada: sub-menu SDM → Izin & kredensial (pemantauan masa berlaku lintas pegawai, prototipe `sdm.html`) masih Segera hadir; reset sandi oleh admin dan tautan aktivasi email menunggu usulan/1.7.
  - Utang dari tinjauan #58 (tidak memblokir): 403/404 pada muat ulang rincian pegawai sesudah data dimuat tetap menampilkan data lama tanpa tanda; penanda "hasil terpotong" di PemilihPegawai memakai angka 20, bukan kursor berikutnya; uji unit penangan 403 `wajib_ganti_sandi` asli (main.ts) dan penjaga router, serta PATCH selisih + 412 di DialogPegawai belum ada; teks kontrak GET /pegawai ("klien mengurutkan tampilan") diselaraskan sesi 1 di PR kontrak berikutnya.
- Sesudah 1.9-fe:
  - Pengaturan → Konfigurasi integrasi: kartu per sistem dari `GET /integrasi/sistem` (tanpa angka tiruan), kredensial
    tulis-saja per lingkungan (isian rahasia `type=password`, kosong = tidak diubah, hanya isian yang berubah dikirim,
    nilai tidak disimpan di luar dialog), aktifkan/nonaktifkan (dengan konfirmasi), uji koneksi (429 `Retry-After`).
  - Integrasi → SATUSEHAT / Bridging BPJS: kartu pemantau (`kredensial` null bila tanpa `pengaturan.integrasi.lihat`)
    + kotak keluar per tujuan; tombol kirim ulang hanya dari `dapat_kirim_ulang`; kiriman tanpa lingkungan diikat ke
    lingkungan aktif (disebut di konfirmasi). Integrasi → Log integrasi: isi disamarkan dalam lipatan, halaman 20.
  - Kotak keluar BPJS tanpa filter tujuan meminta semua tujuan yang diizinkan lalu menyaring VClaim/Aplicares di klien
    (kontrak belum menerima beberapa `tujuan`); halaman bisa lebih pendek dari `batas` bila akun juga memegang SATUSEHAT.
  - e2e `integrasi` (contoh.integrasi, simulator): kredensial SATUSEHAT sandbox → aktifkan → uji koneksi → log; mode
    simulator mati/gangguan (circuit breaker di layar) dan kotak keluar berisi kiriman belum diuji e2e (kiriman nyata
    bisa diuji sesudah penangan SATUSEHAT/Aplicares 1.9-be; belum ada skenario e2e).
- **Utang 1.12-fe** (tidak memblokir): job e2e CI naik dari ±7,5 menjadi ±15 menit karena proyek `fase1` menunggu satu
  jendela rate limit (61 detik) untuk akun yang jatahnya dipakai spec sebelumnya (catatan tinjauan #88); pelanggaran axe
  `moderate`/`minor` belum dicatat atau dilaporkan; tab dan dialog di dalam layar hanya diuji axe di spec masing-masing
  (mis. tab Label di `pengaturan.spec`), bukan di `fase1.spec`.
- **Utang 1.12 (dari penyusunan runbook docs/operasional/, kode):**
  - `BerkasAnchor` tidak memeriksa hasil tulis: disk `local` `'throw' => false`, sehingga `audit:ekspor-anchor` sukses walau
    mount anchor terlepas (berkas jatuh ke disk server aplikasi). `append` menulis ulang berkas bulan berjalan utuh tiap
    jam, jadi WORM/append-only tidak bisa dipasang pada berkas itu.
  - ~~`audit:verifikasi-rantai` kena batas 5 detik~~ **selesai 1.12**: per jendela `AUDIT_VERIFIKASI_JENDELA` (702 ribu baris
    utuh dalam 18 detik). Verifikasi berkala belum dijadwalkan di kode (runbook mengusulkan timer harian).
  - Setiap permintaan PHP membuka koneksi TCP baru ke PostgreSQL dan Valkey (tanpa persisten/PgBouncer); uji beban lokal
    jenuh ±125 permintaan/detik (hambatan belum diketahui; basis data di VM 2 CPU) dan error `Can't assign requested address` di atasnya. Produksi wajib
    PgBouncer (PRD 14.1.1 butir 8); timbang `REDIS_PERSISTENT`/phpredis. `DB_CONNECT_TIMEOUT` (5 detik; bawaan pdo_pgsql 30)
    sudah ditambah 1.12. Koneksi Valkey tanpa `timeout`/`read_timeout` (dugaan penyebab permintaan 3m39s di uji lokal);
    tambahkan dengan hati-hati karena queue Horizon memakai blocking pop.
  - ASVS T-1 butir 2: penugasan peran pengelola akses bercakupan unit belum ditolak `422 cakupan_harus_rs` (butuh kode
    error di kontrak); celahnya sudah tertutup oleh `can.rs` (#86).
  - Temuan ASVS Sedang/Rendah (S-1…S-6, R-1…R-20) di `docs/keamanan/asvs-fase1.md` §3, termasuk batas umur sesi mutlak
    dan notifikasi peristiwa keamanan (perlu keputusan pemilik untuk angka). Dari tinjauan #86: pemegang izin
    `pengaturan.akun/peran` bercakupan unit masih melihat sub-menunya di sidebar (semua endpoint 403); tugas permintaan
    akses dikirim ke semua pemegang `pengaturan.akun.perbaiki` tanpa melihat cakupan.
  - `queue:prune-failed` dan `horizon:snapshot` belum dijadwalkan; `config/database.php` belum punya `sslrootcert` untuk
    `DB_SSLMODE=verify-full`.
  - Sesudah pemulihan yang kehilangan data: kiriman integrasi yang sudah terkirim bisa kembali `menunggu` (kirim ganda),
    dan anchor jendela yang hilang tidak akan cocok lagi. Prosedur menunggu pemilik (runbook README, keputusan no. 4).
- ~~Utang tinjauan #78~~ **selesai**: panel merek halaman masuk memakai token sendiri (`--panel-merek`, `-tengah`,
  `-akhir`, `-sorot`, `--penekanan-panel`) yang diturunkan `turunanTema()` dari warna paket RS dengan cek kontras
  otomatis (teks putih dan `--brand-100` ≥ 4,5:1 di setiap titik gradien termasuk sorot; penekanan dari aksen ≥ 4,5:1);
  palet bawaan di `tokens.css` tidak berubah. Keputusan pemilik 12 Okt 2026 (panel ikut warna paket RS); PRD 5.3/5.3.2 diselaraskan di PRD v0.10.20 (#93).
- Paket identitas instalasi lokal/staging: `PaketIdentitasContohSeeder` (dipanggil `DatabaseSeeder` di local/staging)
  memasang profil RS dari folder di luar repo (`PAKET_IDENTITAS_CONTOH_DIR`, format di `docs/brand/README.md`) lewat
  `KelolaProfilRs` (kontras, pemeriksaan berkas, audit). Kosong = identitas netral. Berkas dan warna RS nyata tidak di repo.
- Sesudah 1.8f-fe:
  - Layar penyiapan tidak menghitung apa pun sendiri: status, syarat, dan tombol dari `GET /penyiapan` (`dapat_*`). Langkah tanpa layar pengisi (penjamin/tarif, formularium, kredensial integrasi, modul aktif) hanya menampilkan keterangan; tautan langkah 1 ke Akun & akses (superadmin kedua dibuat dari konsol atau akun pegawai).
  - Muat paket referensi hanya untuk pemegang `master_data.katalog.input` (peran `penyiap_instalasi`); superadmin melihat daftar paket tanpa tombol muat. 409 `versi_sudah_dimuat` tampil di kolom versi dengan satu pesan umum; pratinjau tanpa perubahan tampil "Tidak ada perubahan; versi ini sudah termuat".
  - Pita Beranda hanya untuk pemegang `pengaturan.penyiapan.lihat` (tanpa izin tidak memanggil server), memakai kueri yang sama dengan halaman penyiapan.
  - e2e `penyiapan` memakai contoh.superadmin (TOTP) dan menunggu periode TOTP berikutnya di akhir agar spec `pengaturan` tidak memakai ulang kode. Muat paket ke backend asli belum di e2e (tidak ada akun sintetis pemegang katalog.input); diuji unit test dan backend.
  - Utang: akun sintetis `contoh.penyiap` (peran penyiap_instalasi, MFA) untuk e2e muat paket; layar modul aktif; templat CSV per paket belum bisa diunduh dari layar.
  - Utang tinjauan #74 (tidak memblokir; pesan ganda `berkas_tidak_valid`, error versi basi, dan jumlah pratinjau tiruan sudah diperbaiki di 1.9-fe): `boleh()` di klien tidak melihat cakupan (pemegang izin bercakupan unit melihat tombol lalu 403; berlaku umum, bukan khusus penyiapan); `DialogLewati` tidak memuat ulang daftar langkah sesudah 409; 409 `versi_sudah_dimuat` satu pesan umum (beda "isi berbeda"/"sudah digantikan" tidak tampil); permintaan e2e penyiapan tidak dihitung pelacak rate limit `pengaturan.spec`.
- Sesudah 1.8c-fe:
  - Tombol tindakan usulan mengikuti `dapat.*` dari server (pemisahan tugas dijaga server); layar tidak menghitung sendiri siapa boleh memverifikasi/menyetujui. Tanpa tombol, layar menjelaskan bahwa pengusul, pemverifikasi, dan penyetuju harus tiga orang berbeda.
  - Editor butir: objek ubah/tutup dipilih lewat `PemilihLokasi`; nilai saat ini dimuat dari endpoint 1.8b dan hanya isian yang berbeda dikirim. Objek yang dibuat di usulan yang sama belum bisa dirujuk butir berikutnya (utang 1.8c-be).
  - e2e `usulan` (desktop, berurutan): objek awal diimpor `contoh.data.induk` lewat `/lokasi/impor`; ajukan → kembalikan (catatan wajib) → kirim ulang (putaran 2, cek 390 px) → verifikasi → setujui sendiri 403 → penyetuju menyetujui, langsung berlaku. Penerapan terjadwal besok hanya diuji di backend.
  - Belum ada: layar konfigurasi alur (`inti.alur_usulan`), pembatalan usulan oleh pengusul, dan tautan dari rincian lokasi ke usulan yang sedang mengunci objek itu.
  - Utang dari tinjauan #66 (tidak memblokir): galat klien per butir selalu di "Jenis perubahan", bukan di isian yang salah (PRD 5.1 butir 13); `rincian` server untuk kolom tanpa isian di editor (`perubahan.<i>.objek_id`, `.aksi`, `.nilai_baru`) hanya tampil sebagai pesan umum; langkah alur hanya dibedakan warna (belum `aria-current`/teks sr-only, dikembalikan/gagal tanpa penanda); `hariIni()` di dialog memakai zona perangkat, bukan zona RS (hanya `min` pemilih tanggal; server yang memutuskan). Dari tinjauan ulang: respons muat nilai saat ini yang terlambat bisa menimpa pilihan objek yang lebih baru (belum ada pembatal permintaan basi); bila muat nilai saat ini gagal saat kirim ulang, belum ada tombol Coba lagi (pengguna memilih ulang objek).
  - Utang dari tinjauan sesi 1 #66 (tidak memblokir, server tiruan dan editor): tiruan usulan belum menolak pemverifikasi yang mengembalikan di tahap diverifikasi (backend 403 `sudah_memverifikasi`), memeriksa 403/409 sebelum 422 catatan (backend validasi dulu), memberi `bukan_pengusul` tanpa izin input (backend `akses_ditolak`), menulis `tampilan.baru` tutup "Ditutup (alasan)" (kontrak "Ditutup mulai <tanggal>"), menghitung `jumlah.gagal_diterapkan` tanpa batas 30 hari dan mengabaikan filter `jenis_data`; tiruan TTE masih menolak tanda tangan usulan tahap dikembalikan; `DialogUsulan` mengandalkan urutan larik `ref` dalam `v-for` (pakai peta berkunci `b.kunci` agar indeks `perubahan.<i>` tidak bergeser).
- Sesudah 1.8b-fe:
  - Lokasi, bangsal, kelas rawat, dan poliklinik belum punya seeder sintetis; e2e membuat datanya sendiri dengan kode unik per run, dan instalasi demo mulai kosong (diisi lewat impor CSV atau wizard 1.8f).
  - e2e `organisasi` memakai satu akun (`contoh.data.induk`) dan menjaga batas laju API 120/menit per akun dengan jeda, seperti `pengaturan`. Bila uji di berkas itu bertambah, pertimbangkan akun sintetis kedua pemegang `master_data`.
  - Atribut kamar diisi lewat editor kunci-nilai bebas (nilai "ya"/"tidak" → boolean, angka → number) sampai usulan daftar periksa baku (FR-08-19) diputuskan; kode Aplicares dan kode poli BPJS diketik RS tanpa daftar bawaan.
  - Batas "tanggal berlaku ≤ hari ini" (status lokasi) dan "≥ hari ini" (TT baru) dihitung di klien dengan tanggal perangkat untuk pesan awal; server tetap yang memutuskan.
  - Impor CSV: pratinjau dan terapkan memakai berkas yang sama; daftar baris salah dibatasi 500 baris dari server.
  - Utang dari tinjauan #54 (tidak memblokir): tautan langsung `?tab=lokasi&lokasi=<id>` membuka panel rinci tetapi pohon belum membuka cabangnya; `PemilihLokasi` multi-jenis, cari lokasi, dan peringatan kamar melebihi kapasitas memotong di 50 tanpa tanda "masih ada"; `PemilihLokasi` menawarkan ruang berstatus renovasi; editor atribut kamar: kunci ganda menimpa diam-diam, teks berbentuk angka berubah tipe, galat per baris belum `aria-describedby`; pratinjau kode lengkap memakai induk lama sesudah pindah induk; uji unit belum mencakup PATCH selisih + If-Match/412, dialog impor, tutup TT, 409 status lokasi, dan aksi tersembunyi tanpa izin ubah; e2e 1024 px baru memeriksa kartu kosong, "besok tidak dihitung" belum lewat `/kapasitas-tempat-tidur?tanggal=`.
- Sesudah 1.8a-fe:
  - Server tiruan organisasi `frontend/src/modules/organisasi/tiruan/serverOrganisasi.ts` digabung dengan server tiruan akses (`serverGabungan.ts`) untuk `VITE_API_TIRUAN=true` dan unit test; mengikuti kontrak v0.3.0, bukan acuan aturan. `/saya` dan `/saya/menu` tetap butuh backend.
  - Uji kontras di layar profil RS menyalin konstanta server (putih dan rgb 29 29 27, `KontrasWarna`); bila tafsiran FR-54-08 diubah pemilik produk, ubah keduanya.
  - Pratinjau identitas memakai turunan warna `turunanTema()`, bukan token bawaan, sehingga warna bawaan di pratinjau bisa sedikit berbeda dari tampilan nyata (tidak berdampak, hanya pratinjau).
  - Cakupan penugasan di dialog: hanya Seluruh RS dan Unit kerja (kontrak). KSM dan pasien yang dirawat tampil nonaktif sampai kontraknya ada.
  - CI e2e kini menjalankan `db:seed` (DatabaseSeeder: akun + organisasi sintetis), bukan hanya AkunSintetisSeeder. e2e profil RS mengubah nama RS dan logo instalasi e2e; jalankan ulang seeder/DB baru sebelum run lokal bila perlu profil kosong.
  - Berkas contoh paket RS USU dipindah dari `frontend/public/` ke `docs/brand/turunan/`; sejak repo publik (12 Okt 2026) dihapus dari repo dan diganti paket fiktif `docs/brand/contoh/` (unggah lewat layar Profil rumah sakit).
  - **Untuk sesi 1 / keputusan stack:** unggah berkas memakai `PUT` multipart (kontrak v0.3.0). Symfony hanya mengurai body multipart pada PUT di PHP ≥ 8.4 (`Request::createFromGlobals`, `request_parse_body`); di PHP 8.3 `$_FILES` kosong sehingga setiap unggahan ditolak 422. CI dan lokal memakai 8.4 sehingga tidak tertangkap, padahal `composer.json` dan CLAUDE.md menulis PHP 8.3+. Pilih: naikkan syarat ke PHP 8.4 (`composer.json` + CLAUDE.md), atau ubah kontrak ke `POST`.
  - Halaman profil RS dan organisasi belum punya pasangan di prototipe HTML di luar repo (masterdata.html hanya katalog); tata letak mengikuti pola halaman Peran & izin.
- Teks yang sengaja masih memuat istilah lama: migrasi yang sudah berjalan (sejarah, maju saja), ADR 0003 (diberi catatan nama baru), `docs/fase0/`, prototipe HTML (di luar repo), badan tiket lama di backlog, dan bagian "Kerja berkelanjutan" di CLAUDE.md (teks disetujui pemilik repo apa adanya).
- Nama ikon yang dikenal frontend = kode menu prototipe (`frontend/src/design-system/ikon/ikon.ts`: `beranda`, `pendaftaran`, `igd`, `rawat_jalan`, `rawat_inap`, `kamar_operasi`, `penunjang`, `farmasi`, `rekam_medis`, `kasir`, `casemix`, `mutu`, `sdm`, `logistik`, `penelitian`, `pendidikan`, `integrasi`, `dasbor`, `master_data`, `pengaturan`). Seeder menu 1.2a memakai nama ini; nama lain tampil sebagai ikon umum.
- Sidebar ringkas 64 px (tablet mendatar) tidak menampilkan sub-menu. Halaman sub-menu modul memakai `layouts/HalamanModul.vue` (gerbang izin lihat, judul, navigasi sub-menu saudara, pesan ponsel untuk layar kelas terbatas); menu aktif dengan sub-menu tanpa halaman sendiri otomatis menjadi `HalamanIndukModul` (kartu sub-menu). Wajib dipakai modul berikutnya (1.2c-fe).
- Lonceng notifikasi di header prototipe belum dibuat (tiket 1.7). Pemilih unit kerja (1.8a-fe, `layouts/PemilihUnitKerja.vue`, `app/unitKerja.ts`) hanya konteks tampilan di memori; belum dipakai layar mana pun dan hilang saat muat ulang. Modul klinis yang memakainya (mis. daftar pasien ruang) menentukan apakah perlu disimpan per sesi.
- Tombol, kolom isian, dan pesan error di halaman masuk masih ditulis per komponen (`LangkahSandi.vue` dan `components/langkah-mfa.css` bergaya sama). Pindahkan ke `frontend/src/design-system/` saat komponen dasar dibuat (DESIGN.md) → tiket 1.11.

Branch `lampiran/pr-1.4b` berisi gambar yang dirujuk deskripsi PR #10; jangan dihapus selama gambar itu masih dibutuhkan.

Motif dan foto gedung paket RS USU (`docs/brand/motif-usu.svg`, `docs/brand/turunan/`) dihapus dari repo sejak repo publik (12 Okt 2026); demo memakai motif fiktif `docs/brand/contoh/motif-contoh.svg`.

Panel merek halaman masuk memakai foto gedung dari paket identitas bila RS mengunggahnya (diunggah lewat Pengaturan → Profil rumah sakit; berkas RS nyata tidak disimpan di repo).

---

## [SIMRS] Akses · 1.13 Perangkat tepercaya (MFA tidak diminta setiap kali masuk)

```
Tujuan     : Pengguna ber-MFA (termasuk superadmin) tidak perlu memasukkan kode setiap kali masuk dari perangkat yang
             sama, tanpa mematikan MFA. Menutup utang ADR 0002 "registri perangkat terdaftar".
Keputusan  : MFA superadmin, peran sensitif, dan akses dari luar jaringan RS TIDAK bisa dimatikan (PRD 9.2.4,
             10.3.2 tetap). Yang diatur adalah frekuensinya lewat perangkat tepercaya.
Aturan     : - Setelah MFA berhasil, pengguna boleh mencentang "Percayai perangkat ini" → token perangkat acak (cookie
               HttpOnly, Secure, SameSite=Strict, terpisah dari sesi) disimpan sebagai hash di akses.perangkat_tepercaya
               (pengguna_id, nama perangkat dari user-agent, dibuat_pada, terakhir_dipakai_pada, berlaku_sampai,
               dicabut_pada, alasan_cabut).
             - Masuk dengan sandi benar + token perangkat sah + dari jaringan RS → tidak diminta MFA. Dari luar
               jaringan RS → MFA tetap diminta (token perangkat tidak berlaku di luar jaringan RS).
             - Kebijakan per peran (layar Peran & izin) dan bawaan instalasi (Pengaturan → Keamanan): masa percaya
               0 (fitur mati) sampai maksimum. Bawaan: 30 hari; superadmin maksimum 7 hari; peran sensitif maksimum
               14 hari (batas atas di konfigurasi, tidak bisa dinaikkan dari layar).
             - Batas diam sesi di perangkat tepercaya mengikuti PRD 14.2 (60 menit), di perangkat lain 15 menit.
             - Ganti sandi, reset sandi, reset MFA, nonaktif akun → semua perangkat tepercaya akun itu dicabut.
             - Pengguna melihat dan mencabut perangkatnya sendiri (menu akun); admin mencabut perangkat akun lain.
             - Audit: akses.perangkat_dipercaya, akses.perangkat_dicabut; MFA dilewati karena perangkat tercatat.
             - MFA ulang untuk aksi sensitif (K11, lapis 5) TIDAK dilewati oleh perangkat tepercaya.
Uji penerimaan:
  - Superadmin MFA + centang → keluar → masuk lagi dari jaringan RS tanpa kode; dari IP luar jaringan RS → diminta kode.
  - Setelah 7 hari (waktu dimajukan) superadmin diminta kode lagi; peran biasa 30 hari.
  - Reset MFA/ganti sandi mencabut perangkat; token dicuri dipakai akun lain → ditolak (token terikat pengguna).
  - Cookie token tidak bisa dibaca JavaScript; token tidak pernah di log.
Di luar lingkup: WebAuthn/passkey (tiket lanjutan).
```
## [SIMRS] Akses · 1.14 Reset sandi oleh admin dan perintah konsol darurat

```
Tujuan     : Akun (termasuk superadmin) yang lupa sandi bisa dipulihkan tanpa menyentuh database.
Yang sudah ada: ganti sandi sendiri (POST /saya/sandi), sandi awal saat akun dibuat (1.8d), reset MFA (1.2c).
Aturan     : - POST /akun/{id}/sandi/reset (izin pengaturan.akun.perbaiki, alasan + dasar wajib): membuat sandi sementara
               acak yang memenuhi aturan sandi, ditampilkan SEKALI ke admin, akun wajib ganti sandi saat masuk berikutnya,
               semua sesi dan perangkat tepercaya akun itu dicabut, audit tanpa nilai sandi.
             - Tidak bisa mereset sandi sendiri (pakai ganti sandi). Reset sandi superadmin hanya oleh superadmin lain
               (kontrol ganda K4/K13: lewat persetujuan bila kebijakan mewajibkan, bawaan langsung oleh superadmin lain).
             - Konsol darurat `php artisan akses:reset-sandi <nama_pengguna>` untuk keadaan tidak ada superadmin lain:
               butuh akses server, menampilkan sandi sementara sekali di terminal, tidak menulis ke log, tercatat audit
               "akses.sandi_direset_konsol".
             - Halaman masuk: "Lupa sandi?" menjelaskan cara meminta reset ke unit admin (kontak dari profil RS).
               Reset mandiri lewat email TIDAK dibuat di tiket ini (keputusan: RS memverifikasi identitas tatap muka).
  fe       : tombol "Reset sandi" di Pengaturan → Akun & akses (dialog alasan + dasar, tampilan sandi sementara sekali
             dengan tombol salin, peringatan "tidak akan ditampilkan lagi").
Uji penerimaan: reset → sesi lama akun itu putus → masuk dengan sandi sementara → dipaksa ganti; superadmin mereset
             dirinya → 403; perintah konsol berjalan dan tercatat audit; sandi tidak muncul di log mana pun.
```
## [SIMRS] Platform · 1.15 Tema bawaan pedoman identitas RS mitra dan halaman masuk yang dapat diatur

```
Tujuan     : Tampilan bawaan produk memakai palet dari pedoman identitas RS mitra (keputusan pemilik), halaman masuk kembali
             ke rancangan awal (panel hijau, motif, foto gedung, keterangan foto), dan semua teks serta gambar panel
             halaman masuk dapat diubah admin dari layar tanpa ubah kode.
Keputusan  : - Palet bawaan di design-system kembali ke token sebelum #78: brand #006937 (hover #005A2F, fokus #1F8A4C),
               panel gelap #135851, emas #F3C300 (isian/garis saja), kuning #FAE345 (teks penekanan di panel gelap),
               merah/hitam pedoman untuk dokumen cetak. Kontras mengikuti catatan di token (PRD 5.3.2).
             - Logo, nama RS, motif, dan foto TETAP dari paket identitas (profil RS / PaketIdentitasContohSeeder),
               bukan berkas di repo, sampai pemilik menyatakan izin Humas RS mitra mencakup publikasi di repo publik.
             - Instalasi yang mengisi warna merek sendiri tetap memakai warnanya (turunan + cek kontras dari #92).
Halaman masuk dapat diatur (Pengaturan → Profil rumah sakit → tab "Halaman masuk"):
  - lencana (≤ 60 karakter), judul (≤ 80) dengan bagian sorot (≤ 40), uraian (≤ 240), tiga butir fitur
    (judul ≤ 30, uraian ≤ 120), foto panel (jpg/webp/png, dipotong 16:10, EXIF/GPS dibuang), keterangan foto (≤ 80),
    tampilkan/sembunyikan motif. Bawaan = teks yang sekarang.
  - Teks biasa saja (tanpa HTML/markdown), dibersihkan di server; pratinjau langsung di layar; simpan dengan dasar
    perubahan dan versi (If-Match) seperti profil RS; audit.
  - Cek kontras teks di atas panel tetap otomatis (pakai fungsi #92).
Kontrak   : perluas GET/PUT /profil-rs dengan objek halaman_masuk; unggah foto lewat endpoint berkas yang ada.
Uji penerimaan: instalasi kosong → halaman masuk hijau pedoman dengan teks bawaan; admin mengganti judul dan foto →
             halaman masuk berubah tanpa build ulang; teks berisi <script> tersimpan sebagai teks biasa; e2e 4 lebar + axe;
             tangkapan layar PR memakai identitas fiktif.
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
