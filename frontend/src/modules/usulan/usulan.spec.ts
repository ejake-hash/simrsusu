import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, type PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { PIN_TIRUAN } from '@/modules/ttd/tiruan/serverTtd'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { daftarUsulan } from './api'
import DaftarUsulan from './components/DaftarUsulan.vue'
import DialogUsulan from './components/DialogUsulan.vue'
import RincianUsulan from './components/RincianUsulan.vue'
import type { UsulanDataInduk } from './tipe'

const adapterAsli = klien.defaults.adapter
let server: ReturnType<typeof buatServerGabungan>
let permintaan: PermintaanTiruan[]

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan())
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', pegawai: { id: 'p', nama: 'Contoh Superadmin' }, ttd: server.ttd.status(ID_SUPERADMIN) })
  aturIzin(['master_data.usulan.lihat', 'master_data.usulan.input', 'master_data.usulan.verifikasi', 'master_data.usulan.setujui', 'master_data.organisasi.lihat'])
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
async function pilihLokasi(label: string, ketik: string, opsi: RegExp, akar: ParentNode = document) {
  const el = isian(label, akar)
  el.focus()
  el.value = ketik
  el.dispatchEvent(new Event('input'))
  await tenang(300)
  const o = [...document.querySelectorAll<HTMLElement>('[role="option"]')].find((x) => opsi.test(x.textContent ?? ''))
  expect(o, `opsi ${opsi}`).toBeTruthy()
  o!.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
  await tenang()
}
async function usulan(tahap: UsulanDataInduk['tahap']): Promise<UsulanDataInduk> {
  return (await daftarUsulan({})).data.find((u) => u.tahap === tahap)!
}
const rincian = () => document.querySelector('section[aria-labelledby="judul-rincian-usulan"]') as HTMLElement

describe('Daftar usulan', () => {
  it('kartu ringkasan dari meta.jumlah; filter Terbuka, Perlu saya, Semua dikirim ke server', async () => {
    await pasang(DaftarUsulan, { modelValue: null })
    const kpi = document.querySelector('section[aria-label="Ringkasan usulan"]')!.textContent!
    expect(kpi).toMatch(/Menunggu verifikasi\s*1/)
    expect(kpi).toMatch(/Menunggu persetujuan\s*1/)
    expect(kpi).toMatch(/Dikembalikan\s*1/)
    expect(permintaan.find((p) => p.jalur === '/usulan-data-induk')!.kueri.get('tahap')).toBe('terbuka')
    expect(document.querySelectorAll('tbody tr')).toHaveLength(3)
    ;(document.querySelector('input[value="perlu_saya"]') as HTMLInputElement).click()
    await tenang()
    expect(permintaan.filter((p) => p.jalur === '/usulan-data-induk').slice(-1)[0]!.kueri.get('perlu_saya')).toBe('true')
  })
})

describe('Rincian dan tindakan', () => {
  it('diff berlabel dari tampilan; verifikasi → menunggu persetujuan; pemverifikasi tidak melihat tombol Setujui', async () => {
    const u = await usulan('diusulkan')
    await pasang(RincianUsulan, { id: u.id })
    const diff = rincian().querySelector('table.diff')!
    expect(diff.textContent).toContain('Kapasitas maksimum')
    expect(diff.querySelector('.lama')?.textContent).toBe('2')
    expect(diff.querySelector('.baru')?.textContent).toBe('3')
    await klik(tombol('Verifikasi', rincian()))
    expect(dialog()?.textContent).toContain('Anda tidak bisa menyetujui usulan yang Anda verifikasi')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-tindakan-usulan"]'))
    expect(dialog()).toBeNull()
    expect(rincian().querySelector('.kartu-kepala .pil')?.textContent).toBe('Menunggu persetujuan')
    expect(tombol('Setujui', rincian())).toBeUndefined()
    expect(rincian().textContent).toContain('tiga orang berbeda')
    expect(daftarToast.value.map((t) => t.pesan)).toContain(`${u.nomor} diverifikasi; diteruskan ke penyetuju.`)
  })

  it('setujui usulan terverifikasi orang lain → terjadwal berlaku pada tanggalnya', async () => {
    const u = await usulan('diverifikasi')
    await pasang(RincianUsulan, { id: u.id })
    expect(rincian().textContent).toContain('TT baru 3')
    await klik(tombol('Setujui', rincian()))
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-tindakan-usulan"]'))
    expect(rincian().querySelector('.kartu-kepala .pil')?.textContent).toBe('Terjadwal berlaku')
    expect(rincian().textContent).toContain('berlaku otomatis')
  })

  it('kembalikan wajib catatan minimal 5 karakter', async () => {
    const u = await usulan('diusulkan')
    await pasang(RincianUsulan, { id: u.id })
    await klik(tombol('Kembalikan', rincian()))
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-tindakan-usulan"]'))
    expect(dialog()?.textContent).toContain('Tulis catatan pengembalian, minimal 5 karakter.')
    await isi('Catatan pengembalian', 'Lampirkan memo bangsal')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-tindakan-usulan"]'))
    expect(rincian().querySelector('.kartu-kepala .pil')?.textContent).toBe('Dikembalikan')
    expect(rincian().textContent).toContain('“Lampirkan memo bangsal”')
  })

  it('pengusul memperbaiki usulan yang dikembalikan dan mengirim ulang → putaran 2, kembali menunggu verifikasi', async () => {
    const u = await usulan('dikembalikan')
    await pasang(RincianUsulan, { id: u.id })
    expect(rincian().textContent).toContain('“Lampirkan memo renovasi dari IPSRS.”')
    await klik(tombol('Perbaiki dan kirim ulang', rincian()))
    const d = dialog()!
    expect(d.querySelector('h2')?.textContent).toBe(`Perbaiki dan kirim ulang ${u.nomor}`)
    expect(isian('Nama ruang', d).value).toBe('Ruang Melati 102 (renovasi)')
    // Isian yang tidak diubah memakai nilai data induk saat ini (dimuat ulang dari 1.8b), bukan kosong/bawaan.
    expect(isian('Fungsi', d).value).toBe('rawat_inap')
    expect(isian('Status', d).value).toBe('aktif')
    await isi('Nama ruang', 'Ruang Melati 102 (renovasi tahap 1)', d)
    await isi('Dasar', 'Memo IPSRS 12/2026', d)
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-usulan"]'))
    expect(dialog()).toBeNull()
    const post = permintaan.find((p) => p.jalur.endsWith('/kirim-ulang'))!
    expect((post.isi as { perubahan: unknown[] }).perubahan).toEqual([{ objek_jenis: 'ruang', aksi: 'ubah', objek_id: expect.any(String), nilai_baru: { nama: 'Ruang Melati 102 (renovasi tahap 1)' } }])
    expect(rincian().querySelector('.nomor')?.textContent).toContain('putaran 2')
    expect(rincian().querySelector('.kartu-kepala .pil')?.textContent).toBe('Menunggu verifikasi')
  })

  it('pihak putaran menandatangani usulan dengan TTE; badge terverifikasi', async () => {
    server.ttd.aktifkan(ID_SUPERADMIN)
    useSesiStore().pengguna = { ...useSesiStore().pengguna!, ttd: server.ttd.status(ID_SUPERADMIN) }
    const u = await usulan('diusulkan')
    await pasang(RincianUsulan, { id: u.id })
    expect(tombol('Tandatangani usulan', rincian())).toBeUndefined()
    await klik(tombol('Verifikasi', rincian()))
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-tindakan-usulan"]'))
    await klik(tombol('Tandatangani usulan', rincian()))
    ;(dialog()!.querySelector('label.centang input') as HTMLInputElement).click()
    await tenang()
    await isi('PIN tanda tangan', PIN_TIRUAN)
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-ttd"]'))
    expect(permintaan.find((p) => p.jalur === '/ttd/tandatangani')?.isi).toMatchObject({ objek: [{ jenis: 'usulan_data_induk', id: u.id }] })
    expect(rincian().querySelector('section[aria-labelledby="judul-ttd-usulan"]')?.textContent).toMatch(/Ditandatangani Contoh Superadmin, .* · terverifikasi/)
    expect(tombol('Tandatangani usulan', rincian())).toBeUndefined()
  })
})

describe('Ajukan usulan', () => {
  it('validasi klien; objek yang sedang diusulkan → 409 di butirnya dengan nomor usulan; objek lain → diajukan', async () => {
    const w = await pasang(DialogUsulan, { open: false })
    await w.setProps({ open: true })
    await tenang()
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-usulan"]'))
    expect(dialog()?.textContent).toContain('Pilih jenis perubahan.')
    expect(dialog()?.textContent).toContain('Pilih tanggal berlaku.')

    const d = dialog()!
    await isi('Jenis perubahan', 'kamar.ubah', d)
    await pilihLokasi('Kamar (ruang)', '101', /Ruang Melati 101/, d)
    expect(isian('Kapasitas maksimum TT', d).value).toBe('2')
    await isi('Kapasitas maksimum TT', '4', d)
    const besok = new Date(Date.now() + 86_400_000)
    await isi('Berlaku mulai', `${besok.getFullYear()}-${String(besok.getMonth() + 1).padStart(2, '0')}-${String(besok.getDate()).padStart(2, '0')}`, d)
    await isi('Dasar', 'Memo Keperawatan 30/2026', d)
    await isi('Alasan', 'Tambah satu TT cadangan', d)
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-usulan"]'))
    expect(dialog()?.textContent).toMatch(/sedang diusulkan di UDI-2026-\d+/)

    await isi('Jenis perubahan', 'kamar.ubah', d)
    ;(isian('Jenis perubahan', d) as unknown as HTMLSelectElement).dispatchEvent(new Event('change'))
    await tenang()
    await pilihLokasi('Kamar (ruang)', '102', /Ruang Melati 102/, d)
    await isi('Kapasitas maksimum TT', '6', d)
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-usulan"]'))
    expect(dialog()).toBeNull()
    const post = permintaan.filter((p) => p.metode === 'post' && p.jalur === '/usulan-data-induk').slice(-1)[0]!
    expect((post.isi as { jenis_data: string; perubahan: unknown[] }).jenis_data).toBe('ruang_tt')
    expect((post.isi as { perubahan: unknown[] }).perubahan).toEqual([{ objek_jenis: 'kamar', aksi: 'ubah', objek_id: expect.any(String), nilai_baru: { kapasitas_maksimum: 6 } }])
    expect((w.emitted('selesai')?.[0]?.[0] as UsulanDataInduk).tahap).toBe('diusulkan')
  })
})
