import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PenandaTerbatas from '@/design-system/components/PenandaTerbatas.vue'
import { apakahTerbatas } from './terbatas'

describe('Terbatas (lapis 4)', () => {
  it('mengenali pengganti objek berlabel dari server', () => {
    expect(apakahTerbatas({ terbatas: true })).toBe(true)
    expect(apakahTerbatas({ terbatas: false })).toBe(false)
    expect(apakahTerbatas({ nama: 'x' })).toBe(false)
    expect(apakahTerbatas(null)).toBe(false)
  })

  it('penanda tidak menyebut nama label dan punya teks untuk pembaca layar', () => {
    const w = mount(PenandaTerbatas)
    expect(w.text()).toContain('Terbatas')
    expect(w.get('[role="note"]').attributes('aria-label')).toMatch(/^Terbatas/)
    expect(w.text()).not.toMatch(/HIV|psikiatri|genetik/i)
    expect(mount(PenandaTerbatas, { props: { ringkas: true } }).text()).toBe('Terbatas')
  })
})
