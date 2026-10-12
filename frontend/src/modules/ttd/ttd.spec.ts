import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import TtdModal from '@/design-system/components/TtdModal.vue'
import RincianPersetujuan from '@/modules/akses/components/persetujuan/RincianPersetujuan.vue'
import { uid } from '@/modules/akses/tiruan/dataPengaturan'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, ID_SUPERADMIN_DUA, SANDI_TIRUAN, type PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import type { PersetujuanAkses } from '@/modules/akses/tipePersetujuan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import DialogResetTtd from './components/DialogResetTtd.vue'
import TandaTanganPage from './pages/TandaTanganPage.vue'
import { PIN_TIRUAN, pinLemah } from './tiruan/serverTtd'

const adapterAsli = klien.defaults.adapter
const KASIR = uid(105)
let server: ReturnType<typeof buatServerGabungan>
let permintaan: PermintaanTiruan[]

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan())
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  masukSebagai(ID_SUPERADMIN, 'contoh.superadmin', 'Contoh Superadmin')
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

function masukSebagai(id: string, namaPengguna: string, nama: string) {
  server.akses.sebagai(id)
  useSesiStore().pengguna = penggunaTiruan({ id, nama_pengguna: namaPengguna, mfa_aktif: true, pegawai: { id: `${id}-p`, nama }, ttd: server.ttd.status(id) })
  aturIzin(['pengaturan.peran.lihat', 'pengaturan.akun.lihat', 'pengaturan.akun.perbaiki', 'pengaturan.persetujuan.lihat', 'sdm.kredensial.perbaiki'])
}
const minta = (metode: string, jalur: string, isi?: unknown, header: Record<string, string> = {}) =>
  server.tangani({ metode, jalur, kueri: new URLSearchParams(), header, isi }) as { status: number; isi: unknown; header?: Record<string, string> }
/** Superadmin mengajukan izin sensitif pengelola akses (penyetuju = peran Superadmin); superadmin kedua menyetujui. */
function persetujuanDiputuskanDua(): PersetujuanAkses {
  const awal = minta('GET', `/peran/${KASIR}`)
  const izin = [...(awal.isi as { data: { izin: string[] } }).data.izin, 'pengaturan.akun.lihat', 'pengaturan.akun.input']
  const r = minta('PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 2/2026' }, { 'if-match': awal.header!.ETag! })
  const p = (r.isi as { perubahan: { menunggu_persetujuan: PersetujuanAkses[] } }).perubahan.menunggu_persetujuan[0]!
  masukSebagai(ID_SUPERADMIN_DUA, 'contoh.superadmin.dua', 'Contoh Superadmin Kedua')
  expect(minta('POST', `/persetujuan-akses/${p.id}/setujui`, { catatan: null }).status).toBe(200)
  return p
}

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
function isian(label: string): HTMLInputElement {
  const lbl = [...document.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
async function isi(label: string, nilai: string) {
  const el = isian(label)
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang()
}
const centang = async () => {
  ;(dialog()!.querySelector('input[type="checkbox"]') as HTMLInputElement).click()
  await tenang()
}
const kirimTtd = () => klik(document.querySelector<HTMLButtonElement>('button[form="form-ttd"]'))

describe('Aturan PIN tiruan sama dengan kontrak', () => {
  it('menolak sama semua, berulang, dan berurutan; menerima PIN acak', () => {
    for (const p of ['111111', '121212', '123123', '123456', '987654', '012345', '12345', 'abcdef']) expect(pinLemah(p), p).toBe(true)
    expect(pinLemah(PIN_TIRUAN)).toBe(false)
  })
})

describe('Tanda tangan keputusan persetujuan', () => {
  it('pemutus menandatangani: wajib centang pernyataan dan PIN; PIN salah menyebut sisa percobaan; berhasil → badge terverifikasi dan sesi tanda tangan', async () => {
    const p = persetujuanDiputuskanDua()
    await pasang(RincianPersetujuan, { id: p.id })
    const bagian = document.querySelector('section[aria-labelledby="judul-ttd-persetujuan"]') as HTMLElement
    expect(bagian.textContent).toContain('Belum ditandatangani.')
    await klik(tombol('Tandatangani keputusan', bagian))
    expect(dialog()?.textContent).toContain('Penanda tangan: Contoh Superadmin Kedua')
    expect(dialog()?.textContent).toContain(p.nomor)

    await kirimTtd()
    expect(dialog()?.textContent).toContain('Centang pernyataan untuk menandatangani.')
    expect(dialog()?.textContent).toContain('Masukkan PIN 6 angka.')
    await centang()
    await isi('PIN tanda tangan', '000001')
    await kirimTtd()
    expect(dialog()?.textContent).toContain('Sisa 4 percobaan')
    const post = permintaan.filter((r) => r.jalur === '/ttd/tandatangani').slice(-1)[0]!
    expect(post.isi).toEqual({ objek: [{ jenis: 'persetujuan_akses', id: p.id }], pin: '000001', pernyataan_disetujui: true })

    await isi('PIN tanda tangan', PIN_TIRUAN)
    await kirimTtd()
    expect(dialog()).toBeNull()
    expect(daftarToast.value.map((t) => t.pesan)).toContain(`Keputusan ${p.nomor} ditandatangani.`)
    expect(bagian.textContent).toMatch(/Ditandatangani Contoh Superadmin Kedua, .* · terverifikasi/)
    expect(tombol('Tandatangani keputusan', bagian)).toBeUndefined()
    expect(useSesiStore().pengguna?.ttd.sesi_berlaku_sampai).toBeTruthy()
    // PIN tidak pernah masuk URL atau query string.
    expect(permintaan.some((r) => r.jalur.includes(PIN_TIRUAN) || [...r.kueri.values()].includes(PIN_TIRUAN))).toBe(false)
  })

  it('isi berubah sesudah ditandatangani → verifikasi ulang menandainya', async () => {
    const p = persetujuanDiputuskanDua()
    minta('POST', '/ttd/tandatangani', { objek: [{ jenis: 'persetujuan_akses', id: p.id }], pin: PIN_TIRUAN, pernyataan_disetujui: true })
    await pasang(RincianPersetujuan, { id: p.id })
    expect(document.body.textContent).toContain('· terverifikasi')
    server.ttd.rusakIsi(p.id)
    await klik(tombol('Verifikasi ulang'))
    expect(document.body.textContent).toMatch(/Isi berubah sesudah ditandatangani Contoh Superadmin Kedua/)
  })

  it('pengajuan yang ditarik pengajunya: tidak ada tombol tanda tangan (diputuskan_oleh terisi, tetapi bukan keputusan)', async () => {
    const awal = minta('GET', `/peran/${KASIR}`)
    const izin = [...(awal.isi as { data: { izin: string[] } }).data.izin, 'pengaturan.akun.lihat', 'pengaturan.akun.input']
    const r = minta('PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 4/2026' }, { 'if-match': awal.header!.ETag! })
    const p = (r.isi as { perubahan: { menunggu_persetujuan: PersetujuanAkses[] } }).perubahan.menunggu_persetujuan[0]!
    server.ttd.aktifkan(ID_SUPERADMIN)
    masukSebagai(ID_SUPERADMIN, 'contoh.superadmin', 'Contoh Superadmin')
    expect(minta('POST', `/persetujuan-akses/${p.id}/batalkan`, { alasan: 'Salah peran (sintetis)' }).status).toBe(200)
    await pasang(RincianPersetujuan, { id: p.id })
    expect(document.querySelector('section[aria-labelledby="judul-ttd-persetujuan"]')).toBeTruthy()
    expect(tombol('Tandatangani keputusan')).toBeUndefined()
  })

  it('bukan pemutus: tidak ada tombol tanda tangan, tetapi status verifikasi tetap terlihat', async () => {
    const p = persetujuanDiputuskanDua()
    masukSebagai(ID_SUPERADMIN, 'contoh.superadmin', 'Contoh Superadmin')
    await pasang(RincianPersetujuan, { id: p.id })
    expect(document.querySelector('section[aria-labelledby="judul-ttd-persetujuan"]')?.textContent).toContain('Belum ditandatangani.')
    expect(tombol('Tandatangani keputusan')).toBeUndefined()
  })
})

describe('TtdModal', () => {
  const dua = { id: ID_SUPERADMIN_DUA, nama: 'Contoh Superadmin Kedua' }
  const tandatangani = (objek: { id: string }[]) => async (pin: string | null, pernyataan: boolean) => {
    const { tandatangani: api } = await import('./api')
    await api(objek.map((o) => ({ jenis: 'persetujuan_akses' as const, id: o.id })), pin, pernyataan)
  }

  it('massal: peringatan per objek tetap tampil; satu objek belum diputuskan → galat di butirnya dan tidak ada yang ditandatangani', async () => {
    const p = persetujuanDiputuskanDua()
    masukSebagai(ID_SUPERADMIN, 'contoh.superadmin', 'Contoh Superadmin')
    const awal = minta('GET', `/peran/${KASIR}`)
    const izin = [...(awal.isi as { data: { izin: string[] } }).data.izin, 'pengaturan.peran.lihat', 'pengaturan.peran.input']
    const r = minta('PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 3/2026' }, { 'if-match': awal.header!.ETag! })
    const menunggu = (r.isi as { perubahan: { menunggu_persetujuan: PersetujuanAkses[] } }).perubahan.menunggu_persetujuan[0]!
    masukSebagai(ID_SUPERADMIN_DUA, 'contoh.superadmin.dua', 'Contoh Superadmin Kedua')
    const objek = [
      { id: p.id, judul: p.nomor, ringkasan: p.ringkasan },
      { id: menunggu.id, judul: menunggu.nomor, ringkasan: menunggu.ringkasan, peringatan: 'Masih menunggu keputusan.' },
    ]
    const w = await pasang(TtdModal, { open: false, objek, pengguna: dua, tandatangani: tandatangani(objek) })
    await w.setProps({ open: true })
    await tenang()
    expect(dialog()?.textContent).toContain('2 dokumen, masing-masing ditandatangani dan tercatat terpisah')
    expect(dialog()?.textContent).toContain('Masih menunggu keputusan.')
    ;(dialog()!.querySelector('label.centang input') as HTMLInputElement).click()
    await tenang()
    await isi('PIN tanda tangan', PIN_TIRUAN)
    // Butir berperingatan wajib dikonfirmasi sendiri (PRD 5.1 butir 12): centang massal tidak menelannya.
    const sebelum = permintaan.filter((x) => x.jalur === '/ttd/tandatangani').length
    await klik(tombol('Tanda tangani 2 dokumen'))
    expect(dialog()?.textContent).toContain('Centang konfirmasi peringatan butir ini.')
    expect(permintaan.filter((x) => x.jalur === '/ttd/tandatangani').length).toBe(sebelum)
    ;(dialog()!.querySelector('label.konfirmasi input') as HTMLInputElement).click()
    await tenang()
    await isi('PIN tanda tangan', PIN_TIRUAN)
    await klik(tombol('Tanda tangani 2 dokumen'))
    expect(dialog()?.querySelectorAll('.error-objek')).toHaveLength(1)
    expect(dialog()?.textContent).toContain(`${menunggu.nomor} belum diputuskan.`)
    expect(minta('GET', `/ttd/verifikasi/persetujuan_akses/${p.id}`).isi).toEqual({ data: [] })
  })

  it('penanda tangan bukan akun yang masuk → mode minta tanda tangan, tanpa PIN dan tanpa tombol tanda tangani', async () => {
    const w = await pasang(TtdModal, { open: false, objek: [{ id: 'x', judul: 'PSA-1' }], pengguna: { id: ID_SUPERADMIN, nama: 'Contoh Superadmin' }, penanda: dua, tandatangani: async () => {} })
    await w.setProps({ open: true })
    await tenang()
    expect(dialog()?.querySelector('h2')?.textContent).toBe('Minta tanda tangan')
    expect(dialog()?.textContent).toContain('Anda masuk sebagai Contoh Superadmin, jadi Anda tidak bisa menandatangani atas namanya.')
    expect(dialog()?.querySelector('input[type="password"]')).toBeNull()
    expect(document.querySelector('button[form="form-ttd"]')).toBeNull()
  })

  it('sesi tanda tangan berlaku → tanpa PIN; 5 PIN salah → terkunci dan tombol nonaktif', async () => {
    const p = persetujuanDiputuskanDua()
    const objek = [{ id: p.id, judul: p.nomor }]
    const w = await pasang(TtdModal, { open: false, objek, pengguna: dua, sesiBerlakuSampai: new Date(Date.now() + 600_000).toISOString(), tandatangani: tandatangani(objek) })
    await w.setProps({ open: true })
    await tenang()
    expect(dialog()?.textContent).toContain('PIN tidak perlu dimasukkan lagi')
    // Server: sesi belum dimulai → pin_wajib → kolom PIN muncul.
    await centang()
    await kirimTtd()
    expect(dialog()?.textContent).toContain('Sesi tanda tangan sudah berakhir. Masukkan PIN.')
    for (let i = 0; i < 5; i++) {
      await isi('PIN tanda tangan', '000001')
      await kirimTtd()
    }
    expect(dialog()?.textContent).toContain('Tanda tangan terkunci karena PIN salah 5 kali.')
    expect(dialog()?.textContent).toMatch(/Coba lagi dalam/)
    expect(document.querySelector<HTMLButtonElement>('button[form="form-ttd"]')!.disabled).toBe(true)
  })
})

describe('Kolom PIN tidak disimpan pengelola sandi peramban (PRD 7.57.2)', () => {
  it('semua kolom PIN di halaman TTE dan TtdModal memakai one-time-code dan penanda abaikan pengelola sandi', async () => {
    const periksa = () => {
      const pin = [...document.querySelectorAll<HTMLInputElement>('input[type="password"][inputmode="numeric"]')]
      expect(pin.length).toBeGreaterThan(0)
      for (const el of pin) {
        expect(el.getAttribute('autocomplete')).toBe('one-time-code')
        expect(el.hasAttribute('data-1p-ignore')).toBe(true)
        expect(el.getAttribute('data-lpignore')).toBe('true')
      }
      return pin.length
    }
    await pasang(TandaTanganPage)
    expect(periksa()).toBe(2)
    document.body.innerHTML = ''
    server.ttd.aktifkan(ID_SUPERADMIN)
    masukSebagai(ID_SUPERADMIN, 'contoh.superadmin', 'Contoh Superadmin')
    await pasang(TandaTanganPage)
    expect(periksa()).toBe(3)
    document.body.innerHTML = ''
    const w = await pasang(TtdModal, { open: false, objek: [{ id: 'x', judul: 'PSA-1' }], pengguna: { id: ID_SUPERADMIN, nama: 'Contoh Superadmin' }, tandatangani: async () => {} })
    await w.setProps({ open: true })
    await tenang()
    expect(periksa()).toBe(1)
  })
})

describe('Halaman tanda tangan elektronik', () => {
  it('aktivasi: sandi salah dan PIN lemah ditolak dengan pesan server; berhasil → aktif; ganti PIN dengan PIN lama salah menyebut sisa', async () => {
    await pasang(TandaTanganPage)
    expect(document.body.textContent).toContain('Belum aktif')
    await isi('Sandi akun', 'salah')
    await isi('PIN baru', '135790')
    await isi('Ulangi PIN', '135790')
    await klik(tombol('Aktifkan tanda tangan'))
    expect(document.body.textContent).toContain('Sandi akun salah.')
    expect(isian('PIN baru').value).toBe('')

    await isi('Sandi akun', SANDI_TIRUAN)
    await isi('PIN baru', '123456')
    await isi('Ulangi PIN', '123456')
    await klik(tombol('Aktifkan tanda tangan'))
    expect(document.body.textContent).toContain('PIN harus 6 angka, tidak berulang atau berurutan.')

    await isi('Sandi akun', SANDI_TIRUAN)
    await isi('PIN baru', '135790')
    await isi('Ulangi PIN', '135790')
    await klik(tombol('Aktifkan tanda tangan'))
    expect(useSesiStore().pengguna?.ttd.aktif).toBe(true)
    expect(document.querySelector('h2#judul-ganti-pin')).toBeTruthy()

    await isi('PIN lama', '246813')
    await isi('PIN baru', '864201')
    await isi('Ulangi PIN baru', '864201')
    await klik(tombol('Ganti PIN'))
    expect(document.body.textContent).toContain('Sisa percobaan: 4')
  })
})

describe('Ajukan reset TTE (SDM)', () => {
  it('akun ber-TTE → 202 menunggu atasan; akun tanpa TTE → 409 tampil sebagai pesan', async () => {
    server.ttd.aktifkan(uid(3))
    const w = await pasang(DialogResetTtd, { open: false, akun: { id: uid(3), nama_pengguna: 'contoh.perawat' }, namaPegawai: 'Contoh Perawat' })
    await w.setProps({ open: true })
    await tenang()
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-reset-ttd"]'))
    expect(dialog()?.textContent).toContain('Isi alasan, minimal 5 karakter.')
    await isi('Alasan', 'Lupa PIN (sintetis)')
    await isi('Dasar (verifikasi identitas)', 'Tatap muka + kartu pegawai')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-reset-ttd"]'))
    expect(dialog()).toBeNull()
    expect(daftarToast.value.map((t) => t.pesan).some((p) => /Reset TTE PSA-\d{4}-\d+ diajukan; menunggu persetujuan Contoh Superadmin Kedua\./.test(p))).toBe(true)
    expect(server.ttd.status(uid(3)).reset_menunggu).toBe(true)

    await w.setProps({ akun: { id: uid(4), nama_pengguna: 'contoh.dokter' }, open: false })
    await w.setProps({ open: true })
    await tenang()
    await isi('Alasan', 'Lupa PIN (sintetis)')
    await isi('Dasar (verifikasi identitas)', 'Tatap muka + kartu pegawai')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-reset-ttd"]'))
    expect(dialog()?.querySelector('[role="alert"]')?.textContent).toContain('belum mengaktifkan tanda tangan elektronik')
  })

  it('atasan menyetujui reset: rincian menyebut penyetuju orang; sesudah disetujui TTE pemilik dicabut', async () => {
    server.ttd.aktifkan(uid(3))
    const r = minta('POST', `/ttd/${uid(3)}/reset`, { alasan: 'Lupa PIN (sintetis)', dasar: 'Tatap muka' })
    const p = (r.isi as { data: PersetujuanAkses }).data
    expect(p.penyetuju_pengguna?.nama).toBe('Contoh Superadmin Kedua')
    masukSebagai(ID_SUPERADMIN_DUA, 'contoh.superadmin.dua', 'Contoh Superadmin Kedua')
    await pasang(RincianPersetujuan, { id: p.id })
    expect(document.querySelector('#judul-rincian-persetujuan')?.textContent).toBe('Reset tanda tangan elektronik Contoh Perawat')
    expect(document.body.textContent).toContain('Reset tanda tangan elektronik')
    expect(document.body.textContent).toContain('(atasan langsung)')
    await klik(tombol('Setujui'))
    await klik(tombol('Setujui', dialog()!))
    expect(server.ttd.status(uid(3))).toMatchObject({ aktif: false, reset_menunggu: false })
    expect(server.ttd.status(uid(3)).dicabut_pada).toBeTruthy()
  })
})
