import { describe, expect, it } from 'vitest'
import { langkahDariRespons, tujuanSetelahMasuk } from './alurMasuk'

describe('langkahDariRespons (PRD 10.3.2)', () => {
  it('respons tanpa status (bentuk lama {data}) berarti masuk', () => {
    expect(langkahDariRespons({ data: { id: 'x' } })).toEqual({ status: 'masuk' })
  })

  it('status masuk', () => {
    expect(langkahDariRespons({ status: 'masuk', pengguna: {} })).toEqual({ status: 'masuk' })
  })

  it('perlu_mfa membawa tantangan, metode, dan kedaluwarsa', () => {
    expect(
      langkahDariRespons({
        status: 'perlu_mfa',
        tantangan_id: 't-1',
        metode: ['totp', 'kode_cadangan'],
        kedaluwarsa_pada: '2026-10-10T10:00:00+07:00',
      }),
    ).toEqual({
      status: 'perlu_mfa',
      tantangan_id: 't-1',
      metode: ['totp', 'kode_cadangan'],
      kedaluwarsa_pada: '2026-10-10T10:00:00+07:00',
    })
  })

  it('perlu_daftar_mfa', () => {
    expect(
      langkahDariRespons({ status: 'perlu_daftar_mfa', tantangan_id: 't-2', kedaluwarsa_pada: '2026-10-10T10:00:00+07:00' }),
    ).toEqual({ status: 'perlu_daftar_mfa', tantangan_id: 't-2', kedaluwarsa_pada: '2026-10-10T10:00:00+07:00' })
  })

  it('status tidak dikenal atau tantangan hilang ditolak, tidak dianggap masuk', () => {
    expect(() => langkahDariRespons({ status: 'aneh' })).toThrow()
    expect(() => langkahDariRespons({ status: 'perlu_mfa' })).toThrow()
  })
})

describe('tujuanSetelahMasuk', () => {
  it.each([
    ['/pasien/123?tab=cppt', '/pasien/123?tab=cppt'],
    [undefined, '/'],
    ['', '/'],
    ['https://contoh.invalid/', '/'],
    ['//contoh.invalid/', '/'],
    ['/\\contoh.invalid', '/'],
    ['/masuk', '/'],
    [['/a', '/b'], '/'],
  ])('%j → %s', (kembali, tujuan) => {
    expect(tujuanSetelahMasuk(kembali)).toBe(tujuan)
  })
})
