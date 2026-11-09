import type { NamaIkon } from '@/design-system/ikon/ikon'
import { jamWib } from '@/modules/antrean/antrean'
import type { KategoriTriase, Kunjungan } from '@/modules/pendaftaran/tipeKunjungan'

/**
 * Label, ikon, dan urutan tampil kategori triase (FR-03-02, tiket 2.24). Status tidak hanya warna (PRD 5.1 butir 4):
 * setiap kategori selalu tampil dengan teks dan ikon berbentuk berbeda. Definisi klinis kategori milik data induk RS,
 * bukan kode ini.
 */
export const KATEGORI_TRIASE: readonly KategoriTriase[] = ['merah', 'kuning', 'hijau', 'hitam']

export const LABEL_KATEGORI: Record<KategoriTriase, string> = {
  merah: 'Merah',
  kuning: 'Kuning',
  hijau: 'Hijau',
  hitam: 'Hitam',
}

/**
 * Bentuk ikon berbeda per kategori agar tetap terbedakan tanpa warna. Hijau memakai lingkaran netral, bukan centang:
 * centang di produk ini berarti "berhasil".
 */
export const IKON_KATEGORI: Record<KategoriTriase, NamaIkon> = {
  merah: 'kritis',
  kuning: 'peringatan',
  hijau: 'umum',
  hitam: 'tutup',
}

/**
 * Kategori triase berlaku dari baris daftar IGD: `undefined` = field tidak dikirim server (akun tanpa igd.triase.lihat
 * cakupan rs), `null` = belum ditriase.
 */
export function kategoriBerlaku(k: Kunjungan): KategoriTriase | null | undefined {
  return k.triase === undefined ? undefined : (k.triase?.kategori ?? null)
}

/** "Pukul 10.03 WIB", ditambah tanggal (zona RS) bila bukan hari ini. */
export function waktuTriase(iso: string, sekarang: Date): string {
  const tanggal = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(d)
  const jam = jamWib(iso)
  if (tanggal(new Date(iso)) === tanggal(sekarang)) return `Pukul ${jam}`
  const tgl = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' }).format(new Date(iso))
  return `${tgl}, pukul ${jam}`
}

export const KUNCI_TRIASE = {
  riwayat: (kunjunganId: string) => ['triase', 'riwayat', kunjunganId] as const,
  instrumen: ['triase', 'instrumen'] as const,
}
