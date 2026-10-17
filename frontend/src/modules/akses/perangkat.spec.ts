import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import DialogInfoPeran from './components/pengaturan/DialogInfoPeran.vue'
import RincianAkun from './components/pengaturan/RincianAkun.vue'
import KeamananPage from './pages/KeamananPage.vue'
import PerangkatPage from './pages/PerangkatPage.vue'
import { uid } from './tiruan/dataPengaturan'
import { pasangTiruan } from './tiruan/pasangTiruan'
import { penggunaTiruan } from './tiruan/pengguna'
import { buatServerTiruan, ID_SUPERADMIN, type ServerTiruan } from './tiruan/serverTiruan'
import type { PeranRinci } from './tipePengaturan'

const adapterAsli = klien.defaults.adapter
const AKUN_KASIR = uid(8)
let server: ServerTiruan

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerTiruan())
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  aturIzin(['pengaturan.akun.lihat', 'pengaturan.akun.perbaiki', 'pengaturan.peran.lihat', 'pengaturan.peran.perbaiki', 'pengaturan.keamanan.lihat', 'pengaturan.keamanan.perbaiki'])
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

// Komponen uji beragam (SFC); props diperiksa saat runtime.
async function pasang(komponen: any, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  const w = mount(komponen, {
    props,
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
  return w
}
async function tenang() {
  for (let i = 0; i < 4; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, 10))
  }
}
const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
async function klik(el: HTMLElement | undefined) {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
async function isi(label: string, nilai: string, akar: ParentNode = document) {
  const lbl = [...akar.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '').startsWith(label))
  expect(lbl, `label ${label}`).toBeTruthy()
  const el = document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang()
}
const dialog = () => document.querySelector('[role="alertdialog"], [role="dialog"]') as HTMLElement
const sakelar = () => document.querySelector('[role="switch"]') as HTMLButtonElement

describe('Perangkat tepercaya milik sendiri (tiket 1.13)', () => {
  it('daftar aktif dengan penanda perangkat ini; riwayat menampilkan yang dicabut dengan alasannya; cabut tanpa alasan', async () => {
    await pasang(PerangkatPage)
    const teks = () => document.body.textContent ?? ''
    expect(teks()).toContain('Chrome di Windows')
    expect(teks()).toContain('Perangkat ini')
    expect(teks()).not.toContain('Firefox di Linux')

    await klik(sakelar())
    expect(teks()).toContain('Firefox di Linux')
    expect(teks()).toContain('Dicabut: dicabut pemilik akun')
    await klik(sakelar())

    await klik(tombol('Cabut'))
    expect(dialog().textContent).toContain('Ini perangkat yang sedang Anda pakai')
    expect(dialog().querySelector('textarea')).toBeNull()
    await klik(tombol('Cabut perangkat', dialog()))
    expect(teks()).toContain('Chrome di Windows dicabut.')
    expect(teks()).toContain('Belum ada perangkat tepercaya yang aktif')
  })
})

describe('Tab Perangkat di rincian akun (tiket 1.13)', () => {
  async function bukaTab(akunId: string) {
    await pasang(RincianAkun, { akunId, indeks: null })
    const tab = [...document.querySelectorAll('[role="tab"]')].find((t) => t.textContent === 'Perangkat') as HTMLElement
    tab.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await tenang()
  }

  it('admin mencabut perangkat akun lain dengan alasan wajib; perangkat_ini tidak tampil untuk akun lain', async () => {
    await bukaTab(AKUN_KASIR)
    expect(document.body.textContent).toContain('Edge di Windows')
    expect(document.body.textContent).not.toContain('Perangkat ini')
    await klik(tombol('Cabut'))
    await klik(tombol('Cabut perangkat', dialog()))
    expect(dialog().textContent).toContain('Tulis alasan, minimal 5 karakter.')
    await isi('Alasan', 'Laptop dilaporkan hilang', dialog())
    await klik(tombol('Cabut perangkat', dialog()))
    expect(document.body.textContent).toContain('Perangkat Edge di Windows milik contoh.kasir dicabut.')
    const r = server.tangani({ metode: 'GET', jalur: `/akun/${AKUN_KASIR}/perangkat`, kueri: new URLSearchParams('status=semua'), header: {}, isi: null })!
    expect((r.isi as { data: Record<string, unknown>[] }).data[0]).toMatchObject({ status: 'dicabut', alasan_cabut: 'dicabut_admin', keterangan_cabut: 'Laptop dilaporkan hilang' })
  })

  it('akun sendiri: daftar tampil tanpa tombol cabut (pakai menu akun)', async () => {
    await bukaTab(ID_SUPERADMIN)
    expect(document.body.textContent).toContain('Chrome di Windows')
    expect(tombol('Cabut')).toBeUndefined()
  })
})

describe('Pengaturan → Keamanan (tiket 1.13)', () => {
  it('batas dari server ditampilkan; di atas batas ditolak sebelum dikirim; simpan dengan dasar; 0 = mati', async () => {
    await pasang(KeamananPage)
    const kolom = () => [...document.querySelectorAll('input[inputmode="numeric"]')][0] as HTMLInputElement
    expect(kolom().value).toBe('30')
    expect(document.body.textContent).toContain('Superadmin7 hari')
    await isi('Masa percaya bawaan', '31')
    await isi('Dasar perubahan', 'SK Direktur 9/2026')
    await klik(tombol('Simpan kebijakan'))
    expect(document.body.textContent).toContain('Isi 0 sampai 30 hari.')

    await isi('Masa percaya bawaan', '0')
    await klik(tombol('Simpan kebijakan'))
    expect(document.body.textContent).toContain('Mati')
    const r = server.tangani({ metode: 'GET', jalur: '/kebijakan-keamanan', kueri: new URLSearchParams(), header: {}, isi: null })!
    expect((r.isi as { data: { perangkat_tepercaya_hari: number } }).data.perangkat_tepercaya_hari).toBe(0)
  })

  it('412: kebijakan diubah orang lain → muat ulang', async () => {
    await pasang(KeamananPage)
    server.tangani({ metode: 'PUT', jalur: '/kebijakan-keamanan', kueri: new URLSearchParams(), header: { 'if-match': '"1"' }, isi: { perangkat_tepercaya_hari: 14, dasar_perubahan: 'orang lain' } })
    await isi('Masa percaya bawaan', '7')
    await isi('Dasar perubahan', 'SK Direktur 10/2026')
    await klik(tombol('Simpan kebijakan'))
    expect(document.body.textContent).toContain('Kebijakan diubah orang lain')
    await klik(tombol('Muat ulang kebijakan'))
    expect(([...document.querySelectorAll('input[inputmode="numeric"]')][0] as HTMLInputElement).value).toBe('14')
  })

  it('tanpa izin perbaiki: hanya lihat', async () => {
    aturIzin(['pengaturan.keamanan.lihat'])
    await pasang(KeamananPage)
    expect(document.body.textContent).toContain('Anda hanya bisa melihat kebijakan ini.')
    expect(tombol('Simpan kebijakan')).toBeUndefined()
  })
})

describe('Masa percaya di info peran (tiket 1.13)', () => {
  const peran = (kode: string) => {
    const daftar = server.tangani({ metode: 'GET', jalur: '/peran', kueri: new URLSearchParams(), header: {}, isi: null })!.isi as { data: { id: string; kode: string }[] }
    const id = daftar.data.find((p) => p.kode === kode)!.id
    const r = server.tangani({ metode: 'GET', jalur: `/peran/${id}`, kueri: new URLSearchParams(), header: {}, isi: null })!
    return { peran: (r.isi as { data: PeranRinci }).data, etag: r.header!.ETag! }
  }

  it('kosong = ikut instalasi (N hari); di atas batas peran ditolak; nilai tersimpan', async () => {
    const { peran: p, etag } = peran('contoh_kasir')
    const w = await pasang(DialogInfoPeran, { mode: 'ubah', peran: p, etag, open: false })
    await w.setProps({ open: true })
    await tenang()
    expect(dialog().textContent).toContain(`Kosong = ikut kebijakan instalasi (30 hari)`)
    expect(dialog().textContent).toContain(`Maksimum ${p.batas_masa_percaya_hari} hari untuk peran ini.`)
    await isi('Masa percaya perangkat', String(p.batas_masa_percaya_hari! + 1), dialog())
    await isi('Dasar perubahan', 'SK Direktur 11/2026', dialog())
    await klik(tombol('Simpan', dialog()))
    expect(dialog().textContent).toContain('Kosongkan, atau isi 0 sampai batas hari.')
    await isi('Masa percaya perangkat', '3', dialog())
    await klik(tombol('Simpan', dialog()))
    expect(peran('contoh_kasir').peran.masa_percaya_hari).toBe(3)
  })
})
