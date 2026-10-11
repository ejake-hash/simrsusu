import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { ambilMenuSaya } from '@/modules/akses/api'
import { menuSayaTiruan } from '@/modules/akses/tiruan/menuSaya'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import AppShell from './AppShell.vue'
import { adalahPintasanCari, pencarianTerbuka } from './pencarianMenu'

vi.mock('@/modules/akses/api', () => ({ ambilMenuSaya: vi.fn(), lupakanMenuSaya: vi.fn(), ambilSaya: vi.fn(), keluar: vi.fn() }))

let w: VueWrapper | undefined

async function pasang() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  w = mount(AppShell, { attachTo: document.body, global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient() }]] } })
  await flushPromises()
  return w
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(ambilMenuSaya).mockResolvedValue(menuSayaTiruan)
})
afterEach(() => {
  w?.unmount()
  pencarianTerbuka.value = false
  document.body.innerHTML = ''
})

describe('AppShell', () => {
  it('instalasi demo (VITE_INSTALASI_DEMO): pita "DEMO — data fiktif"; bukan demo: tanpa pita', async () => {
    useSesiStore().pengguna = penggunaTiruan()
    vi.stubEnv('VITE_INSTALASI_DEMO', 'true')
    expect((await pasang()).get('.pita-demo').text()).toBe('DEMO — data fiktif')
    w!.unmount()
    vi.stubEnv('VITE_INSTALASI_DEMO', '')
    expect((await pasang()).find('.pita-demo').exists()).toBe(false)
    vi.unstubAllEnvs()
  })

  it('Ctrl+K (bukan Apple) membuka pencarian menu dari layar mana pun; tombol header punya nama aksesibel', async () => {
    useSesiStore().pengguna = penggunaTiruan()
    const shell = await pasang()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, cancelable: true }))
    expect(pencarianTerbuka.value).toBe(true)
    expect(shell.get('[data-pemicu-pencarian]').attributes('aria-label')).toBe('Cari menu')
  })

  it('pintasan: Cmd+K di Apple, Ctrl+K di lainnya; diabaikan saat IME atau sudah ditangani', () => {
    const e = (isi: KeyboardEventInit) => new KeyboardEvent('keydown', { key: 'k', cancelable: true, ...isi })
    expect(adalahPintasanCari(e({ metaKey: true }), true)).toBe(true)
    expect(adalahPintasanCari(e({ ctrlKey: true }), true)).toBe(false)
    expect(adalahPintasanCari(e({ ctrlKey: true }), false)).toBe(true)
    expect(adalahPintasanCari(e({ metaKey: true }), false)).toBe(false)
    expect(adalahPintasanCari(e({ ctrlKey: true, shiftKey: true }), false)).toBe(false)
    expect(adalahPintasanCari(e({ ctrlKey: true, isComposing: true }), false)).toBe(false)
    const ditangani = e({ ctrlKey: true })
    ditangani.preventDefault()
    expect(adalahPintasanCari(ditangani, false)).toBe(false)
  })

  it('AppShell dilepas (keluar): pencarian ditutup', async () => {
    useSesiStore().pengguna = penggunaTiruan()
    await pasang()
    pencarianTerbuka.value = true
    w!.unmount()
    w = undefined
    expect(pencarianTerbuka.value).toBe(false)
  })
})
