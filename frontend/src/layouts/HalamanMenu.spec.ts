import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { ambilMenuSaya } from '@/modules/akses/api'
import { menuSayaTiruan } from '@/modules/akses/tiruan/menuSaya'
import HalamanMenu from './HalamanMenu.vue'

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
  useSesiStore().pengguna = { id: 'p-1', nama_pengguna: 'contoh.dokter', surel: null, login_terakhir_pada: null, mfa_aktif: false, pegawai: null }
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
    const { w } = await buka('/data-induk/peran')
    expect(w.get('h1').text()).toBe('Master Data · Peran & menu')
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
