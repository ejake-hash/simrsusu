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
| 1.0g | Istilah umum: ganti kata baku yang jarang dipakai (galat, surel, jangkar, dll.) dengan istilah yang lazim, di kode, kontrak, PRD, dan layar; glosarium di PRD 10.3.3. Catatan: PRD v0.10.11 (glosarium) merge di #39 sesudah ditinjau pemilik, kontrak v0.2.1; kolom `akses.pengguna.email`; kode menu `master_data.*`; variabel .env berganti nama (daftar di PR #29); migrasi lama dan ADR 0003 tetap memuat istilah lama sebagai sejarah | Selesai | #29 |
| 1.2c-be | Backend Pengaturan → Peran & izin, Akun & akses: pindah kode menu ke `pengaturan.*`, endpoint kontrak v0.2.0, wajib MFA per peran/akun, reset MFA, larangan diri sendiri. Kode lain yang semula `data_induk.*` kini `master_data.*` (1.0g). Catatan: kontrak v0.2.2; izin/sub-menu yang dicabut dari peran berstatus batal (kolom pembatalan di `peran_izin`/`peran_menu`); versi peran = kolom `revisi`; pencabutan sesi lewat `akses.pengguna.sesi_dicabut_pada` (ADR 0002); akun `contoh.superadmin` + `AKUN_SINTETIS_SUPERADMIN_MFA_RAHASIA` untuk e2e | Selesai | #32 |
| 1.2c-fe | Frontend halaman Peran & izin, Akun & akses (`masterdata.html`, temuan MD-1…MD-4), navigasi sub-menu di halaman induk modul. Catatan: server tiruan kontrak v0.2.2; e2e `pengaturan` ke backend asli | Selesai | #30 |
| **1.2e** | Beranda: modul saya, keamanan akun, pencarian menu, aksesibilitas (`home.html`); tanpa angka tiruan | be: Selesai (kontrak #33, backend #34). Catatan: kolom `akses.pengguna.login_terakhir_dari_jaringan_rs`, `masuk_sebelumnya_pada`, `masuk_sebelumnya_dari_jaringan_rs`; `email` tidak dikirim di `/saya` maupun respons masuk; tanda instalasi demo tidak di `/saya` (milik instalasi, ke `/profil-rs` 1.8a). fe: Selesai (#35) | #33, #34, #35 |
| 1.2d-be / 1.2d-fe | Persetujuan akses (izin sensitif, superadmin), permintaan akses dari layar "Akses ditolak" | be: Selesai (kontrak #41, backend #43). Catatan: `akses.persetujuan_akses` (satu baris per peran penyetuju, satu grup per pengajuan), `akses.permintaan_akses`, `akses.izin.peran_penyetuju_id`; sub-menu `pengaturan.persetujuan` lewat peran (bawaan superadmin); tugas harian `akses:kedaluwarsakan-persetujuan`; fe: Selesai (#47). Catatan fe: layar Pengaturan → Persetujuan akses (antrean, rincian, permintaan akses, penyetuju izin), Akses ditolak + Minta akses di `HalamanModul`, izin menunggu terkunci di editor peran, pengajuan menunggu di rincian akun | #41, #43, #47 |
| 1.8a-be / 1.8a-fe | Profil RS dan paket identitas, unit organisasi, KSM (ganti `VITE_RS_*`) | be: Selesai (kontrak #37, backend #38). Catatan: profil di `App\Core\ProfilRs`, unit/KSM di modul `MasterData`, lapis 3 cakupan `unit` = subpohon, berkas lewat disk `IDENTITAS_DISK`, akun e2e `contoh.data.induk`; fe: Selesai (#42). Catatan fe: profil dari `GET /profil-rs` (tanpa `VITE_RS_*`), tema dari dua warna paket identitas (`app/tema.ts`), pohon unit muat-lambat, cakupan penugasan `unit`, pemilih unit kerja di header | #37, #38, #42 |
| 1.8b-be / 1.8b-fe | Lokasi fisik bertingkat, bangsal/kamar/TT, kelas rawat, poliklinik, impor CSV | be: Selesai (kontrak #44, backend #46). Catatan: tabel di skema `inti`, izin `master_data.organisasi.*` cakupan `rs`, tugas harian `master-data:rekam-kapasitas-tt`; fe: Selesai (#54). Catatan fe: tab Lokasi & tempat tidur, Bangsal, Kelas rawat, Poliklinik di halaman Organisasi, Unit, Ruang & Tempat Tidur (judul PRD 9.1) | #44, #46, #54 |
| 1.8c-be / 1.8c-fe | Usulan perubahan data induk berjenjang (mesin generik), dipakai pertama oleh TT | be: Selesai (kontrak #61, backend #63). Catatan: `inti.usulan_data_induk` (+ `_putaran`, `_kejadian`), `inti.alur_usulan`, `inti.data_induk_versi`; izin `master_data.usulan.{lihat,input,verifikasi,setujui}` (aksi tambahan `MenuBawaan::AKSI_TAMBAHAN`); tugas harian `master-data:terapkan-usulan` 00.00; penjaga `App\Core\Instalasi\StatusInstalasi` (1.8f mengisinya); fe: Selesai (#66). Catatan fe: Master Data → Usulan perubahan (`/master-data/usulan?usulan=`; kartu per tahap, filter Terbuka/Perlu saya/Semua, rincian diff lama → baru dari `tampilan[]`, alur, riwayat per putaran, TTE), editor butir ruang/kamar/TT, riwayat versi di rincian lokasi; sub-menu `master_data.usulan` TERSEDIA (migrasi `2026_10_13_110000`); e2e `usulan` dengan akun `contoh.usulan.*` | #61, #63, #66 |
| 1.8d-be / 1.8d-fe | Pegawai, izin praktik (STR/SIP/SPK), RKK minimal, akun dibuat dari pegawai | be: Selesai (kontrak #48, backend #50). Catatan: NIK utuh hanya `?tampilkan_nik=true` + izin bagian `sdm.pegawai.identitas.lihat` (sensitif, pemilik data ditetapkan RS); pegawai keluar → akun nonaktif lewat kejadian `PegawaiKeluar`; tugas harian `sdm:proses-pegawai-keluar`; 403 `wajib_ganti_sandi`; fe: Selesai (#58). Catatan fe: SDM → Data pegawai (daftar, rincian, izin praktik, RKK, Buat akun), layar ganti sandi di luar AppShell, Nonaktifkan/Aktifkan di rincian akun, kepala unit/ketua KSM di dialog ubah unit/KSM; akun e2e `contoh.sdm` (#55) | #48, #50 |
| 1.8e-be | Pemuat paket referensi (`inti.paket_referensi`) | Selesai (kontrak #65, backend PR ini). Catatan: modul `MasterData` (`PemuatReferensi`), tabel `inti.paket_referensi`/`wilayah`/`referensi`/`terminologi` (+ `aktif`, `versi_terakhir`, `dinonaktifkan_pada`), perintah `referensi:muat`, `database/referensi/SUMBER.md`; isi paket resmi belum dikemas (menunggu berkas, lihat badan tiket) | #65 |
| 1.8f-be / 1.8f-fe | Wizard penyiapan instalasi kosong (FR-54-08) | be: Selesai (kontrak #68, backend #70). Catatan: `App\Core\Instalasi\Penyiapan` (status dihitung dari data, konfirmasi di `inti.penyiapan_konfirmasi`), `siap_melayani` terkunci di `inti.profil_rs.siap_melayani_sejak`, penjaga `StatusPenyiapan` menggantikan `BelumSiapMelayani`, sub-menu `pengaturan.penyiapan` bawaan superadmin (belum TERSEDIA sampai 1.8f-fe), peran contoh `penyiap_instalasi` (penyiapan + `master_data.katalog.lihat/input`, dibuat sekali, tidak terkunci); fe: Selesai (PR ini). Catatan fe: Pengaturan → Penyiapan instalasi (`/pengaturan/penyiapan`; 9 langkah, syarat, tautan ke layar pengisi, konfirmasi/ulang dan lewati langkah 8 mengikuti `dapat_*`), muat paket referensi langkah 4 (pratinjau → terapkan), pita "Instalasi belum bisa melayani pasien" di Beranda; sub-menu TERSEDIA (migrasi `2026_10_13_140000`); e2e `penyiapan` (contoh.superadmin) | #68, #70 |
| 1.7-be / 1.7-fe | Notifikasi, kotak kerja, jenis tugas, eskalasi 3 tingkat | be: Selesai (kontrak #51, backend #53); fe: Selesai (#60). Catatan fe: lonceng di header (polling 30 detik, ETag/304, `X-Permintaan-Latar` hanya pada polling), halaman `/notifikasi` (belum dibaca, semua, preferensi), kotak kerja di Beranda dengan riwayat tugas, Master Data → Jenis Tugas, Tenggat & Eskalasi | #51, #53 |
| 1.6-be / 1.6-fe | TTE T1: aktivasi PIN, kunci Ed25519, TtdModal, verifikasi, tanda tangan massal; adapter PSrE + simulator | be: Selesai (kontrak #56, backend #57). Catatan be: modul `Ttd`, kunci server `TTD_KUNCI`, seeder `TtdSintetisSeeder`; dedup tanda tangan per isi sejak 1.8c; fe: Selesai (#62). Catatan fe: TtdModal dan BadgeTtd di design-system, halaman `/tanda-tangan` (aktivasi, ganti PIN) dari menu akun, tanda tangan keputusan di rincian persetujuan, `reset_ttd`/penyetuju orang di 1.2d, Ajukan reset TTE di data pegawai | #56, #57, #62 |
| 1.5-be / 1.5-fe | Lapis 4 (label sensitivitas) dan 5 (izin praktik, RKK, MFA ulang) | Siap setelah 1.8d, 1.2d, 1.6 | — |
| 1.9-be / 1.9-fe | Gateway integrasi: outbox, adapter BPJS/SATUSEHAT/Aplicares, simulator, layar Konfigurasi & status | Siap setelah 1.8b, 1.8f | — |
| 1.11 | Design system: komponen inti PRD 5.4 yang dipakai fase 1, katalog komponen (dev) | Selesai | #36 |
| 1.12 | Gerbang keluar Fase 1: uji beban, cek keamanan, runbook instalasi (PRD 16 sudah di v0.10.12) | Siap setelah semua | — |
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
| K8 | Kriteria keluar Fase 1 tanpa RS | Sandbox BPJS/SATUSEHAT diganti **simulator + uji kontrak** berbasis `docs/integrasi/catatan-lapangan-bridging.md`. Sambungan sandbox nyata dipindah menjadi syarat **masuk pilot fase 2** bersama SUS dan design partner. PRD 16/16.1 sudah diubah di PRD v0.10.12 (disetujui pemilik) | Kredensial sandbox diberikan ke faskes, bukan vendor [Medium] |
| K9 | Kredensial integrasi | Tabel `integrasi.kredensial_api` terenkripsi dengan **kunci khusus** `KREDENSIAL_KUNCI` (bukan APP_KEY, bisa dirotasi), tidak pernah dikirim ke layar (hanya 4 karakter terakhir), antarmuka `PenyimpanRahasia` agar vault bisa dipasang kemudian | Menyelaraskan FR-54-08 ("vault") dengan 11.2 (`kredensial_api`) untuk instalasi on-premise tanpa vault |
| K10 | TTE fase 1 | T1 (PIN + Ed25519) penuh. T2/PSrE: antarmuka adapter + simulator saja; penyedia dipilih saat kontrak PSrE | PRD 7.57.3: API PSrE belum diverifikasi |
| K11 | MFA ulang untuk aksi sensitif | Izin `butuh_mfa` meminta TOTP bila verifikasi MFA terakhir di sesi > 15 menit | Lapis 5 (9.2.2); angka di konfigurasi |
| K12 | Akses darurat (break-glass) | Ditunda ke fase 2 bersama RME (FR-26-12), karena butuh data pasien | Tidak ada data pasien di fase 1 |
| K13 | Penyetuju izin pengelola akses | Izin `pengaturan.peran.*` dan `pengaturan.akun.*` aksi input/perbaiki (sensitif sejak 1.2c, `MenuBawaan::IZIN_SENSITIF`): penyetujunya **superadmin selain pengaju**. Dipasang sebagai data awal di 1.2d | Ketentuan pemilik produk 11 Okt 2026; kuasa setara superadmin hanya diberikan dengan kontrol ganda (PRD 9.2.4) |

Bila pemilik produk mengubah keputusan, perubahan ditulis ke tabel ini lewat PR dan tiket terkait disesuaikan.

## Utang dan keputusan tertunda

Rincian alasan ada di ADR yang dirujuk; di sini hanya daftarnya agar tidak ada dua versi.

**Fitur yang belum dibangun**
- Pemberitahuan akun terkunci ke pemilik (PRD 14.2) → tiket 1.7, issue #11.
- Superadmin dari `akses:superadmin-pertama` ditandai lewat audit `akses.superadmin_dibuat` (tanpa kolom baru) → tiket 1.2 memetakannya ke peran superadmin dan mengganti hitungan batas 2 di `SuperadminPertama`.
- Registri perangkat terdaftar: sampai ada, semua sesi memakai batas diam 15 menit (`PerangkatBelumTerdaftar`). Belum ada tiket (ADR 0002).
- Ganti pengguna cepat di komputer ruangan (kartu pegawai/PIN, PRD 14.2). Belum ada tiket (ADR 0002).
- Cek sandi kontekstual (sandi memuat nama pengguna, nama RS, atau nama produk) saat fitur ganti sandi dibuat (ADR 0001) → tiket 1.8d.

**Syarat sebelum go-live**
- Uji beban penulisan audit: semua penulisan antre di satu kunci rantai sampai commit (ADR 0003, PRD 14.1) → tiket 1.12.
- `AUDIT_ANCHOR_DISK` ke penyimpanan terpisah yang tidak bisa ditimpa (mis. MinIO object lock) dan channel log `audit_anchor` ke SIEM (ADR 0003).
- Cron `php artisan schedule:run` di server (partisi audit, anchor per jam; ADR 0003).
- `AUTENTIKASI_MFA_JARINGAN_RS` diisi jaringan RS yang sebenarnya (PRD 10.3.2; ditegakkan di tiket 1.1c).
- Daftar sandi bocor diperbarui dari SecLists terbaru (ADR 0001, `SUMBER.md`).

**Repo dan teknis**
- Aturan cabang `main` (merge hanya lewat PR, check `backend`/`frontend`/`e2e` wajib hijau) **belum aktif**: GitHub Free menolak proteksi cabang dan rulesets untuk repo privat. Perlu GitHub Pro atau repo dipindah ke organisasi berpaket Team. Sampai itu, merge setelah CI hijau dijaga lewat kebiasaan kerja.
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
  - Lapis 3 cakupan `unit` kini dievaluasi dengan pohon unit (kueri rekursif tanpa cache); perlu diukur ulang di uji beban 1.12.
  - **[Perlu pemilik]** `GET /profil-rs` dan berkasnya dari tamu (halaman masuk) kena rate limit 120/menit per IP. Di RS dengan satu NAT kuota ini bisa habis saat banyak orang membuka halaman masuk bersamaan. Melonggarkan rate limit menunggu keputusan pemilik (catatan sesi 2, tinjauan #38).
  - Penugasan ke unit yang sedang dinonaktifkan bersamaan bisa lolos (cek unit aktif di Akses tanpa kunci pohon MasterData); rendah, tinjau saat 1.2d menyentuh penugasan.
  - Untuk 1.2d: saat memberi penugasan, cakupan pemberi belum dibandingkan dengan cakupan yang diberikan (aman sekarang karena `pengaturan.akun.input` sensitif dan hanya superadmin); wajib bila admin delegasi per unit dibuat.
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
  diusulkan di PR [Perlu pemilik] tersendiri; 1.8f (`inti.penyiapan_konfirmasi`, `profil_rs.siap_melayani_sejak`)
  didokumentasikan di PRD berikutnya.
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
- Sesudah 1.8f-fe:
  - Layar penyiapan tidak menghitung apa pun sendiri: status, syarat, dan tombol dari `GET /penyiapan` (`dapat_*`). Langkah tanpa layar pengisi (penjamin/tarif, formularium, kredensial integrasi, modul aktif) hanya menampilkan keterangan; tautan langkah 1 ke Akun & akses (superadmin kedua dibuat dari konsol atau akun pegawai).
  - Muat paket referensi hanya untuk pemegang `master_data.katalog.input` (peran `penyiap_instalasi`); superadmin melihat daftar paket tanpa tombol muat. 409 `versi_sudah_dimuat` tampil di kolom versi dengan satu pesan umum; pratinjau tanpa perubahan tampil "Tidak ada perubahan; versi ini sudah termuat".
  - Pita Beranda hanya untuk pemegang `pengaturan.penyiapan.lihat` (tanpa izin tidak memanggil server), memakai kueri yang sama dengan halaman penyiapan.
  - e2e `penyiapan` memakai contoh.superadmin (TOTP) dan menunggu periode TOTP berikutnya di akhir agar spec `pengaturan` tidak memakai ulang kode. Muat paket ke backend asli belum di e2e (tidak ada akun sintetis pemegang katalog.input); diuji unit test dan backend.
  - Utang: akun sintetis `contoh.penyiap` (peran penyiap_instalasi, MFA) untuk e2e muat paket; layar modul aktif; templat CSV per paket belum bisa diunduh dari layar.
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
  - Berkas contoh paket RS USU dipindah dari `frontend/public/` ke `docs/brand/turunan/` (logo, foto, motif) agar tidak ikut build produksi (PRD 3.4); untuk demo internal, unggah lewat layar Profil rumah sakit.
  - **Untuk sesi 1 / keputusan stack:** unggah berkas memakai `PUT` multipart (kontrak v0.3.0). Symfony hanya mengurai body multipart pada PUT di PHP ≥ 8.4 (`Request::createFromGlobals`, `request_parse_body`); di PHP 8.3 `$_FILES` kosong sehingga setiap unggahan ditolak 422. CI dan lokal memakai 8.4 sehingga tidak tertangkap, padahal `composer.json` dan CLAUDE.md menulis PHP 8.3+. Pilih: naikkan syarat ke PHP 8.4 (`composer.json` + CLAUDE.md), atau ubah kontrak ke `POST`.
  - Halaman profil RS dan organisasi belum punya pasangan di prototipe `docs/html/` (masterdata.html hanya katalog); tata letak mengikuti pola halaman Peran & izin.
- Teks yang sengaja masih memuat istilah lama: migrasi yang sudah berjalan (sejarah, maju saja), ADR 0003 (diberi catatan nama baru), `docs/fase0/`, prototipe `docs/html/`, badan tiket lama di backlog, dan bagian "Kerja berkelanjutan" di CLAUDE.md (teks disetujui pemilik repo apa adanya).
- Nama ikon yang dikenal frontend = kode menu prototipe (`frontend/src/design-system/ikon/ikon.ts`: `beranda`, `pendaftaran`, `igd`, `rawat_jalan`, `rawat_inap`, `kamar_operasi`, `penunjang`, `farmasi`, `rekam_medis`, `kasir`, `casemix`, `mutu`, `sdm`, `logistik`, `penelitian`, `pendidikan`, `integrasi`, `dasbor`, `master_data`, `pengaturan`). Seeder menu 1.2a memakai nama ini; nama lain tampil sebagai ikon umum.
- Sidebar ringkas 64 px (tablet mendatar) tidak menampilkan sub-menu. Halaman sub-menu modul memakai `layouts/HalamanModul.vue` (gerbang izin lihat, judul, navigasi sub-menu saudara, pesan ponsel untuk layar kelas terbatas); menu aktif dengan sub-menu tanpa halaman sendiri otomatis menjadi `HalamanIndukModul` (kartu sub-menu). Wajib dipakai modul berikutnya (1.2c-fe).
- Lonceng notifikasi di header prototipe belum dibuat (tiket 1.7). Pemilih unit kerja (1.8a-fe, `layouts/PemilihUnitKerja.vue`, `app/unitKerja.ts`) hanya konteks tampilan di memori; belum dipakai layar mana pun dan hilang saat muat ulang. Modul klinis yang memakainya (mis. daftar pasien ruang) menentukan apakah perlu disimpan per sesi.
- Tombol, kolom isian, dan pesan error di halaman masuk masih ditulis per komponen (`LangkahSandi.vue` dan `components/langkah-mfa.css` bergaya sama). Pindahkan ke `frontend/src/design-system/` saat komponen dasar dibuat (DESIGN.md) → tiket 1.11.

Branch `lampiran/pr-1.4b` berisi gambar yang dirujuk deskripsi PR #10; jangan dihapus selama gambar itu masih dibutuhkan.

Motif `docs/brand/motif-usu.svg` dibuat ulang (bukan berkas resmi pedoman); hanya untuk pengembangan sampai dicocokkan dengan `PATTERN DESIGN USU.ai` dari Humas/Promosi USU.

Panel merek halaman masuk memakai foto gedung dari paket identitas (contoh RS USU: `docs/brand/turunan/foto-rs-usu.webp`, turunan `docs/brand/RS USU Gedung.jpg`: dipotong 16:10, tanpa EXIF/GPS; diunggah lewat Pengaturan → Profil rumah sakit, tidak ikut build sejak 1.8a-fe) menggantikan ilustrasi peta tempat tidur. Prototipe `docs/html/login.html` masih memakai peta tempat tidur. Hak pakai foto untuk materi di luar pengembangan belum dikonfirmasi ke Humas/Promosi USU.

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

## [SIMRS] Akses · 1.2d Persetujuan akses

```
Tujuan     : Pemberian akses berisiko tidak pernah aktif tanpa persetujuan orang yang berwenang, dan pengguna bisa
             meminta akses yang ia butuhkan tanpa telepon ke TI.
FR         : PRD 9.2.3, 9.2.4, 9.3 (antrean persetujuan, permintaan akses), FR-09-06, FR-09-15; keputusan K3, K4
Tabel      : akses.persetujuan_akses (jenis: izin_sensitif | peran_superadmin | pengecualian; objek; diajukan_oleh;
             penyetuju_peran_id/penyetuju_id; status menunggu/disetujui/ditolak/kedaluwarsa; alasan; diputuskan_pada),
             akses.permintaan_akses (11.2), kolom akses.izin.peran_penyetuju_id
API (be)   : GET/POST /persetujuan-akses (daftar "untuk saya putuskan" dan "yang saya ajukan"), POST .../{id}/setujui,
             POST .../{id}/tolak (alasan wajib); POST /permintaan-akses (dari layar Akses ditolak: menu/izin yang
             diminta + alasan), GET /permintaan-akses; PUT /izin/{kode}/penyetuju (atur peran penyetuju izin sensitif)
Pekerjaan  :
  be 1. Ganti penolakan 409 butuh_persetujuan dan peran_bawaan_terkunci dari 1.2c: perubahan disimpan sebagai
        persetujuan menunggu, baru berlaku saat disetujui (dalam transaksi yang sama dengan penerapannya).
     2. Penyetuju: izin sensitif → pemegang peran penyetuju izin itu; superadmin → superadmin lain. Pengaju tidak
        pernah bisa menyetujui permintaannya sendiri (cek di server). Kedaluwarsa 7 hari (jadwal harian).
     3. Permintaan akses diteruskan ke pemberi kewenangan; bila disetujui, pemberi membuat pengecualian/penugasan
        dengan dasar = nomor permintaan.
     4. Saran jadikan peran bila pengecualian yang sama dipakai ≥ 5 akun (FR-09-15): endpoint saran di daftar akun.
     5. Setiap keputusan: audit + hapus cache menu akun terdampak. Notifikasi ke penyetuju lewat 1.7 (sebelum 1.7
        ada: hanya tampil di antrean).
     6. Data awal (K13): penyetuju izin pengelola akses (`pengaturan.peran.*`, `pengaturan.akun.*` aksi input/perbaiki)
        = peran superadmin, dengan pengaju tidak boleh menyetujui sendiri (butir be 2). Dipasang lewat migrasi/MenuBawaan,
        aman diulang, tidak menimpa penyetuju yang sudah diubah admin.
  fe 1. Layar Pengaturan → Persetujuan (tab "Perlu saya putuskan" / "Yang saya ajukan"), kartu rinci: siapa, apa,
        dasar, dampak (akun terdampak), tombol Setujui/Tolak hanya untuk penyetuju yang ditunjuk (MD-4).
     2. Halaman "Akses ditolak" (403 dari rute/halaman) dengan tombol "Minta akses" (satu klik + alasan).
     3. Di editor peran dan akun 1.2c: izin sensitif yang ditambah tampil "Menunggu persetujuan <peran>" alih-alih error.
Uji penerimaan:
  - Izin sensitif tanpa penyetuju → tidak bisa dipilih (422 penyetuju_belum_ditetapkan).
  - Data awal K13: superadmin A menambah `pengaturan.akun.perbaiki` ke peran X → menunggu; superadmin B menyetujui → berlaku;
    A tidak bisa menyetujui permintaannya sendiri.
  - Pengaju menyetujui sendiri → 403; penyetuju lain → berlaku, akun terdampak melihat menu baru.
  - Superadmin A menugaskan peran superadmin ke akun C → menunggu; superadmin B menyetujui → berlaku; A tidak bisa.
  - Permintaan kedaluwarsa setelah 7 hari tanpa keputusan (uji dengan waktu dimajukan).
  - e2e: pengguna tanpa izin membuka rute → Akses ditolak → minta akses → admin melihat permintaan.
Di luar lingkup tiket: admin delegasi per unit (FR-09-07, fase 2), tinjauan akses 6 bulanan (fase 4).
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

## [SIMRS] Master Data · 1.8a Profil RS, unit organisasi, KSM

```
Tujuan     : Identitas RS dan struktur organisasi datang dari data, bukan variabel build; dasar untuk cakupan akses,
             atasan (eskalasi), lokasi, dan pegawai.
FR         : PRD 3.4 (paket identitas), FR-54-01, FR-54-08 langkah 2–3, 11.2 inti.profil_rs, unit_organisasi, ksm
API (be)   : GET /profil-rs (publik terbatas: nama, logo, warna, kontak admin — untuk halaman masuk), PUT /profil-rs;
             unggah logo/motif/foto ke MinIO (tipe dan ukuran dibatasi, dipindai tipe sebenarnya);
             CRUD /unit-organisasi (pohon, induk, jenis, kepala_pegawai_id menyusul 1.8d, status, berlaku_dari);
             CRUD /ksm
Pekerjaan  :
  be 1. Tabel dan endpoint di atas; warna merek diuji kontras otomatis (teks di latar putih ≥ 4,5:1, isian ≥ 3:1 →
        422 kontras_kurang) (FR-54-08 langkah 2).
     2. Cakupan penugasan `unit` (1.2c: 422 cakupan_belum_didukung) kini didukung untuk unit; AccessEvaluator lapis 3
        memakai pohon unit (unit induk mencakup turunannya).
     3. inti.modul_aktif.unit_organisasi_id berkunci tamu (utang 1.2a).
     4. Izin menu master_data.organisasi.* dan profil di pengaturan.profil_rs.*.
  fe 1. Halaman profil RS (pratinjau halaman masuk dan sidebar dengan identitas baru) dan pohon unit + KSM.
     2. Ganti `frontend/src/app/profilRs.ts` (VITE_RS_*) dengan GET /profil-rs; build produksi tanpa variabel tetap
        bekerja. Pemilih unit kerja di header (utang 1.2b) bila akun punya penugasan ber-cakupan unit.
Uji penerimaan:
  - Logo baru tampil di halaman masuk tanpa build ulang; logo SVG berisi skrip ditolak.
  - Warna dengan kontras kurang ditolak dengan pesan rasio.
  - Penugasan cakupan unit "Instalasi Rawat Inap" memberi akses data unit turunannya saja (uji AccessEvaluator).
  - Pohon unit 500 node: daftar memakai paginasi/lazy load, P95 < 300 ms lokal.
Di luar lingkup tiket: pusat biaya (fase 4), SATUSEHAT Organization (1.9).
```

## [SIMRS] Master Data · 1.8b Lokasi fisik, ruang, dan tempat tidur

```
Tujuan     : RS sebesar apa pun bisa memetakan gedung → lantai → ruang → TT, mengubah jumlah TT dengan aman, dan
             setiap kunjungan nanti punya lokasi nyata (SATUSEHAT Encounter.location).
FR         : PRD FR-54-11…14, FR-08-16…20 (kecuali usulan berjenjang = 1.8c), 11.2 lokasi, bangsal/kamar/tempat_tidur,
             kapasitas_tempat_tidur_harian, kelas_rawat, poliklinik
API (be)   : CRUD /lokasi (pohon; pindah induk; status dengan berlaku_dari), POST /lokasi/impor (CSV, mode
             pratinjau dulu lalu terapkan), CRUD /bangsal, /kamar, /tempat-tidur, /kelas-rawat, /poliklinik;
             GET /kapasitas-tempat-tidur?tanggal=
Pekerjaan  :
  be 1. Validasi: kode unik per induk, tanpa siklus, TT hanya di bawah ruang, jenis anak sesuai jenis induk.
     2. Kode TT tidak pernah dihapus/dipakai ulang; penutupan lewat status_operasional dan berlaku_sampai.
        TT terisi/dipesan tidak bisa ditutup (aturan siap, karena penempatan pasien baru ada di fase 2: sediakan
        antarmuka `PemeriksaPenempatan` yang di fase 1 selalu "kosong").
     3. Tugas harian mengisi kapasitas_tempat_tidur_harian (jumlah tersedia dan ekstra per bangsal/kelas).
     4. Atribut standar kamar (FR-08-19) sebagai daftar periksa data induk; peringatan bila TT > kapasitas maksimum.
     5. Impor CSV: templat diunduh dari layar; pratinjau menampilkan baris salah beserta alasannya; terapkan
        dalam satu transaksi; 5.000 baris < 30 detik (lewat queue `dokumen` bila perlu).
     6. Kejadian `KapasitasTempatTidurBerubah` (dipakai Aplicares di 1.9).
  fe 1. Layar Master Data → Organisasi, Unit, Ruang & Tempat Tidur: pohon lokasi (lipat, cari kode), panel rinci,
        tambah/ubah/tutup dengan tanggal berlaku, impor CSV dengan pratinjau.
     2. Ringkasan per bangsal: jumlah TT per kelas, ekstra, ditutup; peringatan kapasitas kamar.
Uji penerimaan:
  - Impor 3 gedung × 8 lantai × 30 ruang × 4 TT dari CSV berhasil; CSV dengan siklus/duplikat ditolak per baris.
  - Menutup TT dengan berlaku_dari besok: hari ini masih dihitung, besok tidak (kapasitas harian).
  - Kode TT yang ditutup tidak bisa dipakai untuk TT baru.
  - Tablet 1024 px: pohon dan panel rinci berdampingan tanpa gulir horizontal halaman.
Aturan rinci (kontrak v0.5.0; FR-54-11…14 dan FR-08-16…20 ada di PRD sejak v0.10.11; rincian yang dipakai kode):
  - Izin: sub-menu yang sudah ada `master_data.organisasi` (PRD 9.1 "Organisasi, Unit, Ruang & Tempat Tidur", satu
    halaman; labelnya disamakan dengan PRD) untuk lokasi, bangsal, kamar, TT, kelas rawat, poliklinik, kapasitas. Semua
    dengan cakupan `rs`. Pemisahan pemilik data ruang & TT (FR-54-01) nanti lewat izin tingkat bagian, bukan sub-menu baru.
  - Bangsal tanpa `kelas_layanan` (PRD 11.2): kelas ditetapkan per TT. Arti kolom itu ditanyakan ke pemilik (utang).
  - Impor CSV sinkron, paling banyak 5.000 baris / 2 MB per berkas.
  - Jenis anak: akar → situs|gedung; situs → gedung; gedung → sayap|lantai; sayap → lantai; lantai → ruang;
    ruang → tempat_tidur. Kode segmen `^[A-Z0-9]{1,10}$`, `kode_lengkap` = gabungan dengan "-", unik di seluruh RS.
  - Kamar = ruang (lokasi jenis ruang) yang dimasukkan ke bangsal; satu ruang paling banyak satu kamar.
  - TT: node lokasi jenis tempat_tidur dibuat lewat /tempat-tidur; kode TT = kode_lengkap saat dibuat, tidak berubah
    walau ruangnya dipindah, unik selamanya. `berlaku_sampai` = hari terakhir dihitung; tutup = permanen (tutup
    sementara/buka kembali lewat 1.8c). Status operasional diturunkan dari tanggal.
  - Status lokasi berlaku segera (tanggal ≤ hari ini); penjadwalan ke depan lewat 1.8c.
  - Kapasitas: rekaman hari T dibuat 00.05 zona RS dan diperbarui setiap perubahan TT sepanjang hari T, lalu beku;
    tanggal lampau dibaca dari rekaman, hari ini/mendatang dihitung dari masa berlaku TT.
  - Kelas rawat, kode Aplicares, dan kode poli BPJS diisi RS; tidak ada data bawaan.
  - `satusehat_lokasi_id` hanya baca (diisi integrasi 1.9).
Di luar lingkup tiket: peta TT dan penempatan pasien (fase 2), denah gambar (FR-54-13, S → fase 2), sinkron Aplicares (1.9).
```

## [SIMRS] Master Data · 1.8c Usulan perubahan data induk berjenjang

```
Tujuan     : Setelah instalasi melayani pasien, perubahan data induk penting (TT, nanti tarif, formularium) lewat usulan
             → verifikasi → persetujuan → berlaku pada tanggalnya, dengan versi dan jejak.
FR         : PRD FR-54-01, FR-54-02, FR-08-16; prototipe masterdata.html bagian Usulan (U-0405 dsb.)
API (be)   : POST /usulan-data-induk (jenis_data, objek, nilai_lama, nilai_baru, berlaku_dari, alasan, dasar),
             GET /usulan-data-induk (filter tahap), POST .../{id}/verifikasi | setujui | kembalikan (catatan wajib),
             POST .../{id}/kirim-ulang; GET /usulan-data-induk/{id} (rincian, layar dan tautan tugas);
             GET /data-induk/{jenis}/{id}/riwayat-versi
Pekerjaan  :
  be 1. Mesin generik: alur per jenis data induk di konfigurasi (pemilik, pemverifikasi, penyetuju — peran);
        pengusul tidak boleh memverifikasi/menyetujui usulannya sendiri.
     2. Penerapan otomatis pada berlaku_dari pukul 00.00 waktu RS (tugas terjadwal, idempoten); versi lama tersimpan.
     3. Jenis pertama: ruang dan TT (tambah/kurangi/tutup) memakai aturan 1.8b. Setelah status instalasi
        "melayani", perubahan TT langsung dari 1.8b ditolak (409 harus_lewat_usulan) kecuali status darurat lonjakan
        (TT ekstra) yang tetap tercatat.
     4. Notifikasi ke pemverifikasi/penyetuju lewat 1.7.
  fe 1. Layar Master Data → Usulan perubahan (seperti prototipe, tanpa tombol "Peragaan"): daftar, rinci dengan
        perbedaan lama → baru, alur bertahap, kembalikan dengan catatan, riwayat versi.
Uji penerimaan:
  - Usulan ICU 12 → 16 TT disetujui untuk tanggal besok: berlaku otomatis besok, kapasitas harian berubah besok.
  - Pengusul mencoba menyetujui → 403. Dikembalikan → pengusul memperbaiki dan kirim ulang.
  - Penerapan terjadwal yang dijalankan dua kali tidak menggandakan perubahan.
Aturan rinci (kontrak v0.9.0):
  - Satu usulan = satu `jenis_data` (fase 1: `ruang_tt`) dengan 1–50 butir `perubahan` {objek_jenis, aksi, objek_id,
    nilai_baru}: TT tambah/ubah/tutup, kamar tambah/ubah, ruang tambah/ubah (induk, kode, nama, fungsi, unit, status).
    Isi tiap putaran disimpan (`inti.usulan_data_induk_putaran`); ringkasan dipotret saat diusulkan. Tanggal: uji kering
    memakai `berlaku_dari` sebagai hari acuan; tanggal efektif penerapan = max(berlaku_dari, hari ini). Objek terkunci
    hanya oleh usulan tahap diusulkan/diverifikasi/disetujui. Semua butir satu `berlaku_dari` dan diterapkan dalam satu
    transaksi. `nilai_lama` dan revisi objek dipotret server saat diusulkan.
  - Validasi = aturan 1.8b yang sama, dijalankan sebagai uji kering (transaksi dibatalkan) saat diusulkan/dikirim ulang,
    dan sungguhan saat diterapkan. Objek yang revisinya berubah sejak diusulkan → `gagal_diterapkan` (alasan tercatat,
    pengusul diberi tahu). Satu objek hanya boleh ada di satu usulan yang sedang berjalan (tahap diusulkan, diverifikasi,
    disetujui; usulan dikembalikan tidak mengunci) (409 `objek_sedang_diusulkan`).
  - Izin sub-menu `master_data.usulan` (aksi lihat, input, verifikasi, setujui; aksi tambahan verifikasi/setujui
    dipasang MenuBawaan). Alur per jenis data di `inti.alur_usulan` (peran pengusul/pemilik data, pemverifikasi, penyetuju; boleh kosong =
    siapa pun pemegang izinnya; data awal kosong, perlu verifikasi RS; layar konfigurasi alur = utang). Pengusul ≠
    pemverifikasi ≠ penyetuju.
  - Penerapan: tugas terjadwal 00.00 zona RS (idempoten: kunci baris + cek tahap), dan langsung saat disetujui bila
    `berlaku_dari` ≤ hari ini. Pelaku perubahan data induk = penyetuju; dasar = nomor usulan + dasar usulan.
  - Versi: `inti.data_induk_versi` (objek_jenis, objek_id, versi, sebelum, sesudah, berlaku_dari, usulan_id, dasar)
    diisi setiap perubahan ruang/kamar/TT, lewat usulan maupun langsung (1.8b).
  - Penjaga `instalasi.siap_melayani` (antarmuka; 1.8f mengisinya, sampai itu selalu false): sesudah siap melayani,
    POST/PATCH /lokasi jenis ruang, POST /lokasi/impor, POST/PATCH /kamar, POST/PATCH /tempat-tidur dan tutupnya →
    409 `harus_lewat_usulan`, kecuali tambah dan tutup TT `ekstra` (darurat lonjakan). PATCH TT tetap 409 juga untuk TT
    ekstra (mengubah `ekstra` = mengubah kapasitas resmi).
  - Tahap `berlaku` dan `gagal_diterapkan` final. Diff tampil dari `tampilan[]` yang disusun server (label isian dan
    nilai siap tampil, mis. nama kelas/bangsal/unit), bukan isi kanonik TTE.
  - Tugas 1.7 jenis USULAN_DATA_INDUK (tautan `/master-data/usulan?usulan={id}`): pelaksana tahap diusulkan = peran pemverifikasi alur (atau peran pemegang
    `master_data.usulan.verifikasi`), tahap diverifikasi = penyetuju, dikembalikan/gagal = pengusul; tugas tahap
    sebelumnya selesai saat tahap berganti.
  - TTE (1.6): objek `usulan_data_induk`, ditandatangani pengusul/pemverifikasi/penyetuju putaran itu. Isi kanonik = isi
    putaran itu: nomor, jenis_data, putaran, perubahan (objek_jenis, aksi, objek_id, nilai_lama, nilai_baru; tanpa
    label dan tanpa id objek hasil tambah), berlaku_dari, alasan, dasar, pengusul. Dedup tanda
    tangan per isi (unik ttd.tanda_tangan menjadi objek + pengguna + hash_isi), agar kirim ulang bisa ditandatangani lagi.
  - Utang (tinjauan #63): daftar usulan menghitung `dapat`/`objek_berubah`/`tampilan` per baris (banyak query pada
    batas besar; muat sekaligus per halaman); bila alur tanpa peran, tugas verifikasi ikut terkirim ke peran pengusul;
    `dapat.tandatangani` membaca ttd.tanda_tangan langsung (antarmuka modul Ttd menyusul).
  - Utang: tutup sementara/buka kembali TT (periode penutupan + perhitungan kapasitas) belum ada; usulan pembatalan
    oleh pengusul; layar konfigurasi alur; objek yang dibuat di usulan yang sama tidak bisa dirujuk butir berikutnya
    (mis. kamar baru + TT di kamar itu = dua usulan).
Di luar lingkup tiket: jenis data tarif/formularium (fase 2–3, memakai mesin ini).
```

## [SIMRS] SDM · 1.8d Pegawai, izin praktik, akun dari pegawai

```
Tujuan     : Setiap akun milik pegawai yang tercatat, dan syarat profesi (STR/SIP/SPK/RKK) tersedia untuk lapis 5
             hak akses dan pengingat.
FR         : PRD 11.2 sdm.pegawai, izin_praktik, kewenangan_klinis; FR-54-08 langkah 5; 9.2.6; keputusan K5
API (be)   : CRUD /pegawai (paginasi; NIK disimpan terenkripsi, ditampilkan dengan masking kecuali izin khusus),
             CRUD /pegawai/{id}/izin-praktik (unggah dokumen), CRUD /pegawai/{id}/kewenangan-klinis (minimal: daftar
             kode tindakan + masa berlaku), POST /pegawai/{id}/akun (buat akun: nama pengguna, sandi awal sekali
             tampil atau tautan aktivasi; wajib ganti sandi saat masuk pertama), POST /akun/{id}/nonaktifkan
             dan /aktifkan (alasan wajib)
Pekerjaan  :
  be 1. Tabel dan endpoint; kepala unit (unit_organisasi.kepala_pegawai_id) untuk eskalasi 1.7.
     2. Pegawai berstatus keluar → akun nonaktif otomatis dan sesi diputus (9.2.6); tercatat audit.
     3. Ganti sandi (fitur pertama): aturan sandi PRD 14.2 + cek sandi bocor + cek kontekstual (utang ADR 0001:
        memuat nama pengguna, nama RS, atau nama produk → ditolak).
     4. Akun sintetis tetap dari seeder, kini dengan pegawai sintetis lengkap.
  fe 1. Layar SDM → Pegawai (daftar, rinci, izin praktik dengan status berlaku/akan habis/habis), tombol "Buat akun".
     2. Layar ganti sandi (dipaksa saat masuk pertama) dan dari menu akun.
     3. Di Pengaturan → Akun: tombol Nonaktifkan/Aktifkan dengan dialog alasan.
Uji penerimaan:
  - Membuat akun dari pegawai → masuk pertama dipaksa ganti sandi; sandi berisi nama pengguna ditolak.
  - Pegawai diubah menjadi keluar → akun nonaktif, sesi berjalan diputus di permintaan berikutnya.
  - NIK tidak pernah muncul utuh di daftar maupun log.
Aturan rinci (kontrak v0.6.0):
  - Izin: `sdm.pegawai.*` (data pegawai), `sdm.kredensial.*` (izin praktik, RKK), `pengaturan.akun.*` (buat akun,
    nonaktif/aktif); cakupan `rs`. NIK utuh hanya dengan izin bagian baru `sdm.pegawai.identitas.lihat` (sensitif,
    diberikan lewat persetujuan pemilik data K3); tanpa itu hanya `nik_samaran` (4 digit awal dan akhir). NIK terenkripsi,
    keunikan lewat sidik (HMAC), tidak bisa dicari lewat URL, tidak masuk log/audit; pembacaan NIK utuh diaudit.
  - Status pegawai diturunkan dari `tanggal_keluar` (aktif | akan_keluar | keluar). Keluar → akun nonaktif + sesi diputus
    pada tanggalnya (langsung atau tugas harian), audit.
  - Izin praktik dan RKK tidak diubah setelah dicatat: perbaikan = batalkan lalu catat ulang. Dokumen privat ≤ 5 MB
    (PDF/JPEG/PNG, jenis dari isi berkas). Ambang "akan habis" dari konfigurasi (angka awal 60 hari, contoh FR-40-01; perlu verifikasi).
    Kode tindakan RKK diisi RS (bentuk saja yang diperiksa; tanpa daftar bawaan).
  - Akun dari pegawai: sandi awal acak ditampilkan sekali, wajib ganti saat masuk pertama. Selama wajib ganti, endpoint
    lain 403 `wajib_ganti_sandi` (status masuk di PRD 10.3.2 tidak berubah; `Saya.wajib_ganti_sandi`). Tautan aktivasi
    lewat email menunggu 1.7.
  - Akun pemegang superadmin tidak bisa dinonaktifkan, diaktifkan, atau pegawainya dicatat keluar lewat jalur SDM/akun:
    409 `superadmin_aktif` (akhiri penugasan superadmin lewat K4 dulu); tugas harian menunda keluar pegawai seperti itu
    dan mencatat audit. `tanggal_keluar` = hari pertama tidak bekerja.
  - Daftar pegawai: isi `akun` hanya untuk pemegang `pengaturan.akun.lihat` (selain itu `punya_akun` saja).
  - Kepala unit / ketua KSM ditetapkan lewat PATCH /unit-organisasi dan /ksm (`kepala_pegawai_id`, `ketua_pegawai_id`).
  - Utang kontrak (tinjauan #58, sesi 2): deskripsi GET /pegawai "klien mengurutkan tampilan" diganti "Klien menampilkan
    urutan server (terbaru dicatat dulu) dan memakai `cari`/filter; tidak mengurutkan ulang antarhalaman" di PR kontrak
    berikutnya (penyelarasan deskripsi, bentuk tidak berubah).
Di luar lingkup tiket: jadwal dinas, kredensial lengkap komite (fase 4), peserta didik (fase 5).
```

## [SIMRS] Master Data · 1.8e Pemuat paket referensi

```
Tujuan     : Instalasi baru bisa memuat data rujukan nasional dengan sumber dan versi yang jelas, tanpa menghapus kode lama.
FR         : PRD FR-54-09 (tabel paket), keputusan K7
Pekerjaan  : 1. inti.paket_referensi + perintah `php artisan referensi:muat <paket> <berkas>` dan endpoint unggah untuk
                wizard; tiap paket punya pemeta kolom dan validasi.
             2. Paket dikemas: wilayah, UCUM, subset LOINC (atribusi Regenstrief di layar Tentang), kode variabel RME.
                Sumber dan versi ditulis di berkas SUMBER.md per paket; versi terbaru perlu dicek saat rilis.
             3. ICD-10/ICD-9-CM: hanya pemuat dari berkas yang disediakan RS; tidak ada berkas ICD di repo.
             4. Pembaruan versi: kode yang hilang ditandai tidak aktif, tetap terbaca di data historis.
Uji penerimaan: memuat ulang versi sama idempoten; versi baru menonaktifkan kode yang hilang; berkas rusak ditolak
             dengan nomor baris.
Aturan rinci (kontrak v0.11.0):
  - Endpoint unggah wizard: POST /paket-referensi/{sistem}/muat (multipart: berkas, mode pratinjau|terapkan, versi,
    sumber, catatan_lisensi), izin `master_data.katalog.input`; perintah konsol `referensi:muat` dengan aturan sama.
  - Versi = kunci per sistem (sidik isi): versi sama + isi sama + versi itu pemuatan terakhir sistem tersebut =
    idempoten (200, jumlah perubahan 0, tanpa baris/audit baru; pratinjau `diterapkan` false). Isi lain, atau versi lama
    yang sudah digantikan versi lain = 409 `versi_sudah_dimuat`. Referensi: tiap berkas punya versinya sendiri. LOINC
    wajib `catatan_lisensi` (atribusi).
  - Tabel PRD 11.2: `inti.paket_referensi` (satu baris per sistem + versi), `inti.wilayah` (tingkat dan induk dari kode
    Kemendagri bertitik), `inti.referensi` (kelompok, kode, label), `inti.terminologi` (sistem, kode, tampilan,
    kode_induk); ketiganya ditambah `aktif`, `versi_terakhir`, `dinonaktifkan_pada`. Kode tidak pernah dihapus.
  - Satu pemuatan = snapshot satu sistem (referensi: per kelompok di berkas). Sinkron, paling besar 10 MB / 120.000
    baris, target < 30 detik untuk 100.000 baris (diuji), `SET LOCAL statement_timeout` sendiri (wilayah ±91 ribu
    kelurahan; diuji 90 ribu baris ±3 detik, kenaikan memori ±25–30 MB per pemuatan, anggaran tes 64 MB). Transaksi
    pemuatan memakai `SET LOCAL plan_cache_mode = force_custom_plan`: tanpa itu pemeriksaan FK induk wilayah per baris
    memakai rencana seq scan yang di-cache saat tabel kecil (kuadratik, >2 menit). Runbook: `upload_max_filesize`/
    `post_max_size` PHP ≥ 12 MB, `memory_limit` PHP ≥ 128 MB, nginx
    `client_max_body_size` ≥ 12m dan `proxy_read_timeout`/`fastcgi_read_timeout` ≥ 60 s (berkas diunggah dua kali:
    pratinjau lalu terapkan). Usul nanti: pratinjau mengembalikan token agar terapkan tidak perlu unggah ulang.
  - PRD 11.2 (kolom `aktif`, `versi_terakhir`, `dinonaktifkan_pada`, `sidik_isi`, nama sistem huruf kecil) dan rincian
    pemuat di 7.54: PRD v0.10.14.
  - **Isi paket dikemas menunggu berkas resmi** (pemilik/RS): wilayah (Kemendagri), UCUM (unitsofmeasure.org),
    subset LOINC (akun Regenstrief), kode variabel RME (Kemenkes). Repo memuat SUMBER.md per paket dan berkas contoh
    sintetis untuk tes; ICD-10/ICD-9-CM tidak pernah di repo (K7).
Di luar lingkup tiket: KFA dan referensi BPJS (ditarik lewat API di 1.9), SNOMED CT.
```

## [SIMRS] Platform · 1.8f Wizard penyiapan instalasi kosong

```
Tujuan     : Instalasi baru dipandu langkah demi langkah sampai siap, dan tidak bisa melayani pasien sebelum syarat lengkap.
FR         : PRD FR-54-08 (9 langkah), 3.4, keputusan K6
API (be)   : GET /penyiapan (status tiap langkah: belum/sebagian/selesai + apa yang kurang), POST /penyiapan/{langkah}/selesai
Pekerjaan  :
  be 1. Status penyiapan di inti.profil_rs; langkah 1 dicek dari jumlah superadmin ber-MFA (≥ 2), langkah 2 dari
        1.8a, langkah 3 dari 1.8a/1.8b, langkah 4 dari 1.8e, langkah 5 dari 1.8d, langkah 6–7 kerangka (K6), langkah 8
        dari 1.9 (uji koneksi atau ditandai "dilewati: belum ada kredensial"), langkah 9 modul aktif.
     2. Penjaga `instalasi.siap_melayani`: dipakai modul fase 2 untuk menolak transaksi pasien sebelum langkah 1–6 lengkap.
     3. Setiap langkah tercatat audit; bisa diulang.
     4. Langkah 4 (dari tinjauan kontrak 1.8e, PR #65): `GET /penyiapan` memuat daftar paket yang sudah dimuat per
        sistem (sistem, versi, sumber, dimuat_pada, jumlah kode aktif) dari `inti.paket_referensi`, supaya wizard
        menandai selesai per sistem dan pengguna tidak memuat ulang tanpa sadar. ICD-10/ICD-9-CM tidak wajib untuk
        menandai langkah 4 selesai (K7: berkasnya dari RS; tampil "menunggu berkas RS"). Peran penyiap instalasi
        perlu izin `master_data.katalog.input` walau sub-menu katalog belum TERSEDIA.
  fe 1. Layar Pengaturan → Penyiapan: daftar langkah dengan status dan tautan ke layar pengisinya (bukan formulir
        duplikat), indikator "Instalasi belum bisa melayani pasien" di Beranda superadmin.
Uji penerimaan: instalasi kosong menampilkan 9 langkah belum selesai; setelah data sintetis lengkap, langkah 1–5 dan 9
             selesai, 6–7 "menunggu fase 2", penjaga siap_melayani = false sampai 6 lengkap.
Aturan rinci (kontrak v0.12.0):
  - Status langkah dihitung dari data saat diminta (GET tidak menulis); `selesai` = syarat lengkap + dikonfirmasi
    (POST .../selesai, riwayat `inti.penyiapan_konfirmasi`, audit `pengaturan.penyiapan_dikonfirmasi`, bisa diulang).
    Data berkurang → `sebagian`, konfirmasi terakhir tetap tampil. Urutan wajib hanya untuk konfirmasi.
  - `siap_melayani` terkunci sejak langkah 1–6 pertama kali selesai (`inti.profil_rs.siap_melayani_sejak`), tidak
    kembali false. Konfirmasi langkah 2 mengisi `profil_rs.status_penyiapan = selesai` (arti 1.8a tetap: langkah 2).
  - Syarat per langkah di kontrak (blok /penyiapan). Langkah 8 hanya bisa dilewati dengan alasan sampai 1.9.
  - Utang: layar modul aktif (langkah 9 selalu terpenuhi di fase 1 karena modul inti aktif dari migrasi); peran
    `penyiap_instalasi` hanya memegang penyiapan dan katalog; izin data induk lain (organisasi, pegawai) ditambahkan RS
    karena superadmin tidak memegang data induk (PRD 9.2.4); panduan instalasi perlu menjelaskan penugasannya;
    syarat `referensi` belum memeriksa kelengkapan kelompok; PRD 11.2 belum memuat `inti.penyiapan_konfirmasi` dan
    `profil_rs.siap_melayani_sejak` (didokumentasikan sesudah merge).
  - Utang tinjauan #70 (tidak memblokir): syarat unit dan kelas rawat belum memeriksa `berlaku_dari` (kontrak: "≥ 1
    aktif"); tes penolakan cakupan unit (lapis 3) untuk /penyiapan belum ada.
```

## [SIMRS] Platform · 1.7 Notifikasi, kotak kerja, dan eskalasi

```
Tujuan     : Tugas yang tertinggal dikejar sistem (pengingat → teguran → eskalasi), dan setiap pengguna punya satu tempat
             untuk melihat apa yang harus ia kerjakan.
FR         : PRD 7.53 (FR-53-01…09), 14.2 (pemberitahuan akun terkunci, issue #11); keputusan K1, K2
Tabel      : notifikasi.notifikasi (partisi), tugas.jenis_tugas (tenggat, tingkat, penerima per tingkat, kritis),
             tugas.tugas (jenis, objek, pelaksana/unit, tenggat, status, tingkat_saat_ini), tugas.riwayat_tugas,
             notifikasi.preferensi
API (be)   : GET /saya/notifikasi (kursor, ETag; belum dibaca dulu), POST /saya/notifikasi/{id}/dibaca, POST .../semua-dibaca;
             GET /saya/tugas?tab=saya|unit|dieskalasi|selesai; GET /tugas/{id} (riwayat); CRUD /jenis-tugas (data induk);
             GET/PUT /saya/preferensi-notifikasi
Pekerjaan  :
  be 1. Mesin tugas: dibuat dari kejadian domain; tugas selesai otomatis saat kejadian penyelesai datang (bukan
        tombol manual); tautan langsung ke layar penyelesaian.
     2. Eskalasi terjadwal (queue `notifikasi`): tingkat 1 ke pelaksana sebelum tenggat, tingkat 2 ke kepala unit
        (1.8a/1.8d) saat lewat, tingkat 3 ke kepala bidang + penanda dasbor Direktur. Tenggat dan tingkat dari
        jenis_tugas (konfigurasi, FR-53-04).
     3. Jenis bawaan fase 1: akun terkunci (pemberitahuan ke pemilik akun — issue #11), persetujuan akses menunggu
        (1.2d), permintaan akses, usulan data induk menunggu (1.8c), SIP/STR berakhir 30 hari (1.8d). Jenis klinis
        FR-53-05 lainnya dibuat modulnya di fase 2+.
     4. Kanal: in-app (polling 30 dtk + ETag), email (Mailable, queue `notifikasi`), adapter WA/SMS driver `log`.
        Notifikasi kritis tidak bisa dimatikan preferensi. Isi notifikasi tanpa data pasien di email/SMS.
  fe 1. Lonceng di header (jumlah belum dibaca, daftar ringkas), halaman notifikasi.
     2. Kotak kerja di Beranda (komponen dari 1.2e) dengan tab Tugas saya / Unit saya / Sudah dieskalasi / Selesai
        bulan ini, setiap tugas berisi tenggat, tingkat, tombol ke layar penyelesaian; riwayat tugas.
     3. Layar data induk Jenis tugas & tenggat (Master Data). Preferensi notifikasi di menu akun.
     4. Ponsel < 768 px: kotak kerja ringkas dan notifikasi wajib berfungsi (PRD 5.5).
Uji penerimaan:
  - Akun terkunci → pemilik akun menerima notifikasi in-app dan email (tanpa sandi/IP lengkap di isi).
  - Tugas dengan tenggat 1 jam: pengingat, lalu teguran ke kepala unit setelah lewat, lalu eskalasi (waktu dimajukan).
  - Kejadian penyelesai menutup tugas otomatis; riwayat lengkap.
  - 10.000 notifikasi untuk satu akun: daftar tetap paginasi kursor, P95 < 200 ms; polling dengan ETag menjawab 304.
Aturan rinci (kontrak v0.7.0):
  - Tabel mengikuti PRD 11.2: `inti.jenis_tugas`, `inti.tugas`, `inti.tugas_kejadian` (bukan skema `tugas` seperti tertulis
    di atas), `notifikasi.notifikasi` (partisi bulanan) dan `notifikasi.preferensi`.
  - Layar Jenis tugas = sub-menu PRD 9.1 "Jenis Tugas, Tenggat & Eskalasi" (`master_data.jenis_tugas`), cakupan `rs`.
    Tidak ada POST: jenis dipasang migrasi modul pembuatnya; RS mengubah tenggat, waktu eskalasi, kritis, aktif.
  - Kotak kerja dan notifikasi (`/saya/*`) tanpa izin khusus. Tab saling lepas (saya / dieskalasi / unit / selesai).
    Eskalasi tingkat 2 ke kepala unit tugas (izin praktik: unit pegawai) atau `unit_teguran` jenisnya; tingkat 3 ke kepala
    `unit_eskalasi` jenisnya atau unit induk dari unit tingkat 2. Jenis berpelaksana peran (persetujuan, permintaan akses)
    tanpa penerima teguran sampai RS mengisi `unit_teguran` — perlu disebut di wizard 1.8f dan keterangan layar Jenis tugas.
  - Jenis bawaan fase 1 (angka awal = data, diubah di layar; perlu verifikasi kebijakan RS):
    PERSETUJUAN_AKSES_MENUNGGU (pelaksana peran penyetuju; tenggat 2 hari; selesai saat diputuskan/kedaluwarsa/dibatalkan),
    PERMINTAAN_AKSES (pelaksana peran pemegang pengaturan.akun.perbaiki; tenggat 2 hari; selesai saat ditindaklanjuti/ditolak),
    IZIN_PRAKTIK_BERAKHIR (dibuat 30 hari sebelum berakhir — FR-53-05; pelaksana akun pegawai itu; tenggat = tanggal
    berakhir; selesai saat izin pengganti dicatat), dan pemberitahuan AKUN_TERKUNCI (notifikasi saja, kritis, ke pemilik
    akun — issue #11). USULAN_DATA_INDUK dipasang 1.8c. Angka awal eskalasi semua jenis: pengingat 4 jam sebelum tenggat
    (izin praktik: 7 hari), teguran saat tenggat lewat, eskalasi 1 hari sesudah tenggat; unit teguran/eskalasi kosong
    (diisi RS di layar).
  - `notifikasi.notifikasi` berpartisi bulanan seperti audit, tetapi bukan append-only: `dibaca_pada` di-UPDATE.
  - Polling memakai header `X-Permintaan-Latar: 1` yang tidak memperbarui waktu aktif sesi (SesiPengguna).
  - Utang fase 2: tugas yang memuat pasien wajib lolos AccessEvaluator lapis 4 sebelum tampil di kotak kerja.
  - Utang (tinjauan #53): satu objek dengan beberapa peran pelaksana yang dipegang akun yang sama (mis. dua peran pemegang
    pengaturan.akun.perbaiki) terhitung ganda di lencana/tab; pengaju yang memegang peran penyetuju ikut menerima tugas
    persetujuannya sendiri; pesan singkat masih sinkron di transaksi; eskalasi yang tertunda karena scheduler mati
    terkirim sekaligus; seeder sintetis tugas/notifikasi untuk e2e.
Di luar lingkup tiket: WebSocket/Reverb (fase 2), push mobile, kinerja tindak lanjut unit FR-53-08 (fase 4 dasbor).
```

## [SIMRS] TTE · 1.6 Tanda tangan elektronik T1

```
Tujuan     : Setiap pengesahan di SIMRS ditandatangani oleh orangnya sendiri dengan cara yang memenuhi enam syarat
             PP 71/2019 Ps. 59 ayat 3, dan bisa diverifikasi kapan saja.
FR         : PRD 7.57.1–7.57.2, FR-57-01…03, FR-57-05; 11.2 ttd.*; keputusan K10
API (be)   : POST /saya/ttd/aktivasi (buat pasangan kunci + PIN), PUT /saya/ttd/pin (ganti), POST /ttd/{pengguna}/reset
             (diajukan SDM, disetujui atasan lewat 1.2d; lihat aturan rinci), POST /ttd/tandatangani (satu atau banyak objek; isi kanonik
             dihitung server, bukan dikirim klien), GET /ttd/verifikasi/{objek_jenis}/{objek_id}
Pekerjaan  :
  be 1. Ed25519 (ext-sodium); kunci privat dibungkus kunci turunan PIN (Argon2id + garam per pengguna); PIN hanya
        hash. Tidak ada jalur admin untuk menandatangani atas nama orang lain (uji negatif).
     2. Isi kanonik JSON (urutan kunci tetap) → SHA-256 → tanda tangan; disimpan dengan cap waktu server (NTP).
     3. PIN: 6 digit, tolak berurutan/berulang; 5 salah → kunci 15 menit + notifikasi ke atasan (1.7).
        Sesi tanda tangan 15 menit (konfigurasi), setiap objek tetap tercatat terpisah.
     4. Reset PIN membuat pasangan kunci baru; tanda tangan lama tetap terverifikasi dengan kunci publik lama.
     5. Aturan tingkat tanda tangan per jenis dokumen (FR-57-05) sebagai data induk; fase 1 hanya T1 aktif.
     6. Antarmuka `PenyediaTte` + `SimulatorTte` (T2) untuk uji alur asinkron (menunggu → ditandatangani via webhook
        tiruan); tidak ada penyedia nyata.
     7. Objek pertama yang memakai TTE: persetujuan akses (1.2d) dan usulan data induk (1.8c).
  fe 1. Aktivasi TTE (menu akun): buat PIN dua kali, penjelasan singkat arti tanda tangan.
     2. `TtdModal` di design-system (dari prototipe common.js yang sudah diperbaiki: menampilkan ringkasan isi dan
        identitas penanda tangan = akun yang masuk, kalimat persetujuan, PIN, Esc/fokus terjebak); mode massal
        dengan daftar objek. Penanda tangan ≠ akun masuk → mode "minta tanda tangan", tidak bisa menandatangani.
     3. Badge "Ditandatangani <nama>, <waktu> · terverifikasi" dengan tombol verifikasi ulang.
Uji penerimaan:
  - Isi diubah setelah ditandatangani → verifikasi gagal dan ditandai.
  - Admin dengan akses DB tidak bisa membuat tanda tangan valid tanpa PIN (uji: kunci terbungkus tidak bisa dibuka).
  - 5 PIN salah → terkunci, atasan menerima notifikasi.
  - Tanda tangan massal 12 objek = 12 baris ttd.tanda_tangan dengan hash masing-masing.
Aturan rinci (kontrak v0.8.0):
  - Reset TTE (PRD 7.57.2 huruf b, "oleh atasan + SDM"): petugas SDM (`sdm.kredensial.perbaiki`) mengajukan sesudah
    memverifikasi identitas pemilik (dicatat di `dasar`), atasan langsung menyetujui lewat alur 1.2d. Atasan = akun aktif
    kepala unit pegawai itu (`inti.unit_organisasi.kepala_pegawai_id`); bila kepala itu pemilik sendiri, pengaju, atau
    tanpa akun aktif → naik ke kepala unit induk, terus sampai unit akar. Dua orang berbeda: pengaju ≠ atasan ≠ pemilik.
    Tidak ada atasan sampai unit akar (mis. Direktur, atau pegawai tanpa unit) → 422 `atasan_belum_ditetapkan`.
    **Perlu keputusan pemilik produk:** penyetuju reset TTE pejabat puncak (Direktur): mis. Dewan Pengawas/pemilik RS
    berakun, atau verifikasi tatap muka oleh dua superadmin. Sampai diputuskan, TTE Direktur tidak bisa direset lewat
    sistem (aktivasi baru tidak terhalang). Ekstensi 1.2d: `persetujuan_akses.penyetuju_pengguna_id` (penyetuju orang tertentu,
    `penyetuju_peran_id` boleh kosong), jenis/objek `reset_ttd`, kedaluwarsa 7 hari seperti persetujuan lain.
  - Disetujui → kredensial lama berstatus `dicabut` (kunci publiknya tetap untuk verifikasi tanda tangan lama), pemilik
    mengaktifkan ulang sendiri (pasangan kunci + PIN baru). Tidak ada yang menetapkan PIN untuk orang lain.
  - Isi kanonik per jenis objek dihitung penyedia isi (antarmuka `PenyediaIsiTtd`) hanya dari kolom tersimpan yang tidak
    berubah sesudah objek final, bukan dari label yang disusun saat dibaca. Fase 1 `persetujuan_akses`: id, nomor, jenis,
    objek_jenis, objek (jsonb), peran_id, izin_id, sasaran_pengguna_id, penyetuju_peran_id, penyetuju_pengguna_id,
    diajukan_oleh, alasan, dasar, status, diputuskan_oleh, diputuskan_pada, catatan_keputusan; ditandatangani oleh akun
    pemutusnya sesudah diputuskan. Menyetujui tidak mensyaratkan tanda tangan di fase 1 (layar memanggil tandatangani
    sesudah setujui/tolak); kewajiban per jenis dokumen mengikuti `ttd.aturan_tingkat` (FR-57-05) saat dokumen klinis
    hadir di fase 2.
  - Kunci server `TTD_KUNCI` (env/vault, 32 bita; daftar kunci lama `TTD_KUNCI_LAMA` untuk rotasi): lada HMAC untuk PIN
    sebelum Argon2id (hash PIN dan kunci pembungkus). Salinan DB tanpa kunci server tidak bisa menebak PIN 6 digit
    secara offline (uji penerimaan tambahan). Kunci server hilang = semua TTE perlu diaktifkan ulang (tanda tangan lama
    tetap terverifikasi karena verifikasi hanya butuh kunci publik).
  - Aktivasi mewajibkan sandi akun (dibatasi laju seperti ganti sandi).
  - 5 PIN salah → terkunci 15 menit (konfigurasi `ttd.*`) dan notifikasi kritis ke atasan (penerima sama dengan reset)
    serta pemilik; penghitung dipulihkan saat PIN benar. PIN salah di ganti PIN ikut dihitung. Reset diterapkan →
    notifikasi ke pemilik.
  - Sesi tanda tangan 15 menit terikat sesi login; berakhir saat keluar, ganti sandi, ganti PIN, terkunci, atau dicabut.
    Kunci privat sesi disimpan terbungkus kunci acak: bungkusan di sesi server, kunci acak di cookie HttpOnly.
  - be menyertakan untuk e2e: akun sintetis `contoh.ttd` dengan TTE aktif (PIN sintetis dari env
    `AKUN_SINTETIS_TTD_PIN`, CI `E2E_TTD_PIN`) dan akun sintetis tanpa TTE untuk alur aktivasi; env e2e `TTD_KUNCI_MENIT`
    pendek. Saat backend 1.6 merge, `Saya.ttd` dan `PersetujuanAkses.penyetuju_pengguna` masuk `required` di kontrak.
  - Utang: syarat MFA/perangkat terdaftar untuk peran sensitif (PRD 7.57.1 T1) mengikuti lapis 5 di 1.5 (K11);
    ETag/versi per objek bertanda tangan menyusul fase 2; frontend 1.2d menampilkan jenis `reset_ttd`; usulan
    `PersetujuanAkses.ditandatangani` (badge di daftar) dan status TTE akun di `Pegawai.akun` (tombol Ajukan reset);
    endpoint "akhiri sesi tanda tangan" untuk komputer bersama.
  - Utang (tinjauan #57): unique `ttd.tanda_tangan (objek_jenis, objek_id, pengguna_id)` perlu memuat `tingkat` saat T2
    hadir (orang yang sama menandatangani T1 lalu T2, PRD 7.57.3); verifikasi memuat kunci publik per baris (≤ 100).
  - Utang kontrak (tinjauan pemakai #57, untuk PR kontrak berikutnya): 503 `ttd_belum_dikonfigurasi` di /saya/ttd/aktivasi,
    /saya/ttd/pin, /ttd/tandatangani; deskripsi /saya/preferensi-notifikasi menyebut TTD_TERKUNCI (kritis) dan
    TTD_DICABUT; contoh /saya memuat `ttd` dan `wajib_ganti_sandi`. Opsional: aturan PIN diperiksa sebelum sandi di
    aktivasi; seeder memberi `contoh.ttd` unit berkepala dan satu persetujuan sintetis yang diputuskannya (e2e tanpa MFA).
  - `ttd.aturan_tingkat` (jenis dokumen → tingkat T1/T2/S/P) berisi data awal `persetujuan_akses → T1`, tanpa API di
    fase 1. `ttd.permintaan` dipakai SimulatorTte (T2) di uji; tanpa endpoint publik (K10).
Di luar lingkup tiket: PSrE nyata, segel elektronik RS, halaman verifikasi publik ber-QR, tanda tangan pasien di
tablet (fase 2 bersama dokumen dan persetujuan tindakan).
```

## [SIMRS] Akses · 1.5 Lapis 4–5 hak akses

```
Tujuan     : Data paling sensitif dan aksi klinis hanya terbuka bila syarat yang tidak bisa diberikan admin terpenuhi.
FR         : PRD 9.2.2 lapis 4–5, 9.2.5, FR-09-05, FR-09-14; 11.2 label_sensitivitas, pengguna_label; keputusan K11, K12
Pekerjaan  :
  be 1. Label sensitivitas (data induk) dan pengguna_label dengan persetujuan pejabat PDP lewat 1.2d; wajib berbatas waktu.
     2. AccessEvaluator lapis 4: antarmuka `PemeriksaLabel` (objek berlabel → akun punya izin label DAN relasi
        perawatan; relasi perawatan fase 1 = antarmuka yang selalu "tidak merawat" sampai fase 2). Respons API
        objek berlabel tanpa izin = penanda "Terbatas", bukan isi.
     3. Lapis 5: izin dengan butuh_kewenangan_klinis ditolak bila SIP/SPK tidak berlaku atau RKK tidak memuat kode
        tindakan (data 1.8d); izin dengan butuh_mfa meminta MFA ulang bila verifikasi terakhir > 15 menit (403
        mfa_ulang_diperlukan + alur verifikasi TOTP tanpa keluar). Lapis 5 mengalahkan semua pemberian.
     4. Pengingat SIP akan habis (jenis tugas 1.7) dan penguncian otomatis aksi klinis pada tanggal habis.
  fe 1. Dialog MFA ulang (dipakai klien API saat menerima mfa_ulang_diperlukan, lalu mengulang permintaan).
     2. Layar izin label di Pengaturan → Akun (tab baru) dan penanda "Terbatas" di komponen bersama.
Uji penerimaan:
  - Dokter dengan SIP habis kemarin → aksi klinis contoh (endpoint uji ber-izin butuh_kewenangan_klinis) 403
    izin_praktik_tidak_berlaku walau perannya memberi.
  - Aksi butuh_mfa 20 menit setelah MFA → diminta MFA ulang; setelah lolos, permintaan diulang otomatis.
  - Izin label tanpa persetujuan PDP tidak aktif.
Di luar lingkup tiket: akses darurat (K12), rotasi peserta didik (fase 5).
```

## [SIMRS] Integrasi · 1.9 Gateway integrasi dan simulator

```
Tujuan     : Semua kiriman ke BPJS/SATUSEHAT melewati satu gerbang yang tahan sistem luar mati, tercatat aman, dan
             siap disambungkan ke sandbox/produksi begitu kredensial RS tersedia.
FR         : PRD 10 (Integration Gateway + outbox), pola teknis wajib (adapter ExternalGateway, circuit breaker, retry
             eksponensial, idempotency key, outbox, log dengan masking, dasbor kesehatan, sandbox & mock),
             FR-49-01, 04, 05, 06, 07; FR-50-01 (baca saja), 03, 07, 08; 11.2 integrasi.*; keputusan K8, K9
Rujukan    : docs/integrasi/catatan-lapangan-bridging.md (perilaku nyata dan jebakan; ikuti bagian "Selisih dengan PRD")
API (be)   : GET /integrasi/sistem (status per sistem: lingkungan, kredensial terisi, circuit breaker, queue, error
             terakhir), PUT /integrasi/sistem/{kode}/kredensial (tulis saja; respons hanya 4 karakter terakhir),
             POST /integrasi/sistem/{kode}/uji-koneksi, GET /integrasi/kotak-keluar (kursor; filter status/tujuan),
             POST /integrasi/kotak-keluar/{id}/kirim-ulang, GET /integrasi/log (dengan masking)
Pekerjaan  :
  be 1. Outbox integrasi.kotak_keluar (partisi) + pekerja queue `integrasi`: idempotency key, retry eksponensial
        dengan batas, status tertahan bila data induk kurang (dengan penyebab dan pemilik unit, FR-49-04), kirim
        ulang otomatis setelah data induk diperbaiki (FR-49-05). Circuit breaker per sistem.
     2. Kredensial sesuai K9; lingkungan sandbox/produksi per sistem; perintah konsol untuk rotasi kunci.
     3. Klien BPJS: header cons-id/timestamp/signature/user_key, dekripsi AES-256-CBC + LZString dengan timestamp
        permintaan itu sendiri, parser metaData/metadata, kode 201 yang berarti sukses ditangani per endpoint.
        Endpoint baca: peserta (nik/nokartu), rujukan dengan fallback FKTP → RS, referensi (dengan cache), uji koneksi
        `referensi/propinsi`. Endpoint tulis (SEP, rujukan keluar, kontrol, SPRI) di fase 2 bersama pendaftaran.
     4. Klien SATUSEHAT: token OAuth2 di cache (TTL expires_in − 60 dtk, kunci per client+lingkungan), pencarian IHS
        Patient/Practitioner by NIK (hasil di cache tabel), registrasi Organization dan Location dari 1.8a/1.8b
        (physicalType sesuai jenis lokasi, partOf berjenjang; duplikat 20002 dianggap wajar), pencarian KFA.
        Bundle Encounter dsb. di fase 2.
     5. Aplicares: kejadian KapasitasTempatTidurBerubah (1.8b) → outbox → bed/update; ruang tanpa kode kelas
        Aplicares tertahan dengan penyebab.
     6. Simulator (server tiruan dalam repo, jalan di CI dan lokal) untuk BPJS dan SATUSEHAT yang meniru: enkripsi,
        bentuk respons, error, dan jebakan di catatan lapangan (path case-sensitive, metaData/metadata, 201 sukses,
        Location duplikat, token kedaluwarsa, 5xx dan timeout untuk menguji circuit breaker).
     7. Log integrasi.log_api: permintaan/respons di-masking (NIK, nama, no kartu, tanggal lahir), akses terbatas
        izin integrasi.log.lihat, masa simpan di konfigurasi. Tidak ada payload utuh di log aplikasi.
     8. Ubah catatan nomor 1 di bagian "Selisih dengan PRD" catatan lapangan agar merujuk keputusan K9.
  fe 1. Layar Integrasi dan Laporan → Konfigurasi & status (prototipe integrasi.html): kartu per sistem (status, lingkungan,
        circuit breaker, queue), formulir kredensial tulis-saja, tombol Uji koneksi, kotak keluar dengan filter dan kirim
        ulang, log dengan masking. Tanpa angka tiruan.
Uji penerimaan:
  - Semua uji klien lulus terhadap simulator di CI; simulator mati → circuit breaker terbuka, kiriman menumpuk di
    outbox, pekerja jenis lain tetap jalan; simulator hidup kembali → terkirim tanpa duplikat (idempotency).
  - Kredensial tidak pernah muncul di respons API, log, atau pesan error.
  - Location SATUSEHAT terdaftar berjenjang gedung → lantai → ruang dari data 1.8b (terhadap simulator).
  - Perubahan kapasitas TT menghasilkan kiriman Aplicares; ruang tanpa kode kelas tertahan dengan penyebab.
  - Dengan kredensial sandbox nyata (bila kelak tersedia): uji koneksi BPJS dan SATUSEHAT berhasil tanpa ubah kode.
Di luar lingkup tiket: SEP dan transaksi BPJS tulis, bundle FHIR klinis, E-Klaim, Antrean Online, Apotek Online,
i-Care (fase 2–3, memakai gateway ini).
```

## [SIMRS] Platform · 1.11 Design system

```
Tujuan     : Komponen yang dipakai berulang dibuat sekali, konsisten, dan dapat diakses, sebelum modul fase 2 menulis
             ratusan layar.
FR         : PRD 5.3 (token), 5.4 (komponen inti), 5.5, DESIGN.md; utang "komponen halaman masuk per komponen"
Pekerjaan  : 1. Komponen yang dibutuhkan fase 1–2 awal: DataTable (kursor "Muat lagi", kolom prioritas di tablet,
                virtual scroll), KpiCard (hanya dari data asli), PohonData (pohon lipat, keyboard), FormulirBertahap,
                Badge/Pil status, KeadaanKosong, NotifikasiToast, PencarianMenu (dari 1.2e) — sisanya dibuat saat
                modulnya membutuhkan.
             2. Pindahkan tombol/isian/pesan error halaman masuk ke design-system (utang 1.4b).
             3. Halaman katalog komponen hanya di mode pengembangan (tidak ikut build produksi), tiap komponen dengan
                contoh keadaan normal/kosong/error/memuat, dan uji axe.
Uji penerimaan: tidak ada HEX di komponen (lint), axe tanpa pelanggaran serius di katalog, unit test per komponen,
             halaman masuk tetap identik secara visual (tangkapan layar sebelum/sesudah).
Di luar lingkup tiket: PatientHeader/SafetyChips/GlobalPatientSearch (fase 2 bersama data pasien).
```

## [SIMRS] Platform · 1.12 Gerbang keluar Fase 1

```
Tujuan     : Bukti bahwa fondasi siap dipakai modul fase 2 dan dipasang di RS.
FR         : PRD 16 kriteria keluar fase 1, 14.1.1, 14.2, D.7; keputusan K8
Pekerjaan  :
  be 1. Uji beban k6 profil PRD 14.1.1 terhadap endpoint fase 1 (masuk, menu, daftar akun/pegawai/lokasi, notifikasi
        polling) di lingkungan lokal setara produksi kecil; laporkan P95/P99 dan titik jenuh.
     2. Uji beban penulisan audit (utang ADR 0003): N penulis bersamaan, laporkan latensi kunci rantai.
     3. Daftar periksa keamanan: OWASP ASVS L2 bagian autentikasi, sesi, kontrol akses, validasi, log — hasil per
        butir (lulus/tidak/n.a.) di docs/keamanan/asvs-fase1.md; temuan tinggi diperbaiki sebelum tiket selesai.
     4. Runbook instalasi on-premise (docs/operasional/): kebutuhan server, compose produksi, cron schedule:run,
        cadangan + pemulihan teruji (restore ke DB kosong), AUDIT_ANCHOR_DISK ke penyimpanan terpisah,
        AUTENTIKASI_MFA_JARINGAN_RS, superadmin pertama, wizard.
     5. Status semua tiket fase 1 di backlog. (PRD 16/16.1 sesuai K8 — simulator sebagai kriteria keluar fase 1; sandbox
        nyata, SUS ≥ 70, dan design partner sebagai syarat masuk pilot fase 2 — sudah disetujui pemilik di PRD v0.10.12.)
  fe 1. e2e menyeluruh semua layar fase 1 di 1440/1024/768/390 + axe; tangkapan layar ke docs/lampiran/fase1/.
Uji penerimaan: semua butir di atas terdokumentasi; tidak ada temuan keamanan tingkat tinggi terbuka; CI hijau.
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
