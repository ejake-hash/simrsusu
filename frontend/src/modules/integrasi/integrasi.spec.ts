import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import type { PermintaanTiruan, ResponsTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import DaftarKiriman from './components/DaftarKiriman.vue'
import DaftarLog from './components/DaftarLog.vue'
import DaftarSistem from './components/DaftarSistem.vue'
import { buatServerIntegrasi } from './tiruan/serverIntegrasi'

const adapterAsli = klien.defaults.adapter
const SEMUA = ['pengaturan.integrasi.lihat', 'pengaturan.integrasi.perbaiki', 'integrasi.satusehat.lihat', 'integrasi.satusehat.perbaiki', 'integrasi.bpjs.lihat', 'integrasi.bpjs.perbaiki', 'integrasi.log.lihat']
let izin: Set<string>
let server: ReturnType<typeof buatServerIntegrasi>
let permintaan: PermintaanTiruan[]

function siapkan(daftarIzin: string[]) {
  izin = new Set(daftarIzin)
  aturIzin(daftarIzin)
  server = buatServerIntegrasi({ boleh: (k) => izin.has(k), pelaku: () => ({ id: 'p1', nama: 'Contoh Pengelola' }) })
  permintaan = []
  pasangTiruan(klien, { tangani: (r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null => (permintaan.push(r), server.tangani(r)) })
}
beforeEach(() => {
  setActivePinia(createPinia())
  siapkan(SEMUA)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  mount(komponen, {
    props,
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
}
async function tenang(ms = 10) {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, ms))
  }
}
const kartu = (kode: string) => document.querySelector(`section[aria-labelledby="judul-sistem-${kode}"]`) as HTMLElement
const ling = (kode: string, l: string) => document.querySelector(`section[aria-labelledby="judul-ling-${kode}-${l}"]`) as HTMLElement
const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
const klik = async (el: HTMLElement | null | undefined) => {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
const dialog = () => document.querySelector('[role="dialog"], [role="alertdialog"]') as HTMLElement | null
function isian(label: string, akar: ParentNode = document): HTMLInputElement {
  const lbl = [...akar.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
async function isi(label: string, nilai: string, akar: ParentNode = document) {
  const el = isian(label, akar)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await tenang()
}
const pesanToast = () => daftarToast.value.map((t) => t.pesan).join(' | ')

describe('Konfigurasi integrasi', () => {
  it('kartu per sistem dari server: rahasia hanya "terisi ····akhir", kolom bukan rahasia utuh, circuit breaker terbuka tampil', async () => {
    await pasang(DaftarSistem)
    expect(document.querySelectorAll('section.kartu-sistem')).toHaveLength(3)
    const ss = ling('satusehat', 'sandbox')
    expect(ss.textContent).toContain('Aktif')
    expect(ss.textContent).toContain('contoh-client-sandbox')
    expect(ss.textContent).toContain('Terisi ····abcd')
    expect(document.body.textContent).not.toContain('rahasia-sintetis')
    expect(kartu('aplicares').textContent).toContain('Terputus sementara')
    expect(kartu('aplicares').querySelector('[aria-label="Kotak keluar"]')?.textContent).toMatch(/Tertahan\s*1/)
    expect(kartu('bpjs_vclaim').textContent).toContain('Belum ada lingkungan aktif')
    expect(kartu('bpjs_vclaim').textContent).toContain('kiriman baru tertahan')
  })

  it('ubah kredensial tulis-saja: hanya isian yang diubah dikirim, aktifkan; nilai rahasia tidak tampil lagi', async () => {
    await pasang(DaftarSistem)
    await klik(tombol('Ubah kredensial', ling('bpjs_vclaim', 'sandbox')))
    const d = dialog()!
    expect(isian('Secret key', d).type).toBe('password')
    await isi('Cons ID', '54321', d)
    await isi('Secret key', 'rahasia-baru-sintetis-7777-qrst', d)
    await isi('User key', 'kunci-baru-sintetis-5555-mnop', d)
    const centang = d.querySelector<HTMLInputElement>('input[type="checkbox"]')!
    centang.click()
    await tenang()
    await klik(tombol('Simpan', d))
    expect(dialog()).toBeNull()
    const put = permintaan.find((p) => p.metode.toUpperCase() === 'PUT')!
    expect(put.isi).toEqual({ lingkungan: 'sandbox', aktifkan: true, kolom: { cons_id: '54321', secret_key: 'rahasia-baru-sintetis-7777-qrst', user_key: 'kunci-baru-sintetis-5555-mnop' } })
    expect(ling('bpjs_vclaim', 'sandbox').textContent).toContain('Aktif')
    expect(ling('bpjs_vclaim', 'sandbox').textContent).toContain('Terisi ····qrst')
    expect(document.body.textContent).not.toContain('rahasia-baru-sintetis')
  })

  it('aktifkan lingkungan yang belum lengkap → 422 di isiannya; uji koneksi berhasil dan gagal tampil di kartu', async () => {
    await pasang(DaftarSistem)
    await klik(tombol('Ubah kredensial', ling('satusehat', 'produksi')))
    const d = dialog()!
    await isi('Client ID', 'client-produksi', d)
    d.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click()
    await tenang()
    await klik(tombol('Simpan', d))
    expect(isian('Client secret', d).getAttribute('aria-invalid')).toBe('true')
    expect(d.textContent).toContain('Kredensial lingkungan ini belum lengkap.')

    document.querySelector<HTMLButtonElement>('[role="dialog"] button.tombol')?.click()
    await tenang()
    await klik(tombol('Uji koneksi', ling('satusehat', 'sandbox')))
    expect(kartu('satusehat').textContent).toMatch(/Uji koneksi sandbox .*berhasil \(HTTP 200\)/)
    server.sistem.get('satusehat')!.nilai.sandbox.client_secret = 'salah-rahasia-sintetis-0000'
    await klik(tombol('Uji koneksi', ling('satusehat', 'sandbox')))
    expect(kartu('satusehat').textContent).toContain('gagal (HTTP 401)')
    expect(pesanToast()).toContain('Kredensial ditolak server tujuan')
  })

  it('pemantau per tujuan: kartu tanpa kredensial dan tanpa tombol ubah', async () => {
    siapkan(['integrasi.bpjs.lihat'])
    await pasang(DaftarSistem)
    expect(document.querySelectorAll('section.kartu-sistem')).toHaveLength(2)
    expect(kartu('satusehat')).toBeNull()
    expect(tombol('Ubah kredensial')).toBeUndefined()
    expect(tombol('Uji koneksi')).toBeUndefined()
  })
})

describe('Kotak keluar', () => {
  it('kirim ulang hanya bila dapat_kirim_ulang; konfirmasi lalu kiriman kembali menunggu', async () => {
    await pasang(DaftarKiriman, { tujuan: ['satusehat'], sistem: [] })
    const baris = () => [...document.querySelectorAll('tbody tr')]
    expect(baris()).toHaveLength(4)
    expect(permintaan.find((p) => p.jalur === '/integrasi/kotak-keluar')?.kueri.get('tujuan')).toBe('satusehat')
    const tertahan = baris().find((b) => b.textContent?.includes('Lokasi induk belum terdaftar'))!
    expect(tertahan.textContent).toContain('perbaiki oleh IRNA Instalasi Rawat Inap')
    expect(baris().filter((b) => tombol('Kirim ulang', b))).toHaveLength(2)
    await klik(tombol('Kirim ulang', tertahan))
    await klik(tombol('Kirim ulang', dialog()!))
    expect(permintaan.some((p) => p.metode.toUpperCase() === 'POST' && p.jalur.endsWith('/kirim-ulang'))).toBe(true)
    expect(pesanToast()).toContain('diantrekan ulang ke SATUSEHAT')
    expect(baris().find((b) => b.textContent?.includes('Lokasi induk belum terdaftar'))).toBeUndefined()
  })

  it('tanpa izin perbaiki tujuan tidak ada tombol kirim ulang; rentang tanggal terbalik ditolak di klien', async () => {
    siapkan(['integrasi.bpjs.lihat'])
    await pasang(DaftarKiriman, { tujuan: ['bpjs_vclaim', 'aplicares'], sistem: [] })
    expect(document.querySelectorAll('tbody tr')).toHaveLength(2)
    expect(tombol('Kirim ulang')).toBeUndefined()
    await isi('Dari tanggal', '2026-10-12')
    await isi('Sampai tanggal', '2026-10-01')
    expect(document.body.textContent).toContain('Tidak boleh sebelum tanggal awal.')
  })
})

describe('Log integrasi', () => {
  it('daftar log disamarkan dalam lipatan; filter hasil dikirim ke server dengan halaman 20', async () => {
    await pasang(DaftarLog)
    const item = document.querySelectorAll('[aria-label="Daftar log"] > li')
    expect(item).toHaveLength(2)
    expect(item[0]!.querySelector('details pre')?.textContent).toContain('***')
    expect(permintaan.find((p) => p.jalur === '/integrasi/log')?.kueri.get('batas')).toBe('20')
    await isi('Hasil', 'gagal')
    const log = permintaan.filter((p) => p.jalur === '/integrasi/log')
    expect(log[log.length - 1]?.kueri.get('berhasil')).toBe('false')
  })
})
