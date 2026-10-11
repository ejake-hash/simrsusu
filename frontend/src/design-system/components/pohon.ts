/** Simpul PohonData: `anak` kosong/tidak ada = daun. */
export interface SimpulPohon {
  id: string
  label: string
  /** Teks kecil di samping label, mis. kode atau jenis. */
  keterangan?: string
  anak?: SimpulPohon[]
}
