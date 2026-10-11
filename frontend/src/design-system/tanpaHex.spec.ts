import { describe, expect, it } from 'vitest'

/**
 * Lint warna (uji penerimaan 1.11, CLAUDE.md): nilai warna HEX hanya boleh di tokens.css. Komponen, halaman, dan CSS
 * lain memakai token `var(--…)` agar paket identitas RS (PRD 3.4) dan kontras terjaga di satu tempat.
 */
const berkas = import.meta.glob<string>(['/src/**/*.vue', '/src/**/*.css', '/src/**/*.ts', '!/src/design-system/tokens.css', '!/src/**/*.spec.ts'], {
  query: '?raw',
  import: 'default',
  eager: true,
})
const HEX = /#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b/g

describe('tanpa HEX di luar tokens.css', () => {
  it('memindai berkas sumber', () => {
    expect(Object.keys(berkas).length).toBeGreaterThan(50)
  })

  it('tidak ada nilai warna HEX di komponen, halaman, maupun CSS lain', () => {
    const temuan = Object.entries(berkas).flatMap(([jalur, isi]) =>
      isi.split('\n').flatMap((baris, i) => (baris.match(HEX) ?? []).map((hex) => `${jalur}:${i + 1} ${hex}`)),
    )
    expect(temuan).toEqual([])
  })
})
