import { describe, expect, it } from 'vitest'
import { langkahDariRespons, tujuanSetelahMasuk } from './alurMasuk'

describe('langkahDariRespons (PRD 10.3.2)', () => {
  it('respons tanpa status (bentuk lama {data}) ditolak sejak tiket 1.1b', () => {
    expect(() => langkahDariRespons({ data: { id: 'x' } })).toThrow()
  })

  it('status masuk', () => {
    expect(langkahDariRespons({ status: 'masuk', pengguna: {} })).toEqual({ status: 'masuk' })
  })

  it('perlu_mfa membawa challenge, metode, dan kedaluwarsa', () => {
    expect(
      langkahDariRespons({
        status: 'perlu_mfa',
        challenge_id: 't-1',
        metode: ['totp', 'kode_cadangan'],
        kedaluwarsa_pada: '2026-10-10T10:00:00+07:00',
      }),
    ).toEqual({
      status: 'perlu_mfa',
      challenge_id: 't-1',
      metode: ['totp', 'kode_cadangan'],
      kedaluwarsa_pada: '2026-10-10T10:00:00+07:00',
      percayai_perangkat: { tersedia: false, hari: 0 },
    })
  })

  it('percayai_perangkat (1.13): tersedia hanya bila server menawarkan dengan hari > 0; backend lama = tidak tersedia', () => {
    const dasar = { status: 'perlu_mfa', challenge_id: 't-1', metode: ['totp'], kedaluwarsa_pada: '' }
    const tawaran = (p: unknown) => (langkahDariRespons({ ...dasar, percayai_perangkat: p }) as { percayai_perangkat: unknown }).percayai_perangkat
    expect(tawaran({ tersedia: true, hari: 7 })).toEqual({ tersedia: true, hari: 7 })
    expect(tawaran({ tersedia: false, hari: 0 })).toEqual({ tersedia: false, hari: 0 })
    expect(tawaran({ tersedia: true, hari: 0 })).toEqual({ tersedia: false, hari: 0 })
    expect(tawaran(undefined)).toEqual({ tersedia: false, hari: 0 })
    expect(tawaran('ya')).toEqual({ tersedia: false, hari: 0 })
  })

  it('perlu_daftar_mfa', () => {
    expect(
      langkahDariRespons({ status: 'perlu_daftar_mfa', challenge_id: 't-2', kedaluwarsa_pada: '2026-10-10T10:00:00+07:00' }),
    ).toEqual({ status: 'perlu_daftar_mfa', challenge_id: 't-2', kedaluwarsa_pada: '2026-10-10T10:00:00+07:00', percayai_perangkat: { tersedia: false, hari: 0 } })
  })

  it('status tidak dikenal atau challenge hilang ditolak, tidak dianggap masuk', () => {
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
