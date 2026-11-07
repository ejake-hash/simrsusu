import { describe, expect, it } from 'vitest'
import { kunciPanggilan, panggilanBaru, tokenDariFragmen } from './display'
import type { PanggilanDisplay } from './tipe'

const p = (nomor: string, ke: number, pada: string): PanggilanDisplay => ({ nomor_tampil: nomor, inisial: null, tujuan: 'Ruang 1 · Poli Contoh', panggilan_ke: ke, dipanggil_pada: pada, teks_suara: `Nomor ${nomor}` })

describe('token display', () => {
  it('diambil dari fragmen URL saja', () => {
    expect(tokenDariFragmen('#token=1|abcDEF')).toBe('1|abcDEF')
    expect(tokenDariFragmen('#token=')).toBeNull()
    expect(tokenDariFragmen('')).toBeNull()
    expect(tokenDariFragmen('#lain=1')).toBeNull()
  })
})

describe('panggilan yang belum diputar', () => {
  const a = p('PD-001', 1, '2026-11-07T03:00:00Z')
  const b = p('PD-002', 1, '2026-11-07T03:01:00Z')
  it('semua panggilan baru diputar dari yang terlama', () => {
    expect(panggilanBaru(new Set(), [b, a])).toEqual([a, b])
  })
  it('yang sudah diputar dilewati, panggilan ulang diputar lagi', () => {
    expect(panggilanBaru(new Set([kunciPanggilan(a), kunciPanggilan(b)]), [b, a])).toEqual([])
    const ulang = p('PD-002', 2, '2026-11-07T03:02:00Z')
    expect(panggilanBaru(new Set([kunciPanggilan(a), kunciPanggilan(b)]), [ulang, b, a])).toEqual([ulang])
  })
})
