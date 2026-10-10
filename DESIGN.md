# DESIGN.md — SIMRS RS Pendidikan USU

> Sumber nilai: **PRD 5.3** (identitas visual dari Pedoman Identitas USU) dan
> **`frontend/src/design-system/tokens.css`**. File ini **tidak** menyalin nilai warna agar
> tidak ada dua sumber token. Bila berbeda, PRD yang berlaku.
> Prioritas: keselamatan input, lalu kecepatan kerja petugas, lalu keterbacaan, baru estetika.

## Identitas
- Logo produk **Karsitara** (sidebar, halaman masuk), logo **RS USU** (kartu RS, kop dokumen), **Karya Sistem Nusantara** (kaki halaman masuk). Aturan: PRD 5.3.1.
- File asli di `docs/brand/`; aplikasi memakai versi web di `frontend/public/logo/`. Lambang USU **tidak** dipakai di aplikasi.
- Warna utama hijau USU (`--brand-600`), aksen emas (`--accent`, hanya isian/garis), teks `--text`. Huruf **Public Sans**; kode/No. RM **JetBrains Mono**.

## Aturan token yang sering terlewat (PRD 5.3.2)
- Teks bernuansa emas memakai `--accent-ink`, bukan `--accent`.
- Tepi input, select, tombol sekunder memakai **`--border-input`** (≥ 3:1), bukan `--border`/`--border-strong`.
- Warna triase (`--triase-*`) hanya di papan IGD dan triase; emas tidak dipakai di layar IGD.
- Status tidak boleh hanya warna: selalu teks + ikon.
- Motif grafis USU hanya di halaman masuk, layar antrean, dan cetak; tidak di layar kerja klinis.

## Pola layar klinis
- Form panjang dibagi per bagian; tombol simpan selalu terlihat.
- Semua form bisa dioperasikan penuh dengan keyboard (Tab/Enter), tanpa mouse.
- Nilai kritis (lab, tanda vital) memakai warna DAN ikon/teks.
- Identitas pasien (nama, No. RM, tanggal lahir/umur, penjamin, alergi) selalu tampil di header layar pasien (PRD 5.1 butir 7).
- Aksi yang tidak bisa dibatalkan memerlukan konfirmasi yang menyebut nama pasien; pengesahan memakai `TtdModal` (T1 PIN / T2 PSrE, PRD 7.57).
- Drawer untuk detail; modal hanya untuk konfirmasi tindakan berisiko (PRD 5.1 butir 6).
- Format Indonesia: `Rp 1.950.000`, `36,7 °C`, `2 Oktober 2026`, `10.30 WIB`.

## Komponen bersama (PRD 5.4 dan Lampiran D.4)
- `AppShell`, `SidebarMenu`, `PermissionGate`/`v-can`, `TtdModal`, `CpptLinimasa`, `DataTable`, `PatientHeader`, `KpiCard`, `StatusBadge`. Dibuat sekali di `frontend/src/design-system/` atau `frontend/src/shared/`, tidak dibuat ulang per modul.
- Contoh perilaku ada di prototipe `docs/html/` (tombol **Spek dev** di tiap halaman).
