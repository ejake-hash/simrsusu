import type { SkalaTriase } from '@/modules/pendaftaran/tipeKunjungan'

/** Bentuk /data-induk/instrumen-triase (docs/api/openapi.yaml v0.32.0, tiket 2.24). Tanpa impor nilai agar dipakai data tiruan. */
export type StatusInstrumenTriase = 'aktif' | 'nonaktif'

export interface InstrumenTriaseDataInduk {
  id: string
  kode: string
  nama: string
  /** Urut tampil; [] = instrumen tanpa skala. */
  skala: SkalaTriase[]
  status: StatusInstrumenTriase
  versi: string
  diubah_pada: string
}
export interface FilterInstrumenTriase {
  cari?: string
  status?: StatusInstrumenTriase
}
export interface IsianInstrumenTriase {
  kode: string
  nama: string
  skala: SkalaTriase[]
  dasar_perubahan: string
}
export interface UbahInstrumenTriase {
  kode?: string
  nama?: string
  skala?: SkalaTriase[]
  status?: StatusInstrumenTriase
  dasar_perubahan: string
}
