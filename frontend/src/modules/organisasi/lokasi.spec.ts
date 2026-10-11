import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, type PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import TabBangsal from './components/lokasi/TabBangsal.vue'
import TabLokasi from './components/lokasi/TabLokasi.vue'

const adapterAsli = klien.defaults.adapter
let router: Router
let permintaan: PermintaanTiruan[]

beforeEach(() => {
  setActivePinia(createPinia())
  const server = pasangTiruan(klien, buatServerGabungan())
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  aturIzin(['master_data.organisasi.lihat', 'master_data.organisasi.input', 'master_data.organisasi.perbaiki'])
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any, jalur = '/') {
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push(jalur)
  const w = mount(komponen, {
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
  return w
}
async function tenang(ms = 10) {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, ms))
  }
}
const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
const klik = async (el: HTMLElement | undefined) => {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
function isian(label: string): HTMLInputElement {
  const lbl = [...document.querySelectorAll('[role="dialog"] label[for], [role="alertdialog"] label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
async function isi(label: string, nilai: string) {
  const el = isian(label)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await tenang()
}
const tekan = (el: Element, key: string) => el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))
const item = (nama: string) => [...document.querySelectorAll('[role="treeitem"]')].find((t) => t.querySelector('.label')?.textContent?.trim().startsWith(nama)) as HTMLElement | undefined
async function bukaCabang(...nama: string[]) {
  for (const n of nama) {
    tekan(item(n)!, 'ArrowRight')
    await tenang()
  }
}
const rincian = () => document.querySelector('section[aria-labelledby="judul-rincian-lokasi"]') as HTMLElement

describe('Tab lokasi & tempat tidur', () => {
  it('pohon muat-lambat: gedung → lantai → ruang; ruang yang sudah kamar menampilkan TT, kapasitas, dan atribut', async () => {
    await pasang(TabLokasi)
    expect(item('Gedung A (contoh)')).toBeTruthy()
    expect(item('Gedung B (contoh)')).toBeTruthy()
    expect(item('Lantai 1')).toBeUndefined()
    await bukaCabang('Gedung A (contoh)', 'Lantai 1')
    await klik(item('Ruang Melati 101'))
    expect(router.currentRoute.value.query.lokasi).toBeTruthy()
    expect(rincian().querySelector('h2')?.textContent).toBe('Ruang Melati 101')
    expect(rincian().querySelector('nav[aria-label="Lokasi induk"]')?.textContent).toContain('Lantai 1')
    expect(tombol('Jadikan kamar', rincian())).toBeUndefined()
    expect(rincian().textContent).toContain('Tempat tidur (3)')
    expect(rincian().textContent).toContain('A-1-101-1')
  })

  it('jadikan ruang kamar: kapasitas dari input number terkirim sebagai bilangan; sesudahnya tombol Ubah kamar', async () => {
    await pasang(TabLokasi)
    await bukaCabang('Gedung A (contoh)', 'Lantai 2')
    await klik(item('Ruang Poli Anak'))
    await klik(tombol('Jadikan kamar', rincian()))
    expect(document.querySelector('[role="dialog"] h2')?.textContent).toContain('Jadikan A-2-201 kamar rawat')
    const bangsal = isian('Bangsal') as unknown as HTMLSelectElement
    const melati = [...bangsal.options].find((o) => o.textContent?.startsWith('MELATI'))!
    await isi('Bangsal', melati.value)
    // v-model pada <input type="number"> menyimpan number; dulu .trim() melempar dan formulir diam saja.
    await isi('Kapasitas maksimum TT', '2')
    await isi('Dasar perubahan', 'SK Direktur 20/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-kamar"]')!)
    const post = permintaan.find((p) => p.metode === 'post' && p.jalur.endsWith('/kamar'))
    expect(post?.isi).toMatchObject({ bangsal_id: melati.value, kapasitas_maksimum: 2, atribut_standar: {}, dasar_perubahan: 'SK Direktur 20/2026' })
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(tombol('Ubah kamar', rincian())).toBeTruthy()
  })

  it('kapasitas di luar 1–99 ditolak sebelum dikirim', async () => {
    await pasang(TabLokasi)
    await bukaCabang('Gedung A (contoh)', 'Lantai 2')
    await klik(item('Ruang Poli Anak'))
    await klik(tombol('Jadikan kamar', rincian()))
    await isi('Kapasitas maksimum TT', '0')
    await isi('Dasar perubahan', 'SK Direktur 20/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-kamar"]')!)
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Bilangan bulat 1–99')
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Pilih bangsal.')
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur.endsWith('/kamar'))).toBe(false)
  })

  it('cari menampilkan hasil berjalur; memilih hasil membuka cabangnya di pohon', async () => {
    await pasang(TabLokasi)
    const cari = document.getElementById('cari-lokasi') as HTMLInputElement
    cari.value = 'poli anak'
    cari.dispatchEvent(new Event('input'))
    await tenang(120)
    const hasil = [...document.querySelectorAll('.hasil-cari button')]
    expect(hasil).toHaveLength(1)
    expect(hasil[0]!.textContent).toContain('A-2-201')
    await klik(hasil[0] as HTMLElement)
    await tenang()
    expect(item('Ruang Poli Anak')?.getAttribute('aria-selected')).toBe('true')
  })
})

describe('Tab bangsal', () => {
  it('ringkasan TT per kelas (ekstra ikut dihitung) dan peringatan kamar melebihi kapasitas', async () => {
    await pasang(TabBangsal)
    const peringatan = document.querySelector('section[aria-labelledby="judul-melebihi"]')
    expect(peringatan?.textContent).toContain('1 kamar melebihi kapasitas maksimum')
    expect(peringatan?.textContent).toContain('3 TT dihitung, kapasitas 2')
    const baris = [...document.querySelectorAll('tr')].find((t) => t.textContent?.includes('Bangsal Melati (contoh)'))!
    expect(baris.textContent).toContain('Kelas 3: 3 dihitung (1 ekstra)')
    expect(baris.textContent).toContain('1 ditutup')

    await klik(peringatan!.querySelector('button') as HTMLElement)
    expect(router.currentRoute.value.query).toMatchObject({ tab: 'lokasi' })
    expect(router.currentRoute.value.query.lokasi).toBeTruthy()
  })

  it('nonaktifkan bangsal yang masih punya TT → 409 dengan jumlah TT', async () => {
    await pasang(TabBangsal)
    await klik(document.querySelector<HTMLButtonElement>('button[aria-label="Nonaktifkan Bangsal Melati (contoh)"]')!)
    await isi('Dasar perubahan', 'SK Direktur 21/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-status-org"]')!)
    expect(document.querySelector('[role="alertdialog"], [role="dialog"]')?.textContent).toContain('4 tempat tidur belum ditutup')
  })
})
