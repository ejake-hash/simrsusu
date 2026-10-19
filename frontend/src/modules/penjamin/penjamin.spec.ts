import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, type PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { daftarPenjamin, semuaPenjaminAktif } from './api'
import { formatPlafon } from './penjamin'
import PenjaminPage from './pages/PenjaminPage.vue'

const adapterAsli = klien.defaults.adapter
let permintaan: PermintaanTiruan[]
const IZIN_PENUH = ['master_data.penjamin.lihat', 'master_data.penjamin.input', 'master_data.penjamin.perbaiki']

function pasangServer(izinServer?: string[]) {
  setActivePinia(createPinia())
  const gabungan = buatServerGabungan()
  const server = pasangTiruan(klien, gabungan)
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  return { gabungan, izinServer }
}
beforeEach(() => {
  pasangServer()
  aturIzin(IZIN_PENUH)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

async function pasang() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/master-data/penjamin')
  const w = mount(PenjaminPage, {
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
const tombolLabel = (label: string) => document.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`)
const klik = async (el: HTMLElement | null | undefined) => {
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
async function pilih(id: string, nilai: string) {
  const el = document.getElementById(id) as HTMLSelectElement
  el.value = nilai
  el.dispatchEvent(new Event('change'))
  await tenang()
}
const kodeDiTabel = () => [...document.querySelectorAll('tbody td code')].map((c) => c.textContent)

describe('formatPlafon', () => {
  it('memberi titik ribuan dan koma desimal; null = tanpa plafon', () => {
    expect(formatPlafon('25000000')).toBe('Rp 25.000.000')
    expect(formatPlafon('20000000.50')).toBe('Rp 20.000.000,50')
    expect(formatPlafon('1500.00')).toBe('Rp 1.500')
    expect(formatPlafon(null)).toBe('Tanpa plafon')
  })
})

describe('klien penjamin', () => {
  it('keyset: batas 2 memberi kursor berikutnya sampai habis; pilihan pendaftaran hanya yang aktif', async () => {
    const h1 = await daftarPenjamin({}, null, 2)
    expect(h1.data).toHaveLength(2)
    expect(h1.meta.kursor_berikutnya).toBeTruthy()
    const h2 = await daftarPenjamin({}, h1.meta.kursor_berikutnya, 50)
    expect(h2.meta.kursor_berikutnya).toBeNull()
    const aktif = await semuaPenjaminAktif()
    expect(aktif.length).toBeGreaterThan(0)
    expect(aktif.every((p) => p.status === 'aktif')).toBe(true)
    const semuaKode = [...h1.data, ...h2.data].map((p) => p.kode)
    expect(semuaKode).toContain('ASU-LAMA')
    expect(aktif.map((p) => p.kode)).not.toContain('ASU-LAMA')
  })
})

describe('Layar penjamin', () => {
  it('bawaan menampilkan penjamin aktif; penjamin nonaktif tetap terbaca lewat filter status', async () => {
    await pasang()
    expect(kodeDiTabel()).toContain('BPJS')
    expect(kodeDiTabel()).not.toContain('ASU-LAMA')
    await pilih('status-penjamin', 'nonaktif')
    expect(kodeDiTabel()).toEqual(['ASU-LAMA'])
    expect(permintaan.some((p) => p.kueri.get('status') === 'nonaktif')).toBe(true)
  })

  it('kode yang sama ditolak 422 validasi_gagal dan pesannya tampil di kolom Kode', async () => {
    await pasang()
    await klik(tombol('Tambah penjamin'))
    await isi('Kode', 'BPJS')
    await isi('Nama penjamin', 'BPJS ganda')
    await isi('Dasar perubahan', 'SK Direktur 21/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penjamin"]'))
    expect(permintaan[permintaan.length - 1]).toMatchObject({ metode: 'post' })
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('sudah dipakai penjamin lain')
    expect(document.querySelector('[role="dialog"]')).toBeTruthy()
  })

  it('tambah penjamin: plafon dan hari terkirim sebagai string dan bilangan; kosong menjadi null', async () => {
    await pasang()
    await klik(tombol('Tambah penjamin'))
    await isi('Kode', 'asu-baru')
    await isi('Nama penjamin', 'Asuransi Contoh Baru')
    await isi('Masa berlaku surat jaminan (hari)', '21')
    await isi('Plafon (Rp)', '1500000.50')
    await isi('Dasar perubahan', 'SK Direktur 22/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penjamin"]'))
    const post = permintaan.find((p) => p.metode === 'post')
    expect(post?.isi).toMatchObject({ kode: 'ASU-BARU', jenis: 'asuransi', masa_berlaku_gl_hari: 21, plafon: '1500000.50', jatuh_tempo_hari: null, dasar_perubahan: 'SK Direktur 22/2026' })
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(kodeDiTabel()).toContain('ASU-BARU')
  })

  it('plafon tidak sah ditolak sebelum dikirim', async () => {
    await pasang()
    await klik(tombol('Tambah penjamin'))
    await isi('Kode', 'X1')
    await isi('Nama penjamin', 'Contoh')
    await isi('Plafon (Rp)', '1.500.000')
    await isi('Dasar perubahan', 'SK Direktur 23/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penjamin"]'))
    expect(permintaan.some((p) => p.metode === 'post')).toBe(false)
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('tanpa titik ribuan')
  })

  it('ubah mengirim hanya kolom yang berubah dengan If-Match dari rincian; tanpa perubahan tidak dikirim', async () => {
    await pasang()
    await klik(tombolLabel('Ubah PT Contoh B'))
    await isi('Dasar perubahan', 'Tanpa isian lain')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penjamin"]'))
    expect(permintaan.some((p) => p.metode === 'patch')).toBe(false)
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Belum ada perubahan')
    await isi('Jatuh tempo penagihan (hari)', '90')
    await isi('Dasar perubahan', 'Kontrak baru 2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penjamin"]'))
    const patch = permintaan.find((p) => p.metode === 'patch')
    expect(patch?.isi).toEqual({ jatuh_tempo_hari: 90, dasar_perubahan: 'Kontrak baru 2026' })
    expect(patch?.header['if-match']).toBe('"1"')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('jenis penjamin yang sudah dipakai kunjungan tidak bisa diubah (422 di kolom Jenis)', async () => {
    await pasang()
    await klik(tombolLabel('Ubah Umum (bayar sendiri)'))
    await isi('Jenis', 'perusahaan')
    await isi('Dasar perubahan', 'Koreksi jenis')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penjamin"]'))
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('sudah dipakai kunjungan')
  })

  it('nonaktifkan lewat dialog: hilang dari daftar aktif; aktifkan kembali dari filter nonaktif', async () => {
    await pasang()
    await klik(tombolLabel('Nonaktifkan Jasa Raharja'))
    expect(document.querySelector('[role="alertdialog"]')?.textContent).toContain('tidak muncul lagi di pilihan pendaftaran')
    await isi('Dasar perubahan', 'Kerja sama berakhir')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-status-penjamin"]'))
    expect(permintaan.find((p) => p.metode === 'patch')?.isi).toEqual({ status: 'nonaktif', dasar_perubahan: 'Kerja sama berakhir' })
    expect(kodeDiTabel()).not.toContain('JR')
    await pilih('status-penjamin', 'nonaktif')
    expect(kodeDiTabel()).toContain('JR')
  })

  it('tanpa izin input dan perbaiki: tombol Tambah dan kolom Aksi tidak tampil', async () => {
    aturIzin(['master_data.penjamin.lihat'])
    await pasang()
    expect(tombol('Tambah penjamin')?.hidden ?? true).toBe(true)
    expect(tombol('Ubah')).toBeUndefined()
    expect([...document.querySelectorAll('th')].map((t) => t.textContent?.trim())).not.toContain('Aksi')
    expect(kodeDiTabel().length).toBeGreaterThan(0)
  })
})
