# Backlog Fase 1 (Fondasi)

> Sumber tiket untuk semua sesi. Kebutuhan tetap di `docs/prd.md`; file ini hanya urutan, status, dan isi tiket yang belum jalan.
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
| 1.4b | Halaman masuk: penyelarasan visual dengan prototipe (motif, ilustrasi, label), cek sesi tahan server mati | Selesai | #10 |
| **1.0b** | Kebersihan repo: folder modul, Larastan, aturan worktree | **Siap dikerjakan** | — |
| **1.1b** | MFA TOTP + kode cadangan, backend dan langkah MFA di halaman masuk | **Siap dikerjakan** (1.4a sudah merge) | — |
| 1.2 | Menu, peran, izin, `AccessEvaluator` lapis 1–3, `GET /api/v1/saya/menu` | Menunggu 1.1b | — |
| 1.5 | Lapis 4–5 hak akses (label sensitivitas, SIP/SPK, MFA aksi sensitif) | Menunggu 1.2 | — |
| 1.6 | TTE T1: aktivasi PIN, `TtdModal`, kunci Ed25519 | Menunggu 1.2 | — |
| 1.7 | Notifikasi dasar (PRD 7.53), jenis bawaan pertama: pemberitahuan akun terkunci | Menunggu 1.2 | — |
| 1.8 | Wizard penyiapan instalasi kosong dan paket referensi (FR-54-08…10) | Menunggu 1.2 | — |
| 1.9 | Gateway integrasi dasar + sandbox BPJS/SATUSEHAT (kriteria keluar fase 1) | Menunggu dokumen resmi | — |

Utang yang tercatat: pemberitahuan akun terkunci ke pemilik (PRD 14.2) → tiket 1.7.

---

## [SIMRS] Platform · 1.0b Kebersihan repo

```
Tujuan     : Repo aman di-autoload di Linux, kode baru dicek analisis statis, dan semua sesi paralel
             mengikuti aturan worktree yang sama.
FR         : — (tiket teknis) · PRD 14 baris Pemeliharaan, D.7
Regulasi   : —
Akses      : —
Tabel      : —
API        : —
Pekerjaan  :
  1. Modul Registration, Queue, Emergency: Database/factories → Database/Factories,
     Database/seeders → Database/Seeders. Pakai dua langkah karena macOS tidak membedakan huruf:
     git mv factories factories_tmp && git mv factories_tmp Factories (sama untuk seeders).
     Sesuaikan namespace/PSR-4 bila ada.
  2. Pasang larastan/larastan (dev), phpstan.neon level 5, path app/ dan tests/.
     Baseline (phpstan-baseline.neon) hanya untuk temuan kode yang SUDAH ada saat ini.
     Tambah job/langkah CI "phpstan" di job backend.
  3. CLAUDE.md, bagian baru "Kerja paralel":
     - Folder utama SIMRS-USU dipakai satu sesi saja; sesi lain wajib worktree sendiri
       (git worktree add ../SIMRS-USU-<tiket> <branch>).
     - Setiap worktree backend memakai database tes sendiri (simrs_test_<tiket>), dibuat dengan
       docker/postgres/init/sql/02-database.sql (DBNAME=...) lalu migrate --database=pgsql_migrator.
       Jangan memakai simrs_test bila sesi lain sedang menjalankan tes.
     - Sebelum stash/checkout/reset: git status + git worktree list; sentuh hanya file tiket sendiri.
     - Node lokal mengikuti .nvmrc (24).
  4. composer.json: tambahkan skrip "analyse" (phpstan) agar bisa dijalankan lokal.
  5. Buat GitHub issue "Pemberitahuan akun terkunci ke pemilik (PRD 14.2)" dengan rujukan tiket 1.7
     (gh issue create).
Uji penerimaan:
  - composer dump-autoload --strict-psr tanpa peringatan.
  - CI backend menjalankan phpstan level 5 dan lulus dengan baseline.
  - Uji balik: tambahkan sementara satu pelanggaran (mis. pemanggilan method yang tidak ada) di
    kode baru → CI/lokal phpstan gagal; lalu hapus.
  - Baseline tidak memuat entri untuk file yang dibuat di tiket ini.
  - CLAUDE.md memuat bagian "Kerja paralel" dengan empat aturan di atas.
  - Issue GitHub ada dan tertaut ke tiket 1.7.
  - composer test lulus di database tes milik worktree ini.
Di luar lingkup tiket: memperbaiki temuan phpstan di kode lama (cukup baseline), menaikkan level di
atas 5, AutentikasiController, frontend, upgrade dependency.
```

## [SIMRS] Akses · 1.1b MFA (TOTP + kode cadangan)

```
Tujuan     : Superadmin, peran sensitif, dan akses dari luar jaringan RS wajib verifikasi dua langkah.
FR         : FR-54-07, FR-09-05, FR-09-06 · kontrak lengkap PRD 10.3.2
Regulasi   : UU 27/2022 PDP Ps. 4 (data spesifik) [High]; Permenkes 24/2022 Ps. 29 [High]
Akses      : — (sebelum 1.2: kolom akses.pengguna.wajib_mfa + CIDR jaringan RS di config)
Tabel      : akses.pengguna (mfa_rahasia_enkripsi, wajib_mfa, mfa_aktif_pada),
             akses.kode_cadangan_mfa (pengguna_id, kode_hash, dipakai_pada)
API        : POST /api/v1/autentikasi/masuk (bentuk respons baru {status,…}),
             POST /api/v1/autentikasi/mfa, POST /api/v1/autentikasi/mfa/daftar,
             POST /api/v1/autentikasi/mfa/daftar/konfirmasi
Uji penerimaan: semua aturan di PRD 10.3.2, minimal:
  - Akun wajib_mfa: masuk → perlu_mfa; /saya tetap 401 sampai kode benar.
  - Akun wajib_mfa tanpa rahasia: perlu_daftar_mfa → daftar → konfirmasi → 10 kode cadangan sekali tampil.
  - Kode salah dihitung ke penghitung gagal sandi; 5x → akun_terkunci + Retry-After.
  - Tantangan kedaluwarsa (5 menit), sekali pakai, dan ditolak dari sesi lain → tantangan_tidak_berlaku.
  - Kode TOTP yang sama ditolak bila dipakai dua kali dalam periodenya.
  - Akun tidak wajib MFA dari dalam jaringan RS: langsung {status:"masuk", pengguna}.
  - Akses dari luar CIDR RS: wajib MFA walau wajib_mfa=false.
  - Log dan audit tidak memuat kode, rahasia, maupun URI otpauth.
  - Frontend: komponen langkah perlu_mfa dan perlu_daftar_mfa menggantikan LangkahMfaBelumTersedia;
    e2e alur MFA dengan rahasia TOTP sintetis.
Di luar lingkup tiket: WebAuthn, MFA per aksi sensitif (tiket 1.5), reset MFA oleh admin (tiket 1.2).
```
