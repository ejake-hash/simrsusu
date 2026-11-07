import { ApiError } from '@/shared/api/klien'
import type { JenisAlergi, StatusAsesmen, TahapWorklist, TingkatAlergi } from './tipe'

export const KUNCI_WORKLIST = {
  semua: ['rawat-jalan'] as const,
  daftar: (filter: object) => ['rawat-jalan', 'worklist', filter] as const,
  asesmen: (kunjunganId: string) => ['rawat-jalan', 'asesmen', kunjunganId] as const,
  alergi: (pasienId: string) => ['rawat-jalan', 'alergi', pasienId] as const,
}

export const LABEL_TAHAP: Record<TahapWorklist, string> = { perawat: 'Menunggu perawat', dokter: 'Menunggu dokter', selesai: 'Selesai' }
export const NADA_TAHAP: Record<TahapWorklist, 'info' | 'ok' | 'netral'> = { perawat: 'info', dokter: 'ok', selesai: 'netral' }
export const LABEL_ASESMEN: Record<StatusAsesmen, string> = { draf: 'Draf', selesai: 'Selesai' }
export const LABEL_JENIS_ALERGI: Record<JenisAlergi, string> = { obat: 'Obat', makanan: 'Makanan', lingkungan: 'Lingkungan', lainnya: 'Lainnya' }
export const LABEL_TINGKAT: Record<TingkatAlergi, string> = { ringan: 'Ringan', sedang: 'Sedang', berat: 'Berat' }

const MENIT = 60_000

/** "7 menit", "1 jam 5 menit"; di bawah semenit "kurang dari 1 menit". */
export function lamaMenunggu(sejak: string, sekarang: number): string {
  const menit = Math.max(0, Math.floor((sekarang - new Date(sejak).getTime()) / MENIT))
  if (menit < 1) return 'kurang dari 1 menit'
  if (menit < 60) return `${menit} menit`
  const jam = Math.floor(menit / 60)
  return menit % 60 ? `${jam} jam ${menit % 60} menit` : `${jam} jam`
}

/** Sisa waktu perbaikan (B-14): "1 hari 4 jam", "35 menit", null bila batas sudah lewat. */
export function sisaPerbaikan(batas: string | null, sekarang: number): string | null {
  if (!batas) return null
  const menit = Math.floor((new Date(batas).getTime() - sekarang) / MENIT)
  if (menit <= 0) return null
  const hari = Math.floor(menit / 1440)
  const jam = Math.floor((menit % 1440) / 60)
  if (hari) return jam ? `${hari} hari ${jam} jam` : `${hari} hari`
  return jam ? `${jam} jam ${menit % 60} menit` : `${menit} menit`
}

/** Pesan galat asesmen/alergi: pesan server ditambah petunjuk langkah berikutnya dari kodenya. */
export function pesanKlinis(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  switch (e.kode) {
    case 'batas_perbaikan_lewat':
      return 'Batas perbaikan 2 × 24 jam sudah lewat, asesmen ini tidak bisa diubah lagi. Koreksi dicatat lewat adendum pada CPPT.'
    case 'alergi_sudah_tercatat':
      return 'Alergi aktif dengan zat yang sama sudah tercatat. Periksa daftar di atas.'
    case 'asesmen_sudah_ada':
      return 'Asesmen kunjungan ini sudah dimulai petugas lain. Muat ulang untuk melihatnya.'
    case 'status_tidak_sesuai':
      return `${e.message} Muat ulang untuk melihat keadaan terbaru.`
    default:
      if (e.status === 412 || e.status === 428) return 'Asesmen sudah berubah sejak Anda membukanya. Muat ulang, lalu ulangi perubahan.'
      return e.message
  }
}
