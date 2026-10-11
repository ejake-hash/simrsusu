import { ApiError } from '@/shared/api/klien'
import { formatSisaWaktu } from '@/shared/utils/waktu'

/**
 * Arti error langkah MFA bagi halaman masuk (PRD 10.3.2). Challenge yang tidak berlaku dan akun
 * terkunci membuang challenge di server, jadi pengguna kembali ke langkah sandi dengan pesannya.
 */
export type TindakanMfaError =
  | { jenis: 'tampilkan'; pesan: string; kosongkanKode: boolean }
  | { jenis: 'kembali'; pesan: string }

export function tindakanMfaError(error: unknown, unitAdmin: string): TindakanMfaError {
  if (!(error instanceof ApiError)) {
    return { jenis: 'tampilkan', pesan: `Respons server tidak dikenali. Coba lagi atau hubungi ${unitAdmin}.`, kosongkanKode: false }
  }

  const sisa = error.cobaLagiDetik === null ? null : formatSisaWaktu(error.cobaLagiDetik)
  switch (error.kode) {
    case 'kode_mfa_salah':
      return { jenis: 'tampilkan', pesan: 'Kode salah. Periksa kode terbaru lalu coba lagi.', kosongkanKode: true }
    case 'challenge_tidak_berlaku':
      return { jenis: 'kembali', pesan: 'Waktu verifikasi habis. Silakan masuk lagi.' }
    case 'akun_terkunci':
      return {
        jenis: 'kembali',
        pesan: `Akun terkunci sementara karena terlalu banyak percobaan gagal.${sisa ? ` Coba lagi dalam ${sisa}.` : ''}`,
      }
    case 'terlalu_banyak_permintaan':
      return { jenis: 'tampilkan', pesan: `Terlalu banyak percobaan.${sisa ? ` Coba lagi dalam ${sisa}.` : ' Coba lagi nanti.'}`, kosongkanKode: false }
    case 'validasi_gagal':
      return { jenis: 'tampilkan', pesan: error.rincian[0]?.pesan ?? error.message, kosongkanKode: false }
    default:
      return { jenis: 'tampilkan', pesan: error.message, kosongkanKode: false }
  }
}
