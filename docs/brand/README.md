# Paket identitas RS

Nama, logo, motif, foto gedung, serta warna merek dan aksen tiap RS disimpan di `inti.profil_rs.paket_identitas`
(PRD 3.4), bukan di kode. Di instalasi sungguhan, RS mengisinya lewat **Pengaturan → Profil rumah sakit** (wizard
penyiapan langkah 2).

Repo ini **publik**: nama, berkas, dan warna RS nyata **tidak pernah** di-commit (CLAUDE.md, "Repo publik"). Demo, e2e,
dan tangkapan layar memakai identitas fiktif [`contoh/`](contoh/README.md) ("RS Contoh Pendidikan").

## Paket identitas untuk instalasi lokal/staging

Untuk instalasi lokal atau staging yang perlu tampil dengan identitas RS tertentu (dengan izin RS itu), letakkan paket di
folder **di luar repo**, lalu arahkan `PAKET_IDENTITAS_CONTOH_DIR` (`backend/.env`) ke folder itu:

```bash
PAKET_IDENTITAS_CONTOH_DIR=/jalur/di/luar/repo/paket-identitas
php artisan db:seed            # DatabaseSeeder memanggil PaketIdentitasContohSeeder di APP_ENV local/staging
```

Seeder memasang paket lewat layanan yang sama dengan layar Profil rumah sakit (`KelolaProfilRs`):

- warna diuji kontrasnya (`KontrasWarna`): putih di atas warna utama ≥ 4,5:1, dan teks gelap di atas warna aksen ≥ 4,5:1;
- berkas diperiksa dari isinya (`PemeriksaBerkas`): logo/motif SVG atau PNG (SVG disaring daftar izin), foto WebP/JPEG/PNG,
  dalam batas ukuran `IDENTITAS_BATAS_*_KB`;
- perubahan tercatat di jejak audit atas nama superadmin aktif pertama, dengan dasar "Paket identitas instalasi
  lokal/staging".

Bila `PAKET_IDENTITAS_CONTOH_DIR` kosong atau foldernya tidak ada, seeder dilewati dan identitas tetap netral. Semua isian
dan berkas diperiksa lebih dulu: bila paket ditolak (kontras kurang, berkas tidak sah, `profil.json` salah), `db:seed`
berhenti dengan pesan galat yang sama dengan layar Profil rumah sakit dan **profil tidak berubah sama sekali**. Isian dan
berkas yang sudah sama dengan profil tidak disimpan ulang, jadi menjalankan `db:seed` berulang tidak menambah revisi.
Pelaku di jejak audit adalah superadmin aktif pertama; di staging itu bisa akun orang sungguhan, sedangkan dasar
perubahannya menyebut seeder ini. Seeder ditolak di production.

### Format folder

```
paket-identitas/
├── profil.json
├── logo.svg          # atau .png
├── motif.svg         # opsional
└── gedung.webp       # opsional (.webp/.jpg/.png)
```

`profil.json` (contoh memakai identitas fiktif):

```json
{
  "nama": "RS Contoh Pendidikan",
  "warna": { "brand": "#1F5C99", "accent": "#D97706" },
  "kontak_admin": { "unit": "Instalasi SIMRS", "kontak": "ekstensi 1234" },
  "berkas": { "logo": "logo.svg", "motif": "motif.svg", "foto": "gedung.webp" }
}
```

| Kunci | Wajib | Isi |
|---|---|---|
| `nama` | ya | Nama RS, paling panjang 200 karakter |
| `warna.brand` | ya | Warna utama `#RRGGBB`; putih di atasnya ≥ 4,5:1 |
| `warna.accent` | ya | Warna aksen `#RRGGBB`; teks `#1D1D1B` di atasnya ≥ 4,5:1 |
| `kontak_admin` | tidak | `{ "unit", "kontak" }` untuk teks halaman masuk; bila tidak ada, nilai profil yang ada (atau "Instalasi SIMRS") dipakai |
| `berkas.logo` / `berkas.motif` / `berkas.foto` | tidak | Nama berkas di dalam folder yang sama (tidak boleh keluar folder) |

Panel merek halaman masuk dan skala warna `--brand-*` diturunkan otomatis dari `warna.brand` di frontend
(`frontend/src/app/tema.ts`), dengan cek kontras teks putih dan teks penekanan.
