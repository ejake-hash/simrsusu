import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import KotakKerja from '@/modules/beranda/components/KotakKerja.vue'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, type PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { ambilJenisTugas, daftarJenisTugas } from './api'
import DialogJenisTugas from './components/DialogJenisTugas.vue'
import LoncengNotifikasi from './components/LoncengNotifikasi.vue'
import PreferensiNotifikasi from './components/PreferensiNotifikasi.vue'

const adapterAsli = klien.defaults.adapter
let server: ReturnType<typeof buatServerGabungan>
let router: Router
let permintaan: PermintaanTiruan[]

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan())
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  aturIzin(['master_data.jenis_tugas.lihat', 'master_data.jenis_tugas.perbaiki', 'master_data.organisasi.lihat'])
})
afterEach(() => {
  vi.useRealTimers()
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any, props: Record<string, unknown> = {}) {
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  const w = mount(komponen, {
    props,
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
const klik = async (el: HTMLElement | null | undefined) => {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
const lonceng = () => document.querySelector<HTMLButtonElement>('button[aria-controls^="panel-notifikasi"]')!
const permintaanNotif = () => permintaan.filter((p) => p.jalur === '/saya/notifikasi')

describe('Lonceng notifikasi', () => {
  it('jumlah belum dibaca di label; membuka notifikasi menandainya dibaca lalu membuka tautannya', async () => {
    await pasang(LoncengNotifikasi)
    expect(lonceng().getAttribute('aria-label')).toBe('Notifikasi, 3 belum dibaca')
    expect(permintaanNotif()[0]!.kueri.get('status')).toBe('belum_dibaca')
    // Muat awal = permintaan biasa (memperpanjang sesi).
    expect(permintaanNotif()[0]!.header['x-permintaan-latar']).toBeUndefined()

    await klik(lonceng())
    expect(lonceng().getAttribute('aria-expanded')).toBe('true')
    const item = [...document.querySelectorAll<HTMLButtonElement>('.panel .item')].find((b) => b.textContent?.includes('Permintaan akses baru'))!
    await klik(item)
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur.endsWith('/dibaca'))).toBe(true)
    expect(router.currentRoute.value.fullPath).toMatch(/^\/pengaturan\/akun\?permintaan=/)
    expect(lonceng().getAttribute('aria-label')).toBe('Notifikasi, 2 belum dibaca')
  })

  it('tandai semua dibaca mengirim `sampai` notifikasi terbaru yang tampil; yang kritis tetap belum dibaca', async () => {
    await pasang(LoncengNotifikasi)
    await klik(lonceng())
    expect(document.querySelector('.panel')?.textContent).toContain('Notifikasi kritis tidak ikut ditandai sekaligus')
    await klik(tombol('Tandai semua dibaca'))
    const post = permintaan.find((p) => p.jalur === '/saya/notifikasi/semua-dibaca')!
    expect((post.isi as { sampai: string }).sampai).toMatch(/^\d{4}-\d{2}-\d{2}T/)
    expect(lonceng().getAttribute('aria-label')).toBe('Notifikasi, 1 belum dibaca')
    expect(document.querySelector('.panel')?.textContent).toContain('Akun Anda sempat terkunci')
  })

  it('polling 30 detik: permintaan latar dengan If-None-Match; 304 saat sama; notifikasi baru menaikkan jumlah', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    await pasang(LoncengNotifikasi)
    const awal = permintaanNotif().length
    vi.advanceTimersByTime(30_000)
    await tenang()
    const latar = permintaanNotif().slice(awal)
    expect(latar).toHaveLength(1)
    expect(latar[0]!.header['x-permintaan-latar']).toBe('1')
    expect(latar[0]!.header['if-none-match']).toMatch(/^"/)
    expect(lonceng().getAttribute('aria-label')).toBe('Notifikasi, 3 belum dibaca')

    server.notifikasi.tambahNotifikasi({ jenis: 'PERMINTAAN_AKSES', judul: 'Permintaan akses baru dari contoh.perawat' })
    vi.advanceTimersByTime(30_000)
    await tenang()
    expect(lonceng().getAttribute('aria-label')).toBe('Notifikasi, 4 belum dibaca')
  })
})

describe('Kotak kerja', () => {
  it('tab berlabel jumlah dari server; tab dieskalasi menampilkan teguran dan terlambat; riwayat tanpa penerima', async () => {
    await pasang(KotakKerja)
    const tab = [...document.querySelectorAll('[role="tab"]')].map((t) => t.textContent?.trim())
    expect(tab).toEqual(['Tugas saya (2)', 'Sudah dieskalasi (1)', 'Unit saya (1)', 'Selesai bulan ini (1)'])
    // Urut tenggat terdekat dulu.
    const judul = [...document.querySelectorAll('[role="tabpanel"] .judul')].map((j) => j.textContent?.trim())
    expect(judul[0]).toBe('Putuskan persetujuan PSA-2026-000101')
    expect(document.querySelector('[role="tabpanel"] a.judul')?.getAttribute('href')).toBe('/pengaturan/persetujuan')

    const dieskalasi = [...document.querySelectorAll<HTMLElement>('[role="tab"]')].find((t) => t.textContent?.startsWith('Sudah dieskalasi'))!
    dieskalasi.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    dieskalasi.click()
    await tenang()
    expect(permintaan.some((p) => p.jalur === '/saya/tugas' && p.kueri.get('tab') === 'dieskalasi')).toBe(true)
    const panel = document.querySelector('[role="tabpanel"]:not([hidden])') as HTMLElement
    expect(panel.textContent).toContain('Teguran')
    expect(panel.textContent).toContain('Kritis')
    expect(panel.textContent).toMatch(/Tenggat lewat/)

    await klik(panel.querySelector<HTMLButtonElement>('button[aria-label^="Riwayat:"]'))
    const dialog = document.querySelector('[role="dialog"]') as HTMLElement
    expect(dialog.textContent).toContain('Teguran')
    expect(dialog.textContent).toContain('Tanpa penerima')
    expect(dialog.textContent).toContain('kepala unit belum ditetapkan')
  })
})

describe('Preferensi notifikasi', () => {
  it('kanal kritis dan pesan singkat terkunci dengan alasan; simpan hanya mengirim yang berubah', async () => {
    await pasang(PreferensiNotifikasi)
    const baris = (nama: string) => [...document.querySelectorAll('tbody tr')].find((t) => t.querySelector('th')?.textContent?.includes(nama)) as HTMLElement
    const emailTerkunci = baris('Akun terkunci').querySelector<HTMLInputElement>('input[aria-label^="Email"]')!
    expect(emailTerkunci.disabled).toBe(true)
    expect(baris('Akun terkunci').textContent).toContain('Kritis: selalu dikirim')
    expect(baris('Permintaan akses menunggu').querySelector<HTMLInputElement>('input[aria-label^="Pesan singkat"]')!.disabled).toBe(true)

    const email = baris('Permintaan akses menunggu').querySelector<HTMLInputElement>('input[aria-label^="Email"]')!
    email.click()
    await tenang()
    await klik(document.querySelector<HTMLButtonElement>('form button[type="submit"]'))
    const put = permintaan.find((p) => p.metode === 'put')!
    expect(put.isi).toEqual({ preferensi: [{ jenis: 'PERMINTAAN_AKSES', email: false }] })
  })
})

describe('Jenis tugas', () => {
  it('eskalasi lebih awal dari teguran ditolak di klien; PATCH mengirim menit dan hanya yang berubah, dengan If-Match', async () => {
    const j = (await daftarJenisTugas({})).data.find((x) => x.kode === 'PERMINTAAN_AKSES')!
    const w = await pasang(DialogJenisTugas, { jenis: await ambilJenisTugas(j.id), open: false })
    await w.setProps({ open: true })
    await tenang()
    const isian = (label: string) => {
      const lbl = [...document.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)!
      return document.getElementById(lbl.getAttribute('for')!) as HTMLInputElement
    }
    const isi = async (label: string, nilai: string) => {
      const el = isian(label)
      el.value = nilai
      el.dispatchEvent(new Event('input'))
      await tenang()
    }
    expect(isian('Tenggat sesudah tugas dibuat (jam)').value).toBe('48')
    await isi('Teguran sesudah', '24')
    await isi('Eskalasi sesudah', '12')
    await isi('Dasar perubahan', 'Kebijakan RS 01/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-jenis-tugas"]'))
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Eskalasi tidak boleh lebih awal dari teguran.')
    expect(permintaan.some((p) => p.metode === 'patch')).toBe(false)

    await isi('Eskalasi sesudah', '36')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-jenis-tugas"]'))
    const patch = permintaan.find((p) => p.metode === 'patch')!
    expect(patch.header['if-match']).toBe('"1"')
    expect(patch.isi).toEqual({ dasar_perubahan: 'Kebijakan RS 01/2026', eskalasi: { pengingat_menit: -240, teguran_menit: 1440, eskalasi_menit: 2160 } })
    expect(w.emitted('selesai')).toBeTruthy()
  })
})
