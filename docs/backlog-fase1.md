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
| **1.1b** | MFA TOTP + kode cadangan, backend dan langkah MFA di halaman masuk | **Siap dikerjakan** (backend dan frontend; PR #10 sudah merge) | — |
| 1.0c | Nama modul bahasa Indonesia (backend dan frontend). Catatan: panel Spek dev di `docs/html` masih menyebut nama lama (di luar lingkup) | Selesai | #16 |
| 1.2 | Menu, peran, izin, `AccessEvaluator` lapis 1–3, `GET /api/v1/saya/menu` | Menunggu 1.1b | — |
| 1.5 | Lapis 4–5 hak akses (label sensitivitas, SIP/SPK, MFA aksi sensitif) | Menunggu 1.2 | — |
| 1.6 | TTE T1: aktivasi PIN, `TtdModal`, kunci Ed25519 | Menunggu 1.2 | — |
| 1.7 | Notifikasi dasar (PRD 7.53), jenis bawaan pertama: pemberitahuan akun terkunci | Menunggu 1.2 | — |
| 1.8 | Wizard penyiapan instalasi kosong dan paket referensi (FR-54-08…10) | Menunggu 1.2 | — |
| 1.10 | SSO (OIDC/SAML) ke penyedia identitas RS/universitas; tombol tampil hanya bila dikonfigurasi | **Terhambat**: perlu data IdP dari RS/USU | — |
| 1.9 | Gateway integrasi dasar + sandbox BPJS/SATUSEHAT (kriteria keluar fase 1) | Menunggu dokumen resmi | — |

Utang yang tercatat: pemberitahuan akun terkunci ke pemilik (PRD 14.2) → tiket 1.7, issue #11.

Branch `lampiran/pr-1.4b` berisi gambar yang dirujuk deskripsi PR #10; jangan dihapus selama gambar itu masih dibutuhkan.

Motif `docs/brand/motif-usu.svg` dibuat ulang (bukan berkas resmi pedoman); hanya untuk pengembangan sampai dicocokkan dengan `PATTERN DESIGN USU.ai` dari Humas/Promosi USU.

---

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
