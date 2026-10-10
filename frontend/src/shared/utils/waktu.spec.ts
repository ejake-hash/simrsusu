import { describe, expect, it } from 'vitest'
import { formatSisaWaktu } from './waktu'

describe('formatSisaWaktu', () => {
  it.each([
    [900, '15 menit'],
    [899, '15 menit'],
    [61, '2 menit'],
    [60, '1 menit'],
    [59, 'kurang dari 1 menit'],
    [1, 'kurang dari 1 menit'],
    [0, null],
    [-5, null],
  ])('%i detik → %s', (detik, teks) => {
    expect(formatSisaWaktu(detik)).toBe(teks)
  })
})
