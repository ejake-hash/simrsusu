import type { MetaKursor } from '@/modules/akses/tipePengaturan'

/** Tipe notifikasi, kotak kerja, dan jenis tugas (docs/api/openapi.yaml v0.7.0, tiket 1.7). */

export type PrioritasNotifikasi = 'biasa' | 'penting' | 'kritis'

export interface Notifikasi {
  id: string
  /** Kode jenis tugas (mis. PERSETUJUAN_AKSES_MENUNGGU) atau AKUN_TERKUNCI. */
  jenis: string
  judul: string
  isi: string
  /** Rute SPA; null = tanpa tautan. */
  tautan: string | null
  prioritas: PrioritasNotifikasi
  tugas_id: string | null
  dibaca_pada: string | null
  dibuat_pada: string
}

export interface HalamanNotifikasi {
  data: Notifikasi[]
  meta: MetaKursor & { jumlah_belum_dibaca: number }
}

export interface KanalPreferensi {
  aktif: boolean
  dapat_diubah: boolean
}
export interface PreferensiNotifikasi {
  jenis: string
  nama: string
  kritis: boolean
  in_app: true
  email: KanalPreferensi
  pesan_singkat: KanalPreferensi
}

export type TabTugas = 'saya' | 'dieskalasi' | 'unit' | 'selesai'
export interface Tugas {
  id: string
  jenis: { kode: string; nama: string }
  judul: string
  objek: { jenis: string; id: string; label: string }
  /** Rute SPA layar penyelesaian (FR-53-02). */
  tautan: string
  pelaksana: { jenis: 'pengguna' | 'peran' | 'unit'; id: string; nama: string }
  tenggat_pada: string
  /** 0 belum ada pengingat; 1 pengingat; 2 teguran; 3 eskalasi. */
  tingkat_saat_ini: 0 | 1 | 2 | 3
  status: 'terbuka' | 'selesai' | 'batal'
  terlambat: boolean
  kritis: boolean
  dibuat_pada: string
  selesai_pada: string | null
}
export interface HalamanTugas {
  data: Tugas[]
  meta: MetaKursor & { jumlah: Record<TabTugas, number> }
}
export interface RiwayatTugas {
  jenis: 'dibuat' | 'pengingat' | 'teguran' | 'eskalasi' | 'selesai' | 'batal'
  tingkat: number
  terjadi_pada: string
  /** Kosong = tanpa penerima (mis. kepala unit belum ditetapkan). */
  penerima: { id: string; nama: string }[]
  kanal: ('in_app' | 'email' | 'pesan_singkat')[]
  catatan: string | null
}
export interface TugasRinci extends Tugas {
  riwayat: RiwayatTugas[]
}

export interface AturanEskalasi {
  /** Negatif: menit sebelum tenggat (tingkat 1 ke pelaksana). */
  pengingat_menit: number
  /** Menit sesudah tenggat (tingkat 2 ke kepala unit). */
  teguran_menit: number
  /** Menit sesudah tenggat (tingkat 3 ke kepala bidang + dasbor Direktur). */
  eskalasi_menit: number
}
export interface RingkasUnit {
  id: string
  kode: string
  nama: string
}
export interface JenisTugas {
  id: string
  kode: string
  nama: string
  uraian: string | null
  modul: string
  /** dibuat = tenggat = dibuat + batas; objek = tenggat dari tanggal objek, tugas dibuat batas_menit sebelumnya. */
  tenggat_dari: 'dibuat' | 'objek'
  batas_menit: number
  unit_teguran: RingkasUnit | null
  unit_eskalasi: RingkasUnit | null
  eskalasi: AturanEskalasi
  kritis: boolean
  aktif: boolean
  versi: string
  diubah_pada: string
}
export interface UbahJenisTugas {
  nama?: string
  batas_menit?: number
  unit_teguran_id?: string | null
  unit_eskalasi_id?: string | null
  eskalasi?: AturanEskalasi
  kritis?: boolean
  aktif?: boolean
  dasar_perubahan: string
}
