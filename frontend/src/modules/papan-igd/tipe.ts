/**
 * Bentuk /data-induk/area-igd dan /data-induk/aturan-papan-igd (docs/api/openapi.yaml v0.34.0, tiket 2.25). Tanpa impor
 * nilai agar dipakai data tiruan.
 */
export type StatusAreaIgd = 'aktif' | 'nonaktif'

export interface AreaIgdDataInduk {
  id: string
  kode: string
  nama: string
  /** Urut tampil di papan (kecil dulu). */
  urutan: number
  status: StatusAreaIgd
  versi: string
  diubah_pada: string
}
export interface IsianAreaIgd {
  kode: string
  nama: string
  urutan?: number
  dasar_perubahan: string
}
export interface UbahAreaIgd {
  kode?: string
  nama?: string
  urutan?: number
  status?: StatusAreaIgd
  dasar_perubahan: string
}

/** Satu set per RS. `peringatan_lama_igd_menit` null = tanda mendekati 6 jam tidak dipakai. */
export interface AturanPapanIgd {
  batas_tanggap_menit: number
  peringatan_lama_igd_menit: number | null
  versi: string
  diubah_pada: string
}
export interface UbahAturanPapanIgd {
  batas_tanggap_menit?: number
  peringatan_lama_igd_menit?: number | null
  dasar_perubahan: string
}
