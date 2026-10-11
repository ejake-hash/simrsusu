import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import PencarianMenu from '@/layouts/PencarianMenu.vue'
import { pencarianTerbuka } from '@/layouts/pencarianMenu'
import { ambilMenuSaya, ambilSaya, type MenuSaya } from '@/modules/akses/api'
import { menuSayaTiruan } from '@/modules/akses/tiruan/menuSaya'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import BerandaPage from './pages/BerandaPage.vue'

vi.mock('@/modules/akses/api', () => ({ ambilMenuSaya: vi.fn(), lupakanMenuSaya: vi.fn(), ambilSaya: vi.fn(), keluar: vi.fn() }))

const terpasang: { unmount: () => void }[] = []

async function pasang(komponen: object, menu: MenuSaya = menuSayaTiruan) {
  vi.mocked(ambilMenuSaya).mockResolvedValue(menu)
  // Beranda menyegarkan /saya saat dibuka; server tiruan mengembalikan akun yang sama.
  const pengguna = useSesiStore().pengguna
  vi.mocked(ambilSaya).mockImplementation(async () => pengguna!)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  const w = mount(komponen, { attachTo: document.body, global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient() }]] } })
  terpasang.push(w)
  await flushPromises()
  return { w, router }
}

function lebar(ponsel: boolean) {
  vi.stubGlobal('matchMedia', (q: string) => ({ matches: !ponsel, media: q, addEventListener: () => {}, removeEventListener: () => {} }))
}

beforeEach(() => {
  setActivePinia(createPinia())
  useSesiStore().pengguna = penggunaTiruan({
    pegawai: { id: 'g-1', nama: 'Contoh Dokter' },
    peran: [{ id: 'r-1', nama: 'Contoh Dokter' }],
    masuk_sebelumnya_pada: '2026-10-10T01:30:00.000Z',
    masuk_sebelumnya_dari_jaringan_rs: false,
  })
  lebar(false)
})
afterEach(() => {
  // Lepas komponen dulu: PencarianMenu memantau ref global dan dialognya di-teleport ke body.
  terpasang.splice(0).forEach((w) => w.unmount())
  pencarianTerbuka.value = false
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('BerandaPage', () => {
  it('salam, peran yang berlaku, kartu modul milik akun dengan sub-menu yang bisa diklik dan lencana Segera hadir', async () => {
    const { w } = await pasang(BerandaPage)
    expect(w.get('h1').text()).toMatch(/^Selamat (pagi|siang|sore|malam), Contoh Dokter$/)
    expect(w.text()).toContain('· Contoh Dokter')

    const kartu = w.findAll('article')
    expect(kartu).toHaveLength(menuSayaTiruan.grup.flatMap((g) => g.menu).length - 1)
    const igd = kartu.find((k) => k.text().includes('IGD'))!
    expect(igd.text()).toContain('Segera hadir · fase 2')
    expect(igd.findAll('a').map((a) => a.attributes('href'))).toEqual(['/igd', '/igd/papan'])
    expect(w.findAll('h2').map((h) => h.text())).toEqual(['Modul saya', 'Kotak kerja', 'Keamanan akun', 'Pintasan', 'Informasi umum'])
  })

  it('kotak kerja jujur tanpa angka; keamanan akun menyebut masuk sebelumnya dari luar jaringan RS', async () => {
    const { w } = await pasang(BerandaPage)
    expect(w.text()).toContain('Tugas dan pengingat tampil di sini setelah modul notifikasi aktif.')
    expect(w.text()).toContain('dari luar jaringan RS')
    expect(w.text()).toContain('Bukan Anda yang masuk?')
  })

  it('membuka Beranda menyegarkan /saya: masuk dari perangkat lain sesudah sesi dibuka langsung tampil', async () => {
    const lama = useSesiStore().pengguna!
    vi.mocked(ambilMenuSaya).mockResolvedValue(menuSayaTiruan)
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/')
    vi.mocked(ambilSaya).mockResolvedValue({ ...lama, masuk_sebelumnya_pada: '2026-10-11T03:00:00.000Z', masuk_sebelumnya_dari_jaringan_rs: true })
    const w = mount(BerandaPage, { attachTo: document.body, global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient() }]] } })
    terpasang.push(w)
    await flushPromises()
    expect(ambilSaya).toHaveBeenCalled()
    expect(w.text()).toContain('11 Oktober 2026')
    expect(w.text()).toContain('dari jaringan RS')
  })

  it('masuk pertama dan akun tanpa modul: keterangan yang jelas', async () => {
    useSesiStore().pengguna = penggunaTiruan({ peran: [], masuk_sebelumnya_pada: null })
    const { w } = await pasang(BerandaPage, { versi: 'x', izin: [], grup: [menuSayaTiruan.grup[0]!] })
    expect(w.text()).toContain('Belum ada catatan masuk sebelumnya — ini masuk pertama Anda')
    expect(w.text()).toContain('Belum ada modul yang diberikan, hubungi')
    expect(w.findAll('article')).toHaveLength(0)
  })

  it('ponsel: tanpa kisi modul dan pintasan, kotak kerja dan keamanan akun tetap ada', async () => {
    lebar(true)
    const { w } = await pasang(BerandaPage)
    expect(w.findAll('h2').map((h) => h.text())).toEqual(['Kotak kerja', 'Keamanan akun', 'Informasi umum'])
    expect(w.text()).toContain('Modul lain dibuka dari tablet atau komputer.')
  })
})

describe('PencarianMenu (popover, Ctrl/Cmd+K)', () => {
  const tekan = (el: Element, key: string) => el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))

  it('hanya menu yang diizinkan; panah memilih, Enter membuka rute', async () => {
    const { router } = await pasang(PencarianMenu)
    pencarianTerbuka.value = true
    await flushPromises()
    const kolom = document.querySelector<HTMLInputElement>('input[role="combobox"]')!
    expect(document.activeElement).toBe(kolom)

    kolom.value = 'igd'
    kolom.dispatchEvent(new Event('input'))
    await flushPromises()
    const opsi = [...document.querySelectorAll('[role="option"]')]
    expect(opsi.map((o) => o.textContent)).toEqual(['IGDSegera hadir', 'Papan pasien IGDIGDSegera hadir'])
    expect(opsi[0]!.getAttribute('aria-selected')).toBe('true')

    tekan(kolom, 'ArrowDown')
    await flushPromises()
    expect(kolom.getAttribute('aria-activedescendant')).toBe(opsi[1]!.id)
    tekan(kolom, 'Enter')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/igd/papan')
    expect(pencarianTerbuka.value).toBe(false)
  })

  it('bukan modal: tanpa tirai dan tanpa aria-modal; tombol pemicu bernama "Cari menu"', async () => {
    const { w } = await pasang(PencarianMenu)
    expect(w.get('button').attributes('aria-label')).toBe('Cari menu')
    await w.get('button').trigger('click')
    await flushPromises()
    const panel = document.querySelector('.panel-pencarian')!
    expect(panel).not.toBeNull()
    expect(panel.getAttribute('aria-modal')).toBeNull()
    expect(document.querySelector('.dialog-tirai')).toBeNull()
  })

  it('kueri tanpa hasil: keterangan di wilayah status', async () => {
    await pasang(PencarianMenu)
    pencarianTerbuka.value = true
    await flushPromises()
    const kolom = document.querySelector<HTMLInputElement>('input[role="combobox"]')!
    kolom.value = 'keuangan'
    kolom.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(document.querySelector('[role="status"]')!.textContent).toContain('Tidak ada menu yang cocok')
    expect(document.querySelectorAll('[role="option"]')).toHaveLength(0)
  })
})
