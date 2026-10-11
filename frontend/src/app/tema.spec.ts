import { afterEach, describe, expect, it } from 'vitest'
import { hexKeRgb, kontras, terapkanTema, turunanTema } from './tema'

const rgb = (hex: string) => hexKeRgb(hex)!
afterEach(() => terapkanTema(null))

describe('tema dari paket identitas', () => {
  it('rasio kontras WCAG', () => {
    expect(kontras(rgb('#000000'), rgb('#FFFFFF'))).toBeCloseTo(21, 0)
  })

  it('turunan menjamin teks --brand-800 di --brand-50/25 dan --accent-ink di --accent-bg/putih ≥ 4,5:1, juga untuk warna terang', () => {
    for (const [brand, accent] of [
      ['#006937', '#F3C300'],
      ['#2E86DE', '#FFD1DC'],
      ['#7A1F5C', '#9BE3C7'],
    ]) {
      const t = turunanTema(brand!, accent!)!
      expect(kontras(rgb(t['--brand-800']!), rgb(t['--brand-50']!))).toBeGreaterThanOrEqual(4.5)
      expect(kontras(rgb(t['--brand-800']!), rgb(t['--brand-25']!))).toBeGreaterThanOrEqual(4.5)
      expect(kontras(rgb(t['--accent-ink']!), rgb(t['--accent-bg']!))).toBeGreaterThanOrEqual(4.5)
      expect(t['--brand-600']).toBe(brand!.toUpperCase())
      expect(t['--ring']).toMatch(/^0 0 0 3px rgba\(/)
    }
  })

  it('warna tidak sah → tanpa turunan; terapkanTema memasang dan melepas properti di :root', () => {
    expect(turunanTema('hijau', '#F3C300')).toBeNull()
    const akar = document.createElement('div')
    terapkanTema({ brand: '#2E86DE', accent: '#FFD1DC' }, akar)
    expect(akar.style.getPropertyValue('--brand-600')).toBe('#2E86DE')
    terapkanTema(null, akar)
    expect(akar.style.getPropertyValue('--brand-600')).toBe('')
  })
})
