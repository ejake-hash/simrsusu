# DESIGN.md — Karsitara SIMRS

> Sumber nilai: **PRD 5.3** dan
> **`frontend/src/design-system/tokens.css`**. File ini **tidak** menyalin nilai warna agar
> tidak ada dua sumber token. Bila berbeda, PRD yang berlaku.
> Prioritas: keselamatan input, lalu kecepatan kerja petugas, lalu keterbacaan, baru estetika.

## Identitas
- Warna `--brand-*`, `--accent`, dan panel merek halaman masuk (`--panel-merek*`, `--penekanan-panel`, diturunkan dengan cek kontras di `frontend/src/app/tema.ts`) serta logo RS berasal dari paket identitas per instalasi (PRD 3.4); palet bawaan produk = palet pedoman identitas USU (keputusan pemilik 13 Okt 2026, tiket 1.15); logo, nama, motif, dan foto tetap dari paket identitas (demo/e2e: paket fiktif "RS Contoh Pendidikan", `docs/brand/contoh/`). Token status, triase, dan netral milik produk dan tidak diganti per RS.
- Logo produk **Karsitara** (sidebar, halaman masuk), logo **RS** dari paket identitas (kartu RS, kop dokumen), **Karya Sistem Nusantara** (kaki halaman masuk). Aturan: PRD 5.3.1.
- Logo produk di `docs/brand/`; aplikasi memakai versi web di `frontend/public/logo/`. Logo dan aset identitas RS nyata **tidak** disimpan di repo (repo publik).
- Warna utama bawaan hijau pedoman (`--brand-600`), aksen emas (`--accent`, hanya isian/garis), teks `--text`. Warna pedoman untuk kop/cetak: `--cetak-*` (bukan status di layar). Huruf **Public Sans**; kode/No. RM **JetBrains Mono**.

## Aturan token yang sering terlewat (PRD 5.3.2)
- Teks bernuansa aksen memakai `--accent-ink`, bukan `--accent`.
- Tepi input, select, tombol sekunder memakai **`--border-input`** (≥ 3:1), bukan `--border`/`--border-strong`.
- Warna triase (`--triase-*`) hanya di papan IGD dan triase; aksen tidak dipakai di layar IGD.
- Status tidak boleh hanya warna: selalu teks + ikon.
- Motif grafis paket identitas hanya di halaman masuk, layar antrean, dan cetak; tidak di layar kerja klinis.

## Pola layar klinis
- Wajib: PRD 5.1 butir 9–17 (identitas nama lengkap + No. RM + tanggal lahir di setiap daftar/pemilih/modal; `TtdModal` menampilkan pasien dan hanya untuk pemilik akun; konfirmasi aksi tak terbalikkan; Esc dan fokus modal).
- Form panjang dibagi per bagian; tombol simpan selalu terlihat.
- Semua form bisa dioperasikan penuh dengan keyboard (Tab/Enter), tanpa mouse.
- Nilai kritis (lab, tanda vital) memakai warna DAN ikon/teks.
- Identitas pasien (nama, No. RM, tanggal lahir/umur, penjamin, alergi) selalu tampil di header layar pasien (PRD 5.1 butir 7).
- Aksi yang tidak bisa dibatalkan memerlukan konfirmasi yang menyebut nama pasien; pengesahan memakai `TtdModal` (T1 PIN / T2 PSrE, PRD 7.57).
- Drawer untuk detail; modal hanya untuk konfirmasi tindakan berisiko (PRD 5.1 butir 6).
- Format Indonesia: `Rp 1.950.000`, `36,7 °C`, `2 Oktober 2026`, `10.30 WIB`.

## Komponen yang sudah ada (tiket 1.11)
Di `frontend/src/design-system/`; contoh semua keadaan (normal/kosong/error/memuat) di rute `/katalog` (hanya mode
pengembangan). Pakai ini sebelum membuat yang baru; warna hanya dari token (uji `tanpaHex.spec.ts`).
- Aksi dan pesan: `Tombol` (utama/kedua/bahaya/tautan, besar, penuh, sibuk), `Pesan` (error/info/peringatan/ok),
  `Lencana` (status berikon), `NotifikasiToast` + `tampilkanToast()` untuk hasil aksi yang tidak terlihat di tempat.
- Formulir: `KolomIsian` (label, petunjuk, error), kelas `.isian`/`.isian-besar`/`.isian-kode`, `IsianSandi`,
  `FormulirBertahap` (langkah dengan `aria-current="step"`), `Sakelar`.
- Data: `DataTable` (kursor "Muat lagi", kolom berprioritas, gulir virtual), `PohonData` (tree WAI-ARIA),
  `KpiCard` (hanya data asli; null = "—"), `KeadaanKosong`.
- Navigasi dan lapisan: `Dialog` (hanya konfirmasi tindakan berisiko), `PopoverPencarian` (combobox non-modal),
  `Tabs`, `NavigasiSubMenu`; di `frontend/src/layouts/`: `HalamanModul`, `HalamanIndukModul`, `PencarianMenu`, `PitaDemo`.

## Komponen bersama (PRD 5.4 dan Lampiran D.4)
- `AppShell`, `SidebarMenu`, `PermissionGate`/`v-can`, `TtdModal`, `CpptLinimasa`, `DataTable`, `PatientHeader`, `KpiCard`, `StatusBadge`. Dibuat sekali di `frontend/src/design-system/` atau `frontend/src/shared/`, tidak dibuat ulang per modul.
- Contoh perilaku ada di prototipe HTML di luar repo, `/Users/ejake/Documents/SIMRS Tipe B/html/` (tombol **Spek dev** di tiap halaman).
