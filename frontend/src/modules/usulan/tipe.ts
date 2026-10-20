import type { MetaKursor, Pelaku } from '@/modules/akses/tipePengaturan'

/** Tipe usulan perubahan data induk berjenjang (docs/api/openapi.yaml v0.9.0, tiket 1.8c; jenis `tarif` v0.18.0, tiket 2.2). */

export type JenisDataInduk = 'ruang_tt' | 'tarif'
export type ObjekDataInduk = 'ruang' | 'kamar' | 'tempat_tidur' | 'tarif'
export type AksiUsulan = 'tambah' | 'ubah' | 'tutup'
/** `berlaku` dan `gagal_diterapkan` final. */
export type TahapUsulan = 'diusulkan' | 'diverifikasi' | 'disetujui' | 'berlaku' | 'dikembalikan' | 'gagal_diterapkan'
export type FilterTahap = 'terbuka' | TahapUsulan

/** Satu baris diff siap tampil, disusun server (bukan isi kanonik TTE). */
export interface TampilanIsian {
  isian: string
  label_isian: string
  lama: string | null
  baru: string | null
}

export interface IsianButir {
  objek_jenis: ObjekDataInduk
  aksi: AksiUsulan
  /** Wajib untuk ubah/tutup; null untuk tambah. */
  objek_id: string | null
  nilai_baru: Record<string, unknown>
}

export interface ButirUsulan extends IsianButir {
  objek_label: string
  /** Objek (ubah/tutup) berubah sejak diusulkan; penerapan akan gagal. */
  objek_berubah: boolean
  nilai_lama: Record<string, unknown> | null
  tampilan: TampilanIsian[]
}

export interface RiwayatUsulan {
  aksi: 'diajukan' | 'diverifikasi' | 'disetujui' | 'dikembalikan' | 'dikirim_ulang' | 'diterapkan' | 'gagal_diterapkan'
  /** null = sistem (penerapan terjadwal). */
  oleh: Pelaku | null
  pada: string
  catatan: string | null
  putaran: number
}

export interface UsulanDataInduk {
  id: string
  nomor: string
  jenis_data: JenisDataInduk
  ringkasan: string
  perubahan: ButirUsulan[]
  berlaku_dari: string
  alasan: string
  dasar: string
  tahap: TahapUsulan
  putaran: number
  diusulkan_oleh: Pelaku | null
  diusulkan_pada: string
  diverifikasi_oleh: Pelaku | null
  disetujui_oleh: Pelaku | null
  gagal_alasan: string | null
  riwayat: RiwayatUsulan[]
  dapat: { verifikasi: boolean; setujui: boolean; kembalikan: boolean; kirim_ulang: boolean; tandatangani: boolean }
}

export interface HalamanUsulan {
  data: UsulanDataInduk[]
  meta: MetaKursor & { jumlah: Record<'diusulkan' | 'diverifikasi' | 'disetujui' | 'dikembalikan' | 'gagal_diterapkan', number> }
}

export interface IsianUsulan {
  jenis_data: JenisDataInduk
  perubahan: IsianButir[]
  berlaku_dari: string
  alasan: string
  dasar: string
}

export interface VersiDataInduk {
  id: string
  versi: number
  objek_jenis: ObjekDataInduk
  objek_id: string
  berlaku_dari: string
  sebelum: Record<string, unknown> | null
  sesudah: Record<string, unknown>
  tampilan: TampilanIsian[]
  usulan: { id: string; nomor: string } | null
  dasar: string
  diubah_oleh: Pelaku | null
  diubah_pada: string
}
