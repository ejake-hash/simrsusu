import { describe, expect, it } from 'vitest'
import { GalatApi } from '@/shared/api/klien'
import { tindakanGalatMfa } from './galatMfa'

describe('tindakanGalatMfa (PRD 10.3.2)', () => {
  it('kode salah: tampilkan pesan dan kosongkan kode', () => {
    expect(tindakanGalatMfa(new GalatApi(401, 'kode_mfa_salah', 'x'), 'Unit TI')).toEqual({
      jenis: 'tampilkan',
      pesan: 'Kode salah. Periksa kode terbaru lalu coba lagi.',
      kosongkanKode: true,
    })
  })

  it('tantangan tidak berlaku dan akun terkunci: kembali ke langkah sandi', () => {
    expect(tindakanGalatMfa(new GalatApi(401, 'tantangan_tidak_berlaku', 'x'), 'Unit TI')).toEqual({
      jenis: 'kembali',
      pesan: 'Waktu verifikasi habis. Silakan masuk lagi.',
    })
    expect(tindakanGalatMfa(new GalatApi(403, 'akun_terkunci', 'x', [], 840), 'Unit TI')).toEqual({
      jenis: 'kembali',
      pesan: 'Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi dalam 14 menit.',
    })
  })

  it('batas laju memakai Retry-After; galat bukan dari API tidak dianggap kode salah', () => {
    expect(tindakanGalatMfa(new GalatApi(429, 'terlalu_banyak_permintaan', 'x', [], 30), 'Unit TI')).toMatchObject({
      pesan: 'Terlalu banyak percobaan. Coba lagi dalam kurang dari 1 menit.',
    })
    expect(tindakanGalatMfa(new Error('aneh'), 'Instalasi SIMRS')).toMatchObject({
      jenis: 'tampilkan',
      pesan: 'Respons server tidak dikenali. Coba lagi atau hubungi Instalasi SIMRS.',
    })
  })
})
