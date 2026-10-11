/// <reference types="node" />
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Lint warna (uji penerimaan 1.11, CLAUDE.md): nilai warna HEX hanya boleh di tokens.css. Komponen, halaman, dan CSS
 * lain memakai token `var(--…)` agar paket identitas RS (PRD 3.4) dan kontras terjaga di satu tempat.
 * Dibaca langsung dari disk: `import.meta.glob('?raw')` mengembalikan string kosong untuk .css di Vitest.
 */
// Vitest berjalan dari folder frontend (import.meta.url di jsdom bukan skema file).
const SRC = resolve(process.cwd(), 'src')
const DIKECUALIKAN = new Set(['design-system/tokens.css'])
const HEX = /#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b/g

function berkas(dir: string): string[] {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n)
    // Folder tiruan berisi data contoh (mis. warna paket identitas dari kontrak), bukan gaya.
    if (n === 'tiruan') return []
    if (statSync(p).isDirectory()) return berkas(p)
    return /\.(vue|css|ts)$/.test(n) && !/\.spec\.ts$/.test(n) ? [p] : []
  })
}

describe('tanpa HEX di luar tokens.css', () => {
  const semua = berkas(SRC).filter((p) => !DIKECUALIKAN.has(relative(SRC, p)))

  it('memindai .vue, .ts, dan .css (termasuk dasar.css)', () => {
    const rel = semua.map((p) => relative(SRC, p))
    expect(rel).toContain('design-system/dasar.css')
    expect(rel.filter((p) => p.endsWith('.css')).length).toBeGreaterThan(1)
    expect(readFileSync(join(SRC, 'design-system/dasar.css'), 'utf8').length).toBeGreaterThan(1000)
  })

  it('tidak ada nilai warna HEX di komponen, halaman, maupun CSS lain', () => {
    const temuan = semua.flatMap((p) =>
      readFileSync(p, 'utf8')
        .split('\n')
        .flatMap((baris, i) => (baris.match(HEX) ?? []).map((hex) => `${relative(SRC, p)}:${i + 1} ${hex}`)),
    )
    expect(temuan).toEqual([])
  })

  it('pola menangkap HEX di CSS (uji pola itu sendiri)', () => {
    expect('.x { color: #1d1d1b; border-color: #FFF }'.match(HEX)).toEqual(['#1d1d1b', '#FFF'])
    expect('href="#konten"'.match(HEX)).toBeNull()
  })
})
