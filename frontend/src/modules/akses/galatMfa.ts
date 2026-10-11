import { GalatApi } from '@/shared/api/klien'
import { formatSisaWaktu } from '@/shared/utils/waktu'

/**
 * Arti galat langkah MFA bagi halaman masuk (PRD 10.3.2). Tantangan yang tidak berlaku dan akun
 * terkunci membuang tantangan di server, jadi pengguna kembali ke langkah sandi dengan pesannya.
 */
export type TindakanGalatMfa =
  | { jenis: 'tampilkan'; pesan: string; kosongkanKode: boolean }
  | { jenis: 'kembali'; pesan: string }

export function tindakanGalatMfa(galat: unknown, unitAdmin: string): TindakanGalatMfa {
  if (!(galat instanceof GalatApi)) {
    return { jenis: 'tampilkan', pesan: `Respons server tidak dikenali. Coba lagi atau hubungi ${unitAdmin}.`, kosongkanKode: false }
  }

  const sisa = galat.cobaLagiDetik === null ? null : formatSisaWaktu(galat.cobaLagiDetik)
  switch (galat.kode) {
    case 'kode_mfa_salah':
      return { jenis: 'tampilkan', pesan: 'Kode salah. Periksa kode terbaru lalu coba lagi.', kosongkanKode: true }
    case 'tantangan_tidak_berlaku':
      return { jenis: 'kembali', pesan: 'Waktu verifikasi habis. Silakan masuk lagi.' }
    case 'akun_terkunci':
      return {
        jenis: 'kembali',
        pesan: `Akun terkunci sementara karena terlalu banyak percobaan gagal.${sisa ? ` Coba lagi dalam ${sisa}.` : ''}`,
      }
    case 'terlalu_banyak_permintaan':
      return { jenis: 'tampilkan', pesan: `Terlalu banyak percobaan.${sisa ? ` Coba lagi dalam ${sisa}.` : ' Coba lagi nanti.'}`, kosongkanKode: false }
    case 'validasi_gagal':
      return { jenis: 'tampilkan', pesan: galat.rincian[0]?.pesan ?? galat.message, kosongkanKode: false }
    default:
      return { jenis: 'tampilkan', pesan: galat.message, kosongkanKode: false }
  }
}
