import { ApiError } from '@/shared/api/klien'
import type { KategoriAlasanAksesRm } from '@/shared/api/aksesDarurat'

export const LABEL_KATEGORI_ALASAN: Record<KategoriAlasanAksesRm, string> = {
  pelayanan_lanjutan: 'Pelayanan lanjutan pasien',
  audit: 'Audit',
  klaim: 'Klaim pembiayaan',
  hukum: 'Keperluan hukum',
}

/** Pesan galat buka akses darurat per kode server (403 izin praktik, MFA, tanpa izin); selain itu pesan server. */
export function pesanBukaAksesDarurat(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  switch (e.kode) {
    case 'izin_praktik_tidak_berlaku': return 'Surat izin praktik Anda tidak berlaku saat ini, jadi akses darurat tidak dapat dibuka. Hubungi bagian SDM.'
    case 'mfa_belum_terdaftar': return 'Akses darurat butuh verifikasi dua langkah. Daftarkan dulu di Pengaturan, Keamanan.'
    case 'akses_ditolak': return 'Akun ini tidak berwenang membuka akses darurat. Akses darurat hanya untuk tenaga klinis yang berizin.'
    default: return e.message
  }
}

/** Pesan galat tinjauan: 409 sudah ditinjau, 403 diri sendiri. */
export function pesanTinjauAksesDarurat(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  if (e.kode === 'sudah_ditinjau') return 'Akses ini sudah ditinjau orang lain. Muat ulang daftar.'
  if (e.kode === 'diri_sendiri') return 'Akses yang Anda buka sendiri tidak dapat Anda tinjau.'
  return e.message
}
