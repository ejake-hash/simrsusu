import type { AturanPerpindahan } from '@/modules/matriks-perpindahan/tipe'
import type { StatusDisposisi, TujuanDisposisi } from '@/modules/pendaftaran/tipeKunjungan'
import { ApiError } from '@/shared/api/klien'

/**
 * Aturan tampil layar IGD → Disposisi (tiket 2.26, kontrak v0.33.0; FR-03-09). Pilihan tujuan hanya dari sel matriks
 * perpindahan yang aktif untuk asal IGD; server tetap memeriksa matriks dan izin pemberi perintah.
 */
export const URUTAN_TUJUAN: readonly TujuanDisposisi[] = ['pulang', 'rawat_inap', 'kamar_operasi', 'rujuk_keluar', 'aps', 'meninggal', 'doa']

export const LABEL_TUJUAN: Record<TujuanDisposisi, string> = {
  pulang: 'Pulang',
  rawat_inap: 'Rawat inap',
  kamar_operasi: 'Kamar operasi',
  rujuk_keluar: 'Rujuk keluar',
  aps: 'Pulang atas permintaan sendiri (APS)',
  meninggal: 'Meninggal',
  doa: 'DOA (meninggal sebelum tiba)',
}

/** Akibat tiap tujuan, ditampilkan sebelum konfirmasi. */
export const AKIBAT_TUJUAN: Record<TujuanDisposisi, string> = {
  pulang: 'Kunjungan IGD ditutup dan pasien keluar dari daftar IGD aktif.',
  rawat_inap: 'Permintaan kamar rawat inap dibuat; pasien tetap di IGD sampai kamar dialokasikan.',
  kamar_operasi: 'Pasien menunggu kamar operasi dan tetap di IGD sampai diterima.',
  rujuk_keluar: 'Kunjungan IGD ditutup dan pasien keluar dari daftar IGD aktif.',
  aps: 'Kunjungan IGD ditutup dan pasien keluar dari daftar IGD aktif.',
  meninggal: 'Kunjungan IGD ditutup dan pasien keluar dari daftar IGD aktif.',
  doa: 'Kunjungan IGD ditutup dan pasien keluar dari daftar IGD aktif.',
}

/** Perpindahan antar-ruang di dalam RS: serah terima RM-15 wajib (7.00.3). */
export const BUTUH_SERAH_TERIMA: readonly TujuanDisposisi[] = ['rawat_inap', 'kamar_operasi']
export const butuhSerahTerima = (t: TujuanDisposisi | '' | null) => t !== null && t !== '' && BUTUH_SERAH_TERIMA.includes(t)
/** Disposisi yang menutup kunjungan IGD (aksi tak terbalikkan). */
export const menutupKunjungan = (t: TujuanDisposisi) => !BUTUH_SERAH_TERIMA.includes(t)

export const labelTujuan = (t: string) => LABEL_TUJUAN[t as TujuanDisposisi] ?? t

export const LABEL_STATUS_DISPOSISI: Record<StatusDisposisi, string> = {
  selesai: 'Selesai',
  menunggu_kamar: 'Menunggu kamar',
  menunggu_kamar_operasi: 'Menunggu kamar operasi',
}
export const NADA_STATUS_DISPOSISI: Record<StatusDisposisi, 'ok' | 'info'> = {
  selesai: 'ok',
  menunggu_kamar: 'info',
  menunggu_kamar_operasi: 'info',
}

/** Tujuan disposisi yang boleh dipilih: sel aktif asal IGD, urut FR-03-09; sel non-disposisi (konsul, order) diabaikan. */
export function tujuanDariMatriks(sel: readonly AturanPerpindahan[]): TujuanDisposisi[] {
  const aktif = new Set(sel.filter((a) => a.pos_asal === 'igd' && a.status === 'aktif').map((a) => a.pos_tujuan as string))
  return URUTAN_TUJUAN.filter((t) => aktif.has(t))
}

/**
 * Pesan galat POST /disposisi per kode kontrak. null = galat kolom (ditampilkan di kolomnya) atau bukan ApiError.
 */
export function pesanGalatDisposisi(e: unknown): string | null {
  if (!(e instanceof ApiError)) return null
  if (e.status === 403) {
    if (e.kode === 'izin_praktik_tidak_berlaku') return 'SIP/SPK Anda tidak berlaku hari ini. Perintah klinis hanya dari dokter dengan SIP berlaku.'
    if (e.kode === 'akses_ditolak') {
      return 'Akun Anda tidak memegang izin pemberi perintah untuk tujuan ini. Perintah klinis hanya dari dokter dengan SIP berlaku yang diizinkan matriks perpindahan.'
    }
    // Kode 403 lain (mis. kewenangan klinis, MFA) membawa pesan server yang lebih tepat.
    return e.message
  }
  switch (e.kode) {
    case 'perpindahan_di_luar_matriks':
      return 'Tujuan ini tidak lagi aktif di matriks perpindahan untuk IGD. Daftar tujuan sudah dimuat ulang; pilih tujuan lain.'
    case 'tujuan_bukan_disposisi':
      return 'Tujuan ini bukan disposisi IGD. Konsul, order penunjang, dan ICU/HCU lewat alurnya sendiri.'
    case 'status_tidak_sesuai':
      return 'Kunjungan ini tidak lagi aktif di IGD (sudah selesai atau dibatalkan). Daftar pasien sudah dimuat ulang.'
    case 'perintah_sudah_menunggu':
      return 'Perintah yang sama sudah tercatat dan masih menunggu. Lihat riwayat disposisi di bawah.'
    default:
      return e.status === 422 && e.rincian.some((r) => typeof r.kolom === 'string' && ['tujuan', 'alasan', 'serah_terima'].includes(r.kolom)) ? null : e.message
  }
}

export const KUNCI_DISPOSISI = {
  riwayat: (kunjunganId: string) => ['disposisi', 'riwayat', kunjunganId] as const,
  matriksIgd: ['matriks-perpindahan', 'igd-aktif'] as const,
}
