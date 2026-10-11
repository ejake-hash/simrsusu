import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { ambilMenuSaya } from '@/modules/akses/api'
import { menuSayaTiruan } from '@/modules/akses/tiruan/menuSaya'
import HalamanMenu from './HalamanMenu.vue'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'

vi.mock('@/modules/akses/api', () => ({ ambilMenuSaya: vi.fn(), lupakanMenuSaya: vi.fn(), ambilSaya: vi.fn(), keluar: vi.fn() }))

async function buka(jalur: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<p>beranda</p>' } },
      { path: '/:jalur(.*)*', component: HalamanMenu },
    ],
  })
  await router.push(jalur)
  const w = mount({ template: '<RouterView />' }, { global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient() }]] } })
  await flushPromises()
  return { w, router }
}

beforeEach(() => {
  setActivePinia(createPinia())
  useSesiStore().pengguna = penggunaTiruan()
  vi.mocked(ambilMenuSaya).mockReset().mockResolvedValue(menuSayaTiruan)
})

describe('HalamanMenu (rute dinamis dari menu)', () => {
  it('sub-menu berstatus segera → SegeraHadirPage dengan nama menu, fase, dan tombol kembali', async () => {
    const { w, router } = await buka('/igd/papan')
    expect(w.get('h1').text()).toBe('IGD · Papan pasien IGD')
    expect(w.text()).toContain('Segera hadir')
    expect(w.text()).toContain('fase 2')
    expect(document.title).toBe('Papan pasien IGD · Karsitara')

    await w.get('a').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('menu aktif yang halamannya belum ada di frontend juga ke SegeraHadirPage', async () => {
    // Router uji hanya memuat HalamanMenu, jadi /pengaturan/peran (aktif) belum punya halaman di sini.
    const { w } = await buka('/pengaturan/peran')
    expect(w.get('h1').text()).toBe('Pengaturan Sistem · Peran & izin')
  })

  it('menu aktif yang punya sub-menu → halaman induk dengan kartu sub-menu (sidebar 64 px tanpa sub-menu)', async () => {
    const { w } = await buka('/pengaturan')
    expect(w.get('h1').text()).toBe('Pengaturan Sistem')
    const nav = w.get('nav[aria-label="Sub-menu Pengaturan Sistem"]')
    expect(nav.findAll('a').map((a) => a.text())).toEqual(['Peran & izin', 'Akun & akses', 'Jejak auditSegera hadir'])
    expect(nav.findAll('a').map((a) => a.attributes('href'))).toEqual(['/pengaturan/peran', '/pengaturan/akun', '/pengaturan/audit'])
  })

  it('menu berstatus segera dengan sub-menu tetap Segera hadir, bukan halaman induk', async () => {
    const { w } = await buka('/igd')
    expect(w.get('h1').text()).toBe('IGD')
    expect(w.text()).toContain('Segera hadir')
  })

  it('jalur yang tidak ada di menu akun → tidak ditemukan, tanpa membedakan "tidak diizinkan"', async () => {
    const { w } = await buka('/keuangan')
    expect(w.get('h1').text()).toBe('Halaman tidak ditemukan')
  })

  it('tanpa sesi menu tidak diminta', async () => {
    useSesiStore().pengguna = null
    const { w } = await buka('/igd')
    expect(ambilMenuSaya).not.toHaveBeenCalled()
    expect(w.find('h1').exists()).toBe(false)
  })
})
