import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { campur, hexKeRgb, kontras, PORSI_SOROT, terapkanTema, turunanTema } from './tema'

const rgb = (hex: string) => hexKeRgb(hex)!
afterEach(() => terapkanTema(null))

describe('tema dari paket identitas', () => {
  it('rasio kontras WCAG', () => {
    expect(kontras(rgb('#000000'), rgb('#FFFFFF'))).toBeCloseTo(21, 0)
  })

  it('turunan menjamin teks --brand-800 di --brand-50/25 dan --accent-ink di --accent-bg/putih ≥ 4,5:1, juga untuk warna terang', () => {
    for (const [brand, accent] of [
      ['#1F5C99', '#D97706'],
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

  it('panel merek halaman masuk mengikuti warna paket: teks putih dan --brand-100 ≥ 4,5:1, penekanan ≥ 4,5:1 di semua titik (catatan #78)', () => {
    for (const [brand, accent] of [
      ['#1F5C99', '#D97706'],
      ['#2E86DE', '#FFD1DC'],
      ['#7A1F5C', '#9BE3C7'],
      // Warna utama sangat terang/gelap dan aksen gelap: turunan tetap harus lolos.
      ['#9FC5E8', '#1D1D1B'],
      ['#0B1F14', '#FFEE00'],
      ['#C21E15', '#2457C5'],
    ]) {
      const t = turunanTema(brand!, accent!)!
      const mulai = rgb(t['--panel-merek']!)
      const latar = [mulai, rgb(t['--panel-merek-tengah']!), rgb(t['--panel-merek-akhir']!), campur(mulai, rgb(t['--panel-merek-sorot']!), PORSI_SOROT)]
      for (const l of latar) {
        expect(kontras(rgb('#FFFFFF'), l), `${brand} putih`).toBeGreaterThanOrEqual(4.5)
        expect(kontras(rgb(t['--brand-100']!), l), `${brand} --brand-100`).toBeGreaterThanOrEqual(4.5)
        expect(kontras(rgb(t['--penekanan-panel']!), l), `${brand}/${accent} penekanan`).toBeGreaterThanOrEqual(4.5)
      }
    }
  })

  it('palet bawaan tokens.css = turunan warna contoh fiktif (#1F5C99, #D97706)', () => {
    const css = readFileSync(resolve(__dirname, '../design-system/tokens.css'), 'utf8')
    const t = turunanTema('#1F5C99', '#D97706')!
    for (const token of ['--brand-900', '--brand-800', '--brand-700', '--brand-600', '--brand-500', '--brand-100', '--brand-50', '--brand-25', '--accent', '--accent-bg', '--accent-ink']) {
      const nilai = new RegExp(`${token}:\\s*(#[0-9A-F]{6})`, 'i').exec(css)?.[1]
      expect(nilai?.toUpperCase(), token).toBe(t[token])
    }
  })

  it('warna tidak sah → tanpa turunan; terapkanTema memasang dan melepas properti di :root', () => {
    expect(turunanTema('hijau', '#D97706')).toBeNull()
    const akar = document.createElement('div')
    terapkanTema({ brand: '#2E86DE', accent: '#FFD1DC' }, akar)
    expect(akar.style.getPropertyValue('--brand-600')).toBe('#2E86DE')
    terapkanTema(null, akar)
    expect(akar.style.getPropertyValue('--brand-600')).toBe('')
  })
})
