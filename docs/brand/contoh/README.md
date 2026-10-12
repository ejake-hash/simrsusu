# Paket identitas contoh: RS Contoh Pendidikan (fiktif)

Identitas **fiktif dan netral** untuk demo, e2e, dan tangkapan layar PR (repo publik; PRD 3.4). Dibuat sendiri, tidak
meniru RS mana pun. RS nyata mengunggah paket identitasnya sendiri lewat Pengaturan → Profil rumah sakit; berkas
identitas RS nyata **tidak** disimpan di repo.

| Berkas | Isi |
|---|---|
| `logo-rs-contoh.svg` | Logo (unggah sebagai logo paket identitas) |
| `motif-contoh.svg` | Motif latar (unggah sebagai motif) |

| Warna | HEX | Catatan |
|---|---|---|
| Utama (`brand`) | `#1F5C99` | teks putih di atasnya 6,9:1; skala `--brand-*` diturunkan `frontend/src/app/tema.ts` |
| Aksen (`accent`) | `#D97706` | isian dan garis saja; teks bernuansa aksen memakai `--accent-ink` |
| Panel merek | `#14385A` | halaman masuk; teks putih 12,0:1 |
| Penekanan di panel | `#FFD166` | 8,4:1 pada panel merek |

Warna utama dan aksen ini juga palet bawaan produk (`frontend/src/design-system/tokens.css`,
`App\Core\ProfilRs\ProfilRs::WARNA_BAWAAN`) sampai RS mengunggah paket identitasnya.
