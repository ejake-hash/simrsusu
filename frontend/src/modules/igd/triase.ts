import type { NamaIkon } from '@/design-system/ikon/ikon'
import type { KategoriTriase } from '@/modules/pendaftaran/tipeKunjungan'

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

/** Bentuk ikon berbeda per kategori agar tetap terbedakan tanpa warna. */
export const IKON_KATEGORI: Record<KategoriTriase, NamaIkon> = {
  merah: 'kritis',
  kuning: 'peringatan',
  hijau: 'centang',
  hitam: 'tutup',
}

export const KUNCI_TRIASE = {
  riwayat: (kunjunganId: string) => ['triase', 'riwayat', kunjunganId] as const,
  instrumen: ['triase', 'instrumen'] as const,
}
