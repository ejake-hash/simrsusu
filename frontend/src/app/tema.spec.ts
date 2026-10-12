import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { hexKeRgb, kontras, latarPanel, rgbKeHex, terapkanTema, turunanTema, type Rgb } from './tema'

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

  it('panel merek halaman masuk: teks putih, --brand-100, dan penekanan ≥ 4,5:1 di semua titik dan sorot, untuk setiap paket yang lolos cek backend (catatan #78)', () => {
    // Grid warna sRGB (langkah 51) yang lolos KontrasWarna backend: putih di atas brand ≥ 4,5:1, #1D1D1B di atas aksen ≥ 4,5:1.
    const kanal = [0, 51, 102, 153, 204, 255]
    const grid: Rgb[] = kanal.flatMap((r) => kanal.flatMap((g) => kanal.map((b) => [r, g, b] as Rgb)))
    const brand = grid.filter((w) => kontras(w, rgb('#FFFFFF')) >= 4.5)
    const accent = grid.filter((w) => kontras(w, rgb('#1D1D1B')) >= 4.5)
    const gagal: string[] = []
    // Kasus dari tinjauan #92: pembulatan HEX dan sorot di atas titik tengah gradien.
    const kasus: [Rgb, Rgb][] = [['#2E86DE', '#FFD1DC'], ['#008282', '#D97706'], ['#005AC3', '#F04905']].map(([x, y]) => [rgb(x!), rgb(y!)])
    for (const [b, a] of [...kasus, ...brand.flatMap((b) => accent.map((a) => [b, a] as [Rgb, Rgb]))]) {
      const t = turunanTema(rgbKeHex(b), rgbKeHex(a))!
      for (const l of latarPanel(t)) {
        for (const teks of ['#FFFFFF', t['--brand-100']!, t['--penekanan-panel']!]) {
          if (kontras(rgb(teks), l) < 4.5) gagal.push(`${rgbKeHex(b)}/${rgbKeHex(a)}: ${teks} di ${rgbKeHex(l)}`)
        }
      }
    }
    expect(brand.length * accent.length).toBeGreaterThan(1000)
    expect(gagal.slice(0, 5)).toEqual([])
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
