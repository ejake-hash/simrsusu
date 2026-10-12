/** Gateway integrasi (kontrak v0.13.0, tiket 1.9). */
export type SistemIntegrasi = 'bpjs_vclaim' | 'aplicares' | 'satusehat'
export type LingkunganIntegrasi = 'sandbox' | 'produksi'
export type StatusKiriman = 'menunggu' | 'terkirim' | 'gagal' | 'tertahan'

export interface Pelaku {
  id: string
  nama: string
}
export interface KolomKredensial {
  nama: string
  rahasia: boolean
  wajib: boolean
  terisi: boolean
  nilai: string | null
  akhir: string | null
}
export interface KredensialLingkungan {
  lingkungan: LingkunganIntegrasi
  aktif: boolean
  lengkap: boolean
  kolom: KolomKredensial[]
  diubah_pada: string | null
  diubah_oleh: Pelaku | null
}
export interface HasilUjiKoneksi {
  lingkungan: LingkunganIntegrasi
  berhasil: boolean
  kode_http: number | null
  latensi_ms: number | null
  pesan: string
  pada: string
}
export interface StatusSistemIntegrasi {
  kode: SistemIntegrasi
  nama: string
  lingkungan_aktif: LingkunganIntegrasi | null
  kredensial: KredensialLingkungan[] | null
  peringatan: { kode: string; pesan: string }[]
  circuit_breaker: { status: 'tertutup' | 'terbuka' | 'setengah_terbuka'; gagal_beruntun: number; sejak: string | null; coba_lagi_pada: string | null }
  queue: { menunggu: number; tertahan: number; gagal: number; terkirim_24_jam: number; menunggu_tertua_pada: string | null }
  error_terakhir: { pesan: string; pada: string } | null
  uji_koneksi_terakhir: HasilUjiKoneksi | null
}
export interface Kiriman {
  id: string
  tujuan: SistemIntegrasi
  lingkungan: LingkunganIntegrasi | null
  jenis_resource: string
  objek_jenis: string
  objek_id: string
  status: StatusKiriman
  percobaan: number
  coba_lagi_pada: string | null
  terkirim_pada: string | null
  error_terakhir: string | null
  penyebab_tertahan: { kode: string; pesan: string } | null
  pemilik_unit: { id: string; kode: string; nama: string } | null
  id_luar: string | null
  dapat_kirim_ulang: boolean
  dibuat_pada: string
}
export interface LogIntegrasi {
  id: string
  tujuan: SistemIntegrasi
  lingkungan: LingkunganIntegrasi
  metode: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  endpoint: string
  kode_http: number | null
  berhasil: boolean
  latensi_ms: number | null
  pesan: string | null
  kotak_keluar_id: string | null
  permintaan: string | null
  respons: string | null
  terpotong: boolean
  terjadi_pada: string
}
export interface Halaman<T> {
  data: T[]
  meta: { kursor_berikutnya: string | null }
}
export interface FilterKiriman {
  tujuan?: SistemIntegrasi
  status?: StatusKiriman
  lingkungan?: LingkunganIntegrasi
  dari?: string
  sampai?: string
}
export interface FilterLog {
  tujuan?: SistemIntegrasi
  berhasil?: boolean
  kotak_keluar_id?: string
  dari?: string
  sampai?: string
}
