/**
 * Bentuk respons penjamin (docs/api/openapi.yaml v0.17.0, tiket 2.1). Tanpa impor nilai agar data tiruan bisa
 * dipakai e2e (tsconfig.node).
 */
export type JenisPenjamin = 'bpjs' | 'umum' | 'asuransi' | 'perusahaan' | 'jasa_raharja' | 'jamkesda' | 'sponsor_riset'
export type StatusPenjamin = 'aktif' | 'nonaktif'

export interface Penjamin {
  id: string
  kode: string
  nama: string
  jenis: JenisPenjamin
  status: StatusPenjamin
  /** Masa berlaku surat jaminan (GL) sejak terbit, hari; null = tidak dibatasi. */
  masa_berlaku_gl_hari: number | null
  /** Rupiah sebagai string desimal; null = tanpa plafon tetap. */
  plafon: string | null
  /** Jatuh tempo penagihan sejak tagihan terbit, hari. */
  jatuh_tempo_hari: number | null
  versi: string
  diubah_pada: string
}

export interface FilterPenjamin {
  cari?: string
  status?: StatusPenjamin
  jenis?: JenisPenjamin
}

export interface IsianPenjamin {
  kode: string
  nama: string
  jenis: JenisPenjamin
  masa_berlaku_gl_hari: number | null
  plafon: string | null
  jatuh_tempo_hari: number | null
  dasar_perubahan: string
}
export type UbahPenjamin = Partial<Omit<IsianPenjamin, 'dasar_perubahan'>> & { status?: StatusPenjamin; dasar_perubahan: string }
