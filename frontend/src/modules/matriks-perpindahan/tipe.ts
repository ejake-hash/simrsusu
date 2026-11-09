/** Bentuk /data-induk/matriks-perpindahan (docs/api/openapi.yaml v0.33.0, tiket 2.26). Tanpa impor nilai agar dipakai data tiruan. */

/** Baris tabel PRD 7.00.3. */
export type PosAsalPerpindahan = 'pendaftaran' | 'poliklinik' | 'igd' | 'penunjang' | 'kamar_operasi' | 'icu_hcu' | 'rawat_inap' | 'farmasi' | 'kasir'
/** Kolom tabel PRD 7.00.3, ditambah `aps` dan `doa` dari FR-03-09. */
export type PosTujuanPerpindahan = 'poli_lain' | 'penunjang' | 'igd' | 'kamar_operasi' | 'icu_hcu' | 'rawat_inap' | 'rujuk_keluar' | 'pulang' | 'meninggal' | 'aps' | 'doa'
export type StatusAturan = 'aktif' | 'nonaktif'

/** Satu sel matriks perpindahan (data induk RS). */
export interface AturanPerpindahan {
  id: string
  pos_asal: PosAsalPerpindahan
  pos_tujuan: PosTujuanPerpindahan
  /** Kode izin yang wajib dipegang pemberi perintah (cakupan rs). */
  izin_perintah: string
  keterangan: string | null
  status: StatusAturan
  versi: string
  diubah_pada: string
}

export interface FilterMatriks {
  pos_asal?: PosAsalPerpindahan
  status?: StatusAturan
}
