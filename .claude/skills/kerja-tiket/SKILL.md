---
name: kerja-tiket
description: Alur kerja tiket SIMRS — kerja paralel antar-worktree, kerja berkelanjutan (ambil tiket, tinjau, merge sendiri), DoD bertingkat, dan kebiasaan hemat token. Gunakan saat mengerjakan tiket backlog, membuka/meninjau/merge PR, atau menyiapkan worktree.
---

# Alur kerja tiket SIMRS

Ringkasan di CLAUDE.md menang bila bentrok dengan contoh skill mana pun. Sumber kebenaran: `docs/prd.md`.

## Hemat token (wajib)

1. **Dokumen besar dibaca per bagian, tidak pernah utuh.** `docs/prd.md`, `docs/api/openapi.yaml`, dan
   `docs/backlog-fase1.md` dibaca dengan `grep -n` dulu lalu `Read`/`sed -n` pada rentang baris yang perlu saja.
   Aturan ini juga berlaku untuk subagen peninjau (beri nomor baris/ruang lingkup, bukan "baca seluruh PRD").
2. **Commit + push WIP tiap ±30 menit dan sebelum menunggu CI.** Branch WIP boleh berisi pekerjaan setengah jadi
   (tandai di pesan commit); yang penting tidak ada perubahan tak ter-push yang bisa hilang bila sesi berakhir.
3. **PRD penyelarasan dikumpulkan sekali per fase**, bukan per tiket. Selama fase berjalan, aturan baru yang sudah
   disetujui ditulis dulu ke backlog/tiket; penyelarasan PRD menyusul dalam satu PR di akhir fase (atau gerbang fase).

## Kerja paralel

Beberapa sesi bisa bekerja bersamaan di mesin yang sama.

1. **Folder utama `SIMRS-USU` dipakai satu sesi saja.** Sesi lain memakai worktree sendiri:
   `git worktree add ../SIMRS-USU-<tiket> <branch>` (butuh `composer install`, `npm ci`, dan `.env` sendiri; jangan
   salin `.env` worktree lain). Di `.env` pakai nomor DB Valkey sendiri (`REDIS_DB`, `REDIS_CACHE_DB`) dan port
   `php artisan serve` sendiri.
2. **Database tes per worktree `simrs_test_<tiket>`**, dibuat dengan skrip CI:
   ```bash
   export PGHOST=127.0.0.1 PGPORT=5433 PGUSER=postgres PGPASSWORD=postgres
   psql -c "CREATE DATABASE simrs_test_<tiket> OWNER simrs_migrator"
   psql -d simrs_test_<tiket> -f docker/postgres/init/sql/02-database.sql
   DB_DATABASE_TES=simrs_test_<tiket> composer test
   ```
   Jangan memakai `simrs_test` (bawaan CI dan folder utama) atau DB tes worktree lain. Jangan `docker compose down -v`.
3. **Sebelum stash/checkout/reset: `git status` dan `git worktree list`**, lalu sentuh hanya berkas tiket sendiri.
   Jangan menyentuh worktree atau branch milik sesi lain.
4. **Node lokal mengikuti `.nvmrc` (24)**, sama dengan CI.
5. **Tiket selesai = bersih.** Setelah PR merge dan CI `main` hijau:
   - `git worktree remove <jalur>`, `git branch -D <branch>`, `git push origin --delete <branch>`;
   - `DROP DATABASE` tes/e2e tiket (`simrs_test_<tiket>`, `simrs_e2e_<tiket>`);
   - `flushdb` DB Valkey tiket di dua instans: `docker compose -p simrs-usu exec valkey valkey-cli -n <REDIS_DB> flushdb`,
     lalu `valkey-cache` dengan `-n <REDIS_CACHE_DB>`;
   - `docker compose -p <proyek> down` (tanpa `-v`) untuk stack tambahan;
   - laporan akhir memuat `git worktree list` dan daftar database tersisa.

## Kerja berkelanjutan

Sesi tidak berhenti menunggu manusia selama ada tiket yang bisa dikerjakan. Pemilik meninjau setelah merge (bisa
revert); proteksi cabang tidak aktif (GitHub Free), jadi aturan ini dijaga sesi sendiri.

1. **Ambil tiket sendiri.** Setelah tiket bersih, ambil tiket `docs/backlog-fase1.md` berstatus "Siap dikerjakan"
   dengan semua prasyarat Selesai. Klaim lewat PR (judul memuat nomor tiket). **Status backlog diubah sekali per
   tiket, di commit penutup PR** (ketentuan pemilik, 12 Okt 2026). Jalur: sesi 1 backend/dokumen, sesi 2 frontend;
   bila jalur sendiri kosong, ambil jalur lain atau tinjau PR.
2. **Merge sendiri** bila semua terpenuhi:
   - `gh pr checks <no> --watch` selesai, semua hijau;
   - tinjauan sesuai jenis PR, tertulis komentar PR berawalan "Tinjauan:" dengan hasil "Disetujui"; temuan diperbaiki
     lalu ditinjau ulang:
     - PR backend atau menyentuh keamanan (autentikasi, hak akses, audit, kriptografi): **subagen `peninjau`**
       (konteks bersih, tidak ikut menulis kode) atau sesi lain, terhadap uji penerimaan tiket dan DoD PRD D.7;
     - PR frontend: **sesi 1** (kecocokan kontrak + tes otomatis); subagen hanya bila menyentuh autentikasi/hak akses;
     - PR kontrak: sesi pemakai (frontend) dan subagen;
     - PR hanya dokumen atau backlog: **tanpa tinjauan**;
   - lalu `gh pr merge <no> --squash --delete-branch`, tunggu CI main hijau, bersih-bersih tiket.
3. **Pengecualian: tunggu pemilik** bila PR:
   - mengubah `docs/prd.md` dengan **aturan baru karangan sesi** (boleh merge sendiri: salinan md5-cocok dari luar repo,
     atau PRD yang **hanya mendokumentasikan kode yang sudah merge** setelah tinjauan subagen);
   - mengubah `docs/api/openapi.yaml` di luar endpoint tiket yang disetujui (endpoint dalam tiket boleh merge sendiri
     setelah ditinjau sesi pemakai + subagen);
   - melonggarkan aturan keamanan (MFA, hak akses, audit, masking log, rate limit), atau migrasi yang menghapus
     data/kolom berisi data;
   - menambah dependensi baru di luar yang disebut tiket, atau menaikkan versi mayor.
   Judul PR berawalan "[Perlu pemilik]"; **pisahkan** jadi PR kecil tersendiri, kode yang tak bergantung di-merge sendiri.
4. **Berhenti dan laporkan** bila: butuh keputusan produk di luar PRD/tiket; butuh data/kredensial pihak luar; CI gagal
   3 kali untuk sebab yang sama; atau tidak ada tiket Siap.
5. **Serah terima.** Tiap PR memuat: yang selesai, utang baru (juga ditulis ke backlog), langkah berikutnya.
6. **Sesi menganggur** meninjau PR sesi lain sebelum mengambil tiket baru.

## DoD bertingkat (ketentuan pemilik, 17 Okt 2026)

- **Tiket kecil** (≤ 1 hari, tanpa migrasi berisiko: tanpa hapus data/kolom berisi data, tanpa backfill besar) =
  **satu PR** berisi kontrak + backend + frontend. E2E di PR itu **hanya spec yang terkait** di **1440 px + axe**.
- **Tiket besar atau berisiko** tetap dipecah (kontrak → be/fe) seperti biasa.
- **E2E penuh 4 lebar** (1440/1024/768/390) + axe **tidak** dijalankan per PR. Dipindah ke **workflow terjadwal tiap
  malam** (`.github/workflows/e2e-nightly.yml`) dan **gerbang fase** (sebelum menutup fase). PR biasa cukup menjalankan
  spec terkaitnya.
- DoD lain PRD D.7 tetap: `composer test` hijau, `composer analyse` bersih, kontrak cocok, tanpa data pasien asli,
  tanpa HEX di komponen.

## Tinjauan subagen

Pakai agen `peninjau` (model sonnet, `.claude/agents/peninjau.md`) untuk tinjauan subagen. Beri: nomor PR/branch,
worktree, perintah diff (ruang lingkup, bukan "baca semua"), badan tiket + uji penerimaan, dan fokus (keamanan untuk PR
keamanan). Minta putusan "Disetujui"/"Perlu perbaikan". Perbaiki temuan, minta tinjau ulang, baru merge.
