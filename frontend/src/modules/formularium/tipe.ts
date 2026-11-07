/**
 * Bentuk respons katalog barang dan formularium (docs/api/openapi.yaml v0.23.0, tiket 2.11). Tanpa impor nilai agar
 * data tiruan bisa dipakai e2e (tsconfig.node).
 */
export type JenisBarang = 'obat' | 'bmhp' | 'reagen' | 'gizi' | 'umum' | 'implan'
export type GenerikPaten = 'generik' | 'paten'
export type JenisFormularium = 'fornas' | 'rs' | 'penjamin'
export type StatusAktif = 'aktif' | 'nonaktif'

export interface Barang {
  id: string
  kode: string
  nama: string
  jenis: JenisBarang
  generik_paten: GenerikPaten | null
  zat_aktif: string | null
  kekuatan: string | null
  sediaan: string | null
  satuan_terkecil: string
  kode_kfa: string | null
  kewaspadaan_tinggi: boolean
  narkotika: boolean
  lasa: boolean
  status: StatusAktif
  versi: string
  diubah_pada: string
}
export interface FilterBarang {
  cari?: string
  jenis?: JenisBarang
  status?: StatusAktif
  tanpa_kfa?: boolean
}
export interface IsianBarang {
  kode: string
  nama: string
  jenis: JenisBarang
  generik_paten: GenerikPaten | null
  zat_aktif: string | null
  kekuatan: string | null
  sediaan: string | null
  satuan_terkecil: string
  kode_kfa: string | null
  kewaspadaan_tinggi: boolean
  narkotika: boolean
  lasa: boolean
  dasar_perubahan: string
}
export type UbahBarang = Partial<Omit<IsianBarang, 'dasar_perubahan'>> & { status?: StatusAktif; dasar_perubahan: string }

export interface Formularium {
  id: string
  kode: string
  nama: string
  jenis: JenisFormularium
  penjamin: { id: string; kode: string; nama: string } | null
  berlaku_dari: string
  berlaku_sampai: string | null
  jumlah_item_aktif: number
  versi: string
  diubah_pada: string
}
export interface IsianFormularium {
  kode: string
  nama: string
  jenis: JenisFormularium
  penjamin_id?: string | null
  berlaku_dari: string
  berlaku_sampai: string | null
  dasar_perubahan: string
}
export type UbahFormularium = Partial<Pick<IsianFormularium, 'nama' | 'berlaku_dari' | 'berlaku_sampai'>> & { dasar_perubahan: string }

export interface ItemFormularium {
  id: string
  formularium_id: string
  barang: Pick<Barang, 'id' | 'kode' | 'nama' | 'generik_paten' | 'zat_aktif' | 'kekuatan' | 'sediaan' | 'satuan_terkecil' | 'status'>
  restriksi: string | null
  maks_per_resep: number | null
  kronis: boolean
  status: StatusAktif
  versi: string
  diubah_pada: string
}
export interface FilterItem {
  cari?: string
  status?: StatusAktif
}
export interface IsianItem {
  barang_id: string
  restriksi: string | null
  maks_per_resep: number | null
  kronis: boolean
  dasar_perubahan: string
}
export type UbahItem = Partial<Omit<IsianItem, 'barang_id' | 'dasar_perubahan'>> & { status?: StatusAktif; dasar_perubahan: string }

export interface KesalahanBarisImpor {
  baris: number
  kolom: string | null
  kode: string
  pesan: string
}
export interface HasilImpor {
  mode: 'pratinjau' | 'terapkan'
  diterapkan: boolean
  jumlah_baris: number
  jumlah_salah: number
  kesalahan: KesalahanBarisImpor[]
  kesalahan_terpotong: boolean
}
