---
name: kerja-tiket
description: Alur kerja tiket SIMRS — worktree (skrip siapkan/bersihkan, Korlap), kerja berkelanjutan (ambil tiket, tinjau, merge sendiri), format tiket dan laporan, DoD bertingkat, antislop, graphify, dan kebiasaan hemat token. Gunakan saat mengerjakan tiket backlog, membuka/meninjau/merge PR, atau menyiapkan worktree.
---

# Alur kerja tiket SIMRS

Ringkasan di CLAUDE.md menang bila bentrok dengan skill mana pun. Sumber kebenaran: `docs/prd.md`.
Tiket aktif: `docs/backlog-fase2.md`. Arsip: `docs/backlog-fase1.md`, `docs/arsip/`.

## 1. Hemat token (wajib)

1. **Navigasi graphify dulu.** `graphify query "<pertanyaan>"`, `graphify explain <simbol>`, `graphify path A B
   --undirected`. Bila tak menjawab: `grep -n` lalu rentang baris. Dokumen besar (`docs/prd.md`,
   `docs/api/openapi.yaml`, backlog) **tidak pernah dibaca utuh** — juga oleh subagen.
2. **Tanpa rencana panjang.** Tiket S langsung dikerjakan. Tiket M boleh rencana ≤ 8 butir, tanpa paragraf.
   Tidak menulis ulang isi tiket/PRD di PR atau chat; cukup rujuk nomornya.
3. **Commit + push WIP tiap ±30 menit dan sebelum menunggu CI** (pesan berawalan `WIP:`).
4. **PRD diselaraskan sekali per fase.** Aturan baru yang sudah disetujui ditulis dulu ke backlog.
5. **Laporan akhir tepat 5 baris** (di chat dan sebagai komentar penutup PR):
   ```
   PR: #<no> <judul>
   CI: hijau | merah (<job>) | menunggu
   Berubah: <≤ 1 kalimat>
   Asumsi/menyimpang: <- atau daftar singkat>
   Berikutnya: <tiket/aksi>
   ```
6. CLAUDE.md dijaga ≤ 85 baris; rincian masuk ke skill ini.

## 2. Format tiket

Format PRD Lampiran D.6 ditambah tiga kolom: **Ukuran** (S ≤ ~300 baris diff, M ≤ ~800; lebih besar dipecah
sebelum mulai), **Jalur** (`BE`, `FE`, `BE+FE`), **Prasyarat**. Uji penerimaan ≤ 5 butir Given/When/Then. Latar
belakang = nomor bagian PRD, bukan salinan. Tiket dibuat perencana (pemilik + penyusun PRD), bukan oleh sesi.

## 3. Worktree

1. **Folder utama `SIMRS-USU`**: satu sesi saja; agen tidak pernah pull/checkout/reset di sana.
2. **Worktree baru** (sesi VS Code atau kartu Korlap): dari dalam worktree jalankan **`scripts/worktree-siapkan.sh`**
   sebelum langkah lain. Skrip menyalin `.env` dari folder utama dan membuat unik per worktree: port API `8000+n`,
   Vite `5173+n`, DB dev `simrs_<slug>`, DB tes `simrs_test_<slug>`, `REDIS_DB`/`REDIS_CACHE_DB`, `SESSION_COOKIE`,
   `SANCTUM_STATEFUL_DOMAINS`; lalu `composer install`, `npm ci`, migrasi, seed. Variabel ada di `.worktree.env`:
   ```bash
   source .worktree.env && (cd backend && composer test)
   source .worktree.env && (cd frontend && npx playwright test e2e/<spec> --project=desktop)
   ```
   Jangan memakai `simrs`/`simrs_test` (milik folder utama dan CI). Jangan `docker compose down -v`.
3. **Graf per worktree:** setelah siapkan, `graphify update .` (±5 detik, tanpa model) bila `graphify` terpasang.
   Hook git (`post-commit` dkk.) dipakai bersama semua worktree; jalankan dengan `GRAPHIFY_BACKEND=none`. Bila
   `graphify hook install` diulang, tambahkan lagi `export GRAPHIFY_BACKEND=none` sesudah `PYTHONHASHSEED=0` di keempat
   skrip hook. Setelah PRD berubah (sekali per fase): `/graphify docs/prd.md --update`.
4. **Sebelum stash/checkout/reset: `git status` dan `git worktree list`.** Jangan menyentuh worktree/branch lain.
5. **Tiket selesai = bersih.** Setelah PR merge dan CI `main` hijau, dari folder utama:
   `scripts/worktree-bersihkan.sh <jalur-worktree>` (hapus DB dev/tes/e2e, flush Valkey, worktree, branch lokal;
   menolak bila PR belum merge). Laporan akhir memuat `git worktree list`.
6. **Browser manual dua worktree sekaligus:** cookie `XSRF-TOKEN` tidak dibedakan per port; pakai profil browser
   terpisah. Playwright tidak terpengaruh (konteks terisolasi).

## 4. Paralel BE/FE dan Korlap

- **Paralel hanya setelah PR kontrak merge.** Jalur `BE+FE`: agen BE membuka PR kontrak (`docs/api/openapi.yaml`,
  hanya endpoint tiket) → tinjau → merge → kartu BE dan kartu FE jalan bersamaan. FE memakai data tiruan dari kontrak
  sampai BE merge, lalu satu commit integrasi.
- **Paling banyak 2 agen bersamaan** (satu BE, satu FE). Lebih dari itu kuota habis lebih cepat daripada hasilnya.
- **Korlap** (aplikasi kanban macOS, alpha) hanya pengatur kartu dan worktree. Aturan:
  1. Isi kartu = blok tiket dari backlog apa adanya + baris `Jalur: BE` atau `Jalur: FE`. Backlog tetap sumber status.
  2. Langkah pertama setiap agen: `scripts/worktree-siapkan.sh` (Korlap tidak punya hook penyiapan).
  3. Kolom Review Korlap **tidak** menggantikan tinjauan di sini: merge tetap lewat `gh pr merge` setelah CI hijau +
     `peninjau`. Jangan pakai tinjauan Opus bawaan Korlap (biaya ganda).
  4. Kartu Done → bersihkan dengan `scripts/worktree-bersihkan.sh`; periksa juga `git worktree list` karena perilaku
     pembersihan Korlap belum terdokumentasi.
  5. Bila Korlap gagal (worktree/DB yatim, agen macet), kembali ke dua sesi VS Code dengan worktree biasa.

## 5. Kerja berkelanjutan

Sesi tidak berhenti menunggu manusia selama ada tiket yang bisa dikerjakan. Pemilik meninjau setelah merge (bisa
revert); proteksi cabang tidak aktif (GitHub Free), jadi aturan ini dijaga sesi sendiri.

1. **Ambil tiket sendiri** berstatus "Siap dikerjakan" dengan prasyarat Selesai, sesuai jalurnya; jalur sendiri
   kosong → tinjau PR atau ambil jalur lain. Klaim lewat PR (judul memuat nomor tiket). Status backlog diubah sekali,
   di commit penutup PR.
2. **Ambigu bukan aturan PRD** → pilih bawaan yang paling dekat dengan PRD, tulis `Asumsi:` di PR, lanjut.
3. **Merge sendiri** bila: `gh pr checks <no> --watch` hijau; komentar "Tinjauan: Disetujui" sesuai jenis PR:
   - backend, keamanan (autentikasi, hak akses, audit, kriptografi), kontrak → subagen `peninjau`;
   - frontend → `peninjau` fokus kecocokan kontrak, layar, dan antislop; keamanan bila menyentuh autentikasi/akses;
   - dokumen atau backlog → tanpa tinjauan.
   Lalu `gh pr merge <no> --squash --delete-branch`, tunggu CI main hijau, bersihkan worktree.
4. **Tunggu pemilik** (judul "[Perlu pemilik]", PR kecil tersendiri; kode yang tak bergantung tetap merge) bila PR:
   - mengubah `docs/prd.md` dengan aturan baru karangan sesi (boleh merge sendiri: salinan md5-cocok dari luar repo,
     atau PRD yang hanya mendokumentasikan kode yang sudah merge, setelah tinjauan subagen);
   - mengubah `docs/api/openapi.yaml` di luar endpoint tiket;
   - melonggarkan keamanan (MFA, hak akses, audit, masking log, rate limit) atau migrasi yang menghapus data;
   - menambah dependensi di luar yang disebut tiket, atau versi mayor.
5. **CI gagal 3× untuk sebab yang sama** → catat utang di backlog (sebab + job), tandai tiket "Terhambat", ambil
   tiket lain.
6. **Berhenti dan laporkan** hanya bila tidak ada tiket Siap, atau butuh data/kredensial pihak luar.

## 6. DoD bertingkat (ketentuan pemilik, 17 Okt 2026)

- **Tiket kecil** (S, tanpa migrasi berisiko) = **satu PR** berisi kontrak + backend + frontend. E2E di PR hanya spec
  terkait di **1440 px + axe**.
- **Tiket M atau berisiko**: PR kontrak, lalu PR be dan PR fe.
- **E2E penuh 4 lebar** (1440/1024/768/390) + axe di workflow malam (`.github/workflows/e2e-nightly.yml`) dan gerbang
  fase, bukan per PR.
- DoD lain PRD D.7 tetap: `composer test` hijau, `composer analyse` bersih, kontrak cocok, tanpa data pasien asli,
  tanpa HEX di komponen.

## 7. Antislop

Skill `antislop*` di `.claude/skills/` (pihak ketiga, MIT; deskripsinya disesuaikan agar tidak termuat di tiket BE).
- **Mode `during`**: pemilik menyetel sekali per komputer `npx antislop-ai --mode during`. Bila belum disetel dan
  skill menanyakan mode, pilih `during` sendiri (keputusan pemilik 19 Okt 2026), jangan menunggu jawaban.
- **Dipakai:** agen FE saat membuat/mengubah layar (`antislop`, `antislop-ui`, `antislop-human`;
  `antislop-layoutmobile` bila layar diuji di 768/390 px) dan Delivery Gate sebelum membuka PR; `antislop-code`
  sebelum membuka PR apa pun; `antislop-copywriting` untuk teks layar, deskripsi PR, dan dokumen.
- **Tidak dipakai:** tiket BE, kontrak, migrasi.
- Arah desain sudah ada (`DESIGN.md`, PRD 5.3, token) dan aset dari paket identitas, jadi R-23/R-37 tidak memicu
  pertanyaan. Mode `after` (audit bernomor menunggu persetujuan) tidak dipakai agen otomatis. Bentrok → PRD/CLAUDE.md.

## 8. Tinjauan subagen

Pakai `peninjau` (sonnet). Beri: nomor PR/branch, jalur worktree, perintah diff (ruang lingkup), badan tiket + uji
penerimaan, dan fokus. Putusan "Disetujui"/"Perlu perbaikan"; perbaiki temuan wajib, minta tinjau ulang, baru merge.
