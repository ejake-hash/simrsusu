/** Simpul PohonData: `anak` kosong/tidak ada = daun. */
export interface SimpulPohon {
  id: string
  label: string
  /** Teks kecil di samping label, mis. kode atau jenis. */
  keterangan?: string
  anak?: SimpulPohon[]
  /** Muat-lambat: simpul punya anak yang belum dimuat (`anak` belum diisi). Pemakai memuatnya saat simpul dibuka. */
  adaAnak?: boolean
  /** Anak sedang dimuat: aria-busy dan pemutar di tombol lipat. */
  memuat?: boolean
}

/** Simpul bisa dibuka: punya anak yang sudah dimuat atau ditandai `adaAnak`. */
export const bisaDibuka = (s: SimpulPohon) => !!s.anak?.length || !!s.adaAnak
