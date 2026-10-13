import { readonly, ref } from 'vue'
import { ambilProfilRs } from '@/modules/organisasi/api'
import type { ProfilRsApi } from '@/modules/organisasi/tipe'
import { terapkanTema } from './tema'

/**
 * Profil dan paket identitas RS dari GET /profil-rs (publik terbatas, tiket 1.8a; PRD 3.4), menggantikan variabel
 * VITE_RS_*: nama, logo, motif, foto, kontak pengelola akun, tanda instalasi demo, dan warna merek (tema). Dimuat
 * sekali saat aplikasi mulai (halaman masuk ikut memakainya) dan diganti langsung sesudah profil disunting.
 * Nilai kosong = bagian itu tidak ditampilkan; build produksi tanpa variabel apa pun tetap bekerja.
 */
export interface ProfilRs {
  versi: string | null
  nama: string | null
  logo: string | null
  /** Motif grafis di panel merek halaman masuk (PRD 5.3.2 aturan 4); bukan untuk layar kerja. */
  motif: string | null
  foto: string | null
  /** Unit yang mengelola akun (teks halaman masuk dan Beranda); bawaan "admin SIMRS". */
  unitAdmin: string
  kontakAdmin: string | null
  /** FR-54-10: pita "DEMO — data fiktif" di setiap layar. */
  demo: boolean
  /** Teks panel merek halaman masuk (tiket 1.15); bawaan = HALAMAN_MASUK_BAWAAN. */
  halamanMasuk: HalamanMasuk
}

/**
 * Isi panel merek halaman masuk yang dapat diatur admin (tiket 1.15, Pengaturan → Profil rumah sakit → Halaman masuk).
 * Teks biasa saja; `judulSorot` ditempel di akhir judul dengan warna --penekanan-panel. Keterangan foto kosong = nama RS.
 */
export interface HalamanMasuk {
  lencana: string
  judul: string
  judulSorot: string | null
  uraian: string
  fitur: readonly { judul: string; uraian: string }[]
  fotoKeterangan: string | null
  tampilkanMotif: boolean
}

export const HALAMAN_MASUK_BAWAAN: HalamanMasuk = {
  lencana: 'Satu data untuk seluruh rumah sakit',
  judul: 'Pelayanan, pendapatan, dan mutu dalam',
  judulSorot: 'satu layar kerja.',
  uraian: 'Dari pendaftaran sampai klaim, setiap pencatatan langsung menjadi rekam medis, tagihan, dan laporan, tanpa entri ulang.',
  fitur: [
    { judul: 'Paperless', uraian: 'Pengesahan elektronik dengan PIN, catatan terkunci, perubahan jadi adendum.' },
    { judul: 'Terhubung', uraian: 'BPJS dan SATUSEHAT berjalan dari layar yang sama.' },
    { judul: 'Pendidikan', uraian: 'Akun peserta didik mengikuti masa rotasi dan supervisi.' },
  ],
  fotoKeterangan: null,
  tampilkanMotif: true,
}

const UNIT_ADMIN_BAWAAN = 'admin SIMRS'
export const PROFIL_BAWAAN: ProfilRs = {
  versi: null,
  nama: null,
  logo: null,
  motif: null,
  foto: null,
  unitAdmin: UNIT_ADMIN_BAWAAN,
  kontakAdmin: null,
  demo: false,
  halamanMasuk: HALAMAN_MASUK_BAWAAN,
}

const profil = ref<ProfilRs>({ ...PROFIL_BAWAAN })

export function useProfilRs() {
  return readonly(profil)
}

export function dariRespons(d: ProfilRsApi): ProfilRs {
  const isi = (v: string | null | undefined) => v?.trim() || null
  return {
    versi: d.versi,
    nama: isi(d.nama),
    logo: isi(d.paket_identitas.logo_url),
    motif: isi(d.paket_identitas.motif_url),
    foto: isi(d.paket_identitas.foto_url),
    unitAdmin: isi(d.kontak_admin.unit) ?? UNIT_ADMIN_BAWAAN,
    kontakAdmin: isi(d.kontak_admin.kontak),
    demo: d.instalasi_demo,
    // Diisi dari `halaman_masuk` GET /profil-rs setelah kontrak 1.15 merge.
    halamanMasuk: HALAMAN_MASUK_BAWAAN,
  }
}

/** Dipakai sesudah GET maupun sesudah PUT profil/berkas: satu sumber untuk seluruh layar. */
export function terapkanProfilRs(d: ProfilRsApi): void {
  profil.value = dariRespons(d)
  terapkanTema(d.paket_identitas.warna)
}

/** Gagal (server mati, instalasi belum siap) = tetap profil bawaan; layar masuk tetap bisa dipakai. */
export async function muatProfilRs(): Promise<void> {
  try {
    terapkanProfilRs((await ambilProfilRs()).data)
  } catch {
    // Profil bawaan sudah terpasang.
  }
}
