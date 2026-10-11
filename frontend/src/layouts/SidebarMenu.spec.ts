import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { GrupMenu } from '@/modules/akses/api'
import { menuSayaTiruan } from '@/modules/akses/tiruan/menuSaya'
import type { KelasLayar } from '@/shared/composables/useKelasLayar'
import SidebarMenu from './SidebarMenu.vue'

async function pasang(jalur: string, grup: GrupMenu[] = menuSayaTiruan.grup, kelas: KelasLayar = 'desktop') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:jalur(.*)*', component: { template: '<p />' } }] })
  await router.push(jalur)
  return mount(SidebarMenu, { props: { grup, kelas }, global: { plugins: [router] } })
}

const labelLink = (w: Awaited<ReturnType<typeof pasang>>) => w.findAll('.nav-item .label').map((l) => l.text())

describe('SidebarMenu', () => {
  it('merender grup dan menu dari respons, berurutan', async () => {
    const w = await pasang('/')
    expect(w.findAll('.nav-grup').map((g) => g.text())).toEqual(['Pelayanan', 'Pendapatan', 'Tata Kelola'])
    expect(labelLink(w).slice(0, 3)).toEqual(['Beranda', 'Pendaftaran', 'IGD'])
    expect(w.findAll('.nav-item')).toHaveLength(19)
  })

  it('menu aktif mengikuti rute; sub-menu hanya di bawah menu aktif', async () => {
    const w = await pasang('/pengaturan/akun')
    const aktif = w.get('.nav-item.aktif')
    expect(aktif.text()).toContain('Pengaturan Sistem')
    expect(w.findAll('.sub-item').map((s) => s.text())).toEqual(['Peran & izin', 'Akun & akses', 'Jejak audit'])
    expect(w.get('.sub-item.aktif').attributes('aria-current')).toBe('page')
    expect(w.text()).not.toContain('Papan pasien IGD')
  })

  it('item berstatus segera diberi penanda "Segera hadir"; item aktif tidak', async () => {
    const w = await pasang('/')
    const igd = w.findAll('.nav-item').find((l) => l.text().startsWith('IGD'))
    expect(igd?.get('.segera').text()).toBe('Segera hadir')
    expect(w.findAll('.nav-item')[0]?.find('.segera').exists()).toBe(false)
  })

  it('jumlah tugas hanya bila ada tugas terbuka (null dan 0 tanpa lencana)', async () => {
    const grup: GrupMenu[] = [
      {
        kode: 'utama',
        label: null,
        menu: [
          { kode: 'beranda', label: 'Beranda', ikon: 'beranda', rute: '/', status: 'aktif', fase: 1, jumlah_tugas: 3, sub_menu: [] },
          { kode: 'kasir', label: 'Kasir', ikon: 'kasir', rute: '/kasir', status: 'aktif', fase: 2, jumlah_tugas: null, sub_menu: [] },
          { kode: 'sdm', label: 'SDM', ikon: 'sdm', rute: '/sdm', status: 'aktif', fase: 4, jumlah_tugas: 0, sub_menu: [] },
        ],
      },
    ]
    const w = await pasang('/', grup)
    expect(w.findAll('.jumlah-tugas').map((l) => l.text())).toEqual(['3 tugas terbuka'])
  })

  it('ponsel: hanya Beranda', async () => {
    const w = await pasang('/', menuSayaTiruan.grup, 'ponsel')
    expect(labelLink(w)).toEqual(['Beranda'])
    expect(w.text()).toContain('Modul lain dibuka dari tablet atau komputer.')
  })

  it('ikon yang tidak dikenal memakai ikon umum, bukan isi dari server', async () => {
    const grup: GrupMenu[] = [
      { kode: 'utama', label: null, menu: [{ kode: 'x', label: 'X', ikon: '<img src=x onerror=alert(1)>', rute: '/', status: 'aktif', fase: 1, sub_menu: [] }] },
    ]
    const w = await pasang('/', grup)
    expect(w.html()).not.toContain('onerror')
    expect(w.get('svg').html()).toContain('<circle')
  })
})
