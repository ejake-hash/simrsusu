import { describe, expect, it } from 'vitest'
import { ApiError } from '@/shared/api/klien'
import { tindakanMfaError } from './mfaError'

describe('tindakanMfaError (PRD 10.3.2)', () => {
  it('kode salah: tampilkan pesan dan kosongkan kode', () => {
    expect(tindakanMfaError(new ApiError(401, 'kode_mfa_salah', 'x'), 'Unit TI')).toEqual({
      jenis: 'tampilkan',
      pesan: 'Kode salah. Periksa kode terbaru lalu coba lagi.',
      kosongkanKode: true,
    })
  })

  it('challenge tidak berlaku dan akun terkunci: kembali ke langkah sandi', () => {
    expect(tindakanMfaError(new ApiError(401, 'challenge_tidak_berlaku', 'x'), 'Unit TI')).toEqual({
      jenis: 'kembali',
      pesan: 'Waktu verifikasi habis. Silakan masuk lagi.',
    })
    expect(tindakanMfaError(new ApiError(403, 'akun_terkunci', 'x', [], 840), 'Unit TI')).toEqual({
      jenis: 'kembali',
      pesan: 'Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi dalam 14 menit.',
    })
  })

  it('rate limit memakai Retry-After; error bukan dari API tidak dianggap kode salah', () => {
    expect(tindakanMfaError(new ApiError(429, 'terlalu_banyak_permintaan', 'x', [], 30), 'Unit TI')).toMatchObject({
      pesan: 'Terlalu banyak percobaan. Coba lagi dalam kurang dari 1 menit.',
    })
    expect(tindakanMfaError(new Error('aneh'), 'Instalasi SIMRS')).toMatchObject({
      jenis: 'tampilkan',
      pesan: 'Respons server tidak dikenali. Coba lagi atau hubungi Instalasi SIMRS.',
    })
  })
})
