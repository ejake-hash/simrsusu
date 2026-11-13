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
import type { PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { ApiError, klien } from '@/shared/api/klien'
import type { PermintaanPemeriksaan, StatusPermintaanItem } from '@/shared/api/permintaanPemeriksaan'
import OrderPenunjang from '@/shared/components/OrderPenunjang.vue'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { ambilSpesimen, daftarKerjaLab, terimaSpesimen } from './api'
import { bolehAmbil, bolehTerima, identitasPasien, kurangJenisSpesimen, perluMuatUlang, pesanGalatLab, teksAsal, teksLokasi } from './lab'
import DaftarKerjaLabPage from './pages/DaftarKerjaLabPage.vue'
import { buatServerLab } from './tiruan/serverLab'
import type { BarisDaftarKerjaLab } from './tipe'

/** Tiket 2.30 FE: server tiruan kontrak v0.40.0, aturan layar, dan layar Daftar kerja lab. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let lab: ReturnType<typeof buatServerLab>
let permintaan: PermintaanTiruan[] = []
let izinServer = new Set<string>()
const IZIN = ['penunjang.laboratorium.lihat', 'penunjang.laboratorium.input', 'penunjang.laboratorium.verifikasi']
const SEKARANG = () => new Date('2026-11-02T03:00:00Z')

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: SEKARANG })
  permintaan = []
  izinServer = new Set(IZIN)
  lab = buatServerLab({ boleh: (k) => izinServer.has(k), sekarang: SEKARANG })
  pasangTiruan(klien, { tangani: (r: PermintaanTiruan) => (permintaan.push(r), lab.tangani(r) ?? gabungan.tangani(r)) })
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'analis', mfa_aktif: true })
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

const cari = (d: BarisDaftarKerjaLab[], nama: string) => d.find((b) => b.pasien.nama === nama)!

describe('lab: server tiruan kontrak v0.40.0', () => {
  it('daftar per prioritas terlama dulu, prioritas wajib, kursor', async () => {
    expect((await daftarKerjaLab('cito')).data.map((b) => b.pasien.nama)).toEqual(['Pasien Contoh Cito'])
    expect((await daftarKerjaLab('rutin')).data.map((b) => b.pasien.nama)).toEqual(['Pasien Contoh Rawat Inap', 'Pasien Contoh Tanpa Lokasi'])
    const h1 = await daftarKerjaLab('rutin', null, 1)
    expect(h1.data).toHaveLength(1)
    const h2 = await daftarKerjaLab('rutin', h1.meta.kursor_berikutnya, 1)
    expect(h2.data.map((b) => b.pasien.nama)).toEqual(['Pasien Contoh Tanpa Lokasi'])
    expect(h2.meta.kursor_berikutnya).toBeNull()
    await expect(klien.get('/lab/daftar-kerja')).rejects.toMatchObject({ status: 422 })
  })

  it('ambil → barcode LAB-YYYYMMDD-NNNNN, butir sampel_diambil; ambil kedua 409; terima → diterima_lab; order keluar sesudah semua diterima', async () => {
    const cito = cari((await daftarKerjaLab('cito')).data, 'Pasien Contoh Cito')
    const [satu, dua] = cito.item
    expect(satu!.dapat).toMatchObject({ ambil: true, terima: false })
    const sp = await ambilSpesimen(satu!.id, { jenis: 'Darah vena', lokasi_pengambilan: 'instalasi' })
    expect(sp).toMatchObject({ barcode: 'LAB-20261102-00001', item_status: 'sampel_diambil', jenis: 'Darah vena', kondisi: null })
    await expect(ambilSpesimen(satu!.id, { jenis: 'Darah vena', lokasi_pengambilan: 'instalasi' })).rejects.toMatchObject({ status: 409, kode: 'status_tidak_sesuai' })
    await expect(ambilSpesimen(dua!.id, { jenis: 'x', lokasi_pengambilan: 'lain' as never })).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'jenis' }, { kolom: 'lokasi_pengambilan' }] })
    const sesudah = cari((await daftarKerjaLab('cito')).data, 'Pasien Contoh Cito').item[0]!
    expect(sesudah).toMatchObject({ status: 'sampel_diambil', spesimen: { barcode: 'LAB-20261102-00001' }, dapat: { ambil: false, terima: true } })

    expect(await terimaSpesimen(sp.id)).toMatchObject({ item_status: 'diterima_lab', kondisi: 'baik', diterima_pada: '2026-11-02T03:00:00.000Z' })
    await expect(terimaSpesimen(sp.id)).rejects.toMatchObject({ status: 409 })
    // Butir kedua masih dipesan: order tetap tampil dengan butir diterima.
    expect(cari((await daftarKerjaLab('cito')).data, 'Pasien Contoh Cito').item.map((i) => i.status)).toEqual(['diterima_lab', 'dipesan'])
    const sp2 = await ambilSpesimen(dua!.id, { jenis: 'Urin', lokasi_pengambilan: 'samping_tt' })
    expect(sp2.barcode).toBe('LAB-20261102-00002')
    await terimaSpesimen(sp2.id)
    expect((await daftarKerjaLab('cito')).data).toEqual([])
  })

  it('izin: tanpa lihat 403; tanpa input/verifikasi dapat false dan aksi 403; order batal 409', async () => {
    izinServer = new Set(['penunjang.laboratorium.lihat'])
    const cito = (await daftarKerjaLab('cito')).data[0]!
    expect(cito.item[0]!.dapat).toEqual({ ambil: false, terima: false, input: false, validasi: false })
    await expect(ambilSpesimen(cito.item[0]!.id, { jenis: 'Darah', lokasi_pengambilan: 'instalasi' })).rejects.toMatchObject({ status: 403 })
    izinServer = new Set()
    await expect(daftarKerjaLab('cito')).rejects.toMatchObject({ status: 403 })
    izinServer = new Set(IZIN)
    lab.batalkan(cito.id)
    await expect(ambilSpesimen(cito.item[0]!.id, { jenis: 'Darah', lokasi_pengambilan: 'instalasi' })).rejects.toMatchObject({ status: 409 })
  })
})

describe('lab: aturan layar', () => {
  it('asal, lokasi (TT, IGD, poli, tidak diketahui), dan identitas dua penanda', async () => {
    const rutin = (await daftarKerjaLab('rutin')).data
    const ri = cari(rutin, 'Pasien Contoh Rawat Inap')
    expect(teksAsal(ri)).toBe('Poliklinik · Poli Penyakit Dalam (contoh)')
    expect(teksLokasi(ri)).toBe('Bangsal Melati (contoh) · TT CTHG-2-202-3')
    const tanpa = cari(rutin, 'Pasien Contoh Tanpa Lokasi')
    expect(teksLokasi(tanpa)).toBe('Tidak diketahui')
    expect(teksAsal(tanpa)).toBe('Asal tidak diketahui')
    expect(teksLokasi({ ...tanpa, lokasi_saat_ini: { jenis: 'igd', nama: 'Zona merah', tempat_tidur: null } })).toBe('IGD · Zona merah')
    expect(teksLokasi({ ...tanpa, lokasi_saat_ini: { jenis: 'igd', nama: null, tempat_tidur: null } })).toBe('IGD')
    expect(identitasPasien(tanpa)).toMatch(/^Pasien Contoh Tanpa Lokasi, No\. RM 900\.003, lahir 5 Mei 1985$/)
    expect(identitasPasien(ri)).toBe('Pasien Contoh Rawat Inap, No. RM 900.002, lahir —')
  })

  it('aksi hanya menurut dapat server; jenis spesimen 2–100; pesan 403/409', () => {
    const butir = { id: 'i', kode_item: 'K', nama: 'N', spesimen: null }
    expect(bolehAmbil({ ...butir, status: 'dipesan', dapat: { ambil: true, terima: false } })).toBe(true)
    expect(bolehAmbil({ ...butir, status: 'dipesan' })).toBe(false)
    expect(bolehTerima({ ...butir, status: 'sampel_diambil', dapat: { ambil: false, terima: true } })).toBe(false)
    expect(kurangJenisSpesimen(' a ')).toMatch(/minimal 2/)
    expect(kurangJenisSpesimen('x'.repeat(101))).toMatch(/100/)
    expect(kurangJenisSpesimen('Darah')).toBeNull()
    expect(pesanGalatLab(new ApiError(403, 'akses_ditolak', 'Tidak berhak.'))).toMatch(/^Akses ditolak: Tidak berhak\./)
    expect(pesanGalatLab(new ApiError(409, 'status_tidak_sesuai', 'Sudah diambil.'))).toBe('Sudah diambil. Daftar kerja sudah dimuat ulang.')
    expect(perluMuatUlang(new ApiError(409, 'x', 'y'))).toBe(true)
    expect(perluMuatUlang(new ApiError(422, 'x', 'y'))).toBe(false)
  })
})

async function tenang(ms = 10) {
  for (let i = 0; i < 6; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, ms))
  }
}
const plugin = () => {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  return { router, global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]] as never, directives: { can: vCan } } }
}
const tombol = (teks: string | RegExp, akar: ParentNode = document) =>
  [...akar.querySelectorAll('button')].find((b) => (typeof teks === 'string' ? b.textContent?.trim() === teks : teks.test(b.textContent ?? ''))) as HTMLButtonElement | undefined
const seksi = (judul: string) => [...document.querySelectorAll('section')].find((s) => s.querySelector('h2')?.textContent?.trim() === judul) as HTMLElement
const baris = (akar: ParentNode, nama: string) => [...akar.querySelectorAll('tbody tr')].find((tr) => tr.textContent?.includes(nama)) as HTMLElement | undefined
const dialog = () => document.querySelector<HTMLElement>('[role="dialog"]')

describe('DaftarKerjaLabPage: layar', () => {
  async function pasang() {
    const { router, global } = plugin()
    await router.push('/penunjang/laboratorium')
    const w = mount(DaftarKerjaLabPage, { attachTo: document.body, global })
    await tenang()
    return w
  }

  it('Cito di atas Rutin; baris memuat identitas dua penanda, asal, lokasi, indikasi, butir berstatus', async () => {
    await pasang()
    const judul = [...document.querySelectorAll('section h2')].map((h) => h.textContent?.trim())
    expect(judul.indexOf('Cito')).toBeLessThan(judul.indexOf('Rutin'))
    const b = baris(seksi('Cito'), 'Pasien Contoh Cito')!
    expect(b.textContent).toMatch(/No\. RM 900\.001 · lahir 1 Jan 1970/)
    expect(b.textContent).toContain('Poliklinik · Poli Penyakit Dalam (contoh)')
    expect(b.textContent).toContain('Indikasi: Demam sintetis')
    expect(b.textContent).toContain('Contoh Dokter Poli')
    expect(b.textContent).toContain('Dipesan')
    expect(baris(seksi('Rutin'), 'Pasien Contoh Tanpa Lokasi')!.textContent).toContain('Tidak diketahui')
    expect(baris(seksi('Rutin'), 'Pasien Contoh Rawat Inap')!.textContent).toContain('Pemesan tidak tercatat')
    // Dua kueri terpisah, satu per prioritas.
    expect(permintaan.filter((p) => p.jalur === '/lab/daftar-kerja').map((p) => p.kueri.get('prioritas')).sort()).toEqual(['cito', 'rutin'])
  })

  it('ambil sampel: dialog menampilkan identitas dua penanda sebelum konfirmasi, isian wajib, barcode tampil, daftar dimuat ulang', async () => {
    await pasang()
    const b = baris(seksi('Cito'), 'Pasien Contoh Cito')!
    const t = [...b.querySelectorAll('button')].find((x) => x.getAttribute('aria-label') === 'Ambil sampel Pemeriksaan lab contoh satu Pasien Contoh Cito')!
    t.click()
    await tenang()
    const d = dialog()!
    expect(d.textContent).toContain('Pasien Contoh Cito, No. RM 900.001, lahir 1 Jan 1970')
    expect(d.textContent).toContain('Poli Penyakit Dalam (contoh)')
    tombol('Catat sampel dan buat barcode', document)!.click()
    await tenang()
    expect(permintaan.some((p) => p.metode === 'post')).toBe(false)
    expect(d.textContent).toContain('Isi jenis spesimen, minimal 2 karakter.')
    expect(d.textContent).toContain('Pilih lokasi pengambilan.')
    const isian = d.querySelector<HTMLInputElement>('input[type="text"]')!
    isian.value = 'Darah vena'
    isian.dispatchEvent(new Event('input'))
    const radio = [...d.querySelectorAll<HTMLInputElement>('input[type="radio"]')].find((r) => r.value === 'samping_tt')!
    radio.click()
    await tenang()
    const sebelum = permintaan.length
    tombol('Catat sampel dan buat barcode', document)!.click()
    await tenang()
    expect(permintaan.slice(sebelum).find((p) => p.metode === 'post')?.isi).toEqual({ jenis: 'Darah vena', lokasi_pengambilan: 'samping_tt' })
    expect(dialog()).toBeNull()
    expect(document.body.textContent).toContain('Sampel Pemeriksaan lab contoh satu untuk Pasien Contoh Cito tercatat. Barcode LAB-20261102-00001.')
    expect(permintaan.slice(sebelum).filter((p) => p.jalur === '/lab/daftar-kerja').length).toBeGreaterThanOrEqual(2)
    const sesudah = baris(seksi('Cito'), 'Pasien Contoh Cito')!
    expect(sesudah.textContent).toContain('LAB-20261102-00001')
    expect(sesudah.textContent).toContain('Sampel diambil')
    expect(tombol('Terima di lab', sesudah)).toBeDefined()
  })

  it('terima di lab: konfirmasi beridentitas, sesudah semua butir diterima order keluar dari seksi', async () => {
    const r = lab.order.find((o) => o.pasien.nama === 'Pasien Contoh Rawat Inap')!
    await ambilSpesimen(r.item[0]!.id, { jenis: 'Darah', lokasi_pengambilan: 'samping_tt' })
    await pasang()
    tombol('Terima di lab', baris(seksi('Rutin'), 'Pasien Contoh Rawat Inap')!)!.click()
    await tenang()
    const d = dialog()!
    expect(d.textContent).toContain('Pasien Contoh Rawat Inap, No. RM 900.002, lahir —')
    expect(d.textContent).toContain('LAB-20261102-00001')
    tombol('Terima di lab', d)!.click()
    await tenang()
    expect(document.body.textContent).toContain('Spesimen LAB-20261102-00001 (Pemeriksaan lab contoh satu, Pasien Contoh Rawat Inap) diterima di lab.')
    expect(baris(seksi('Rutin'), 'Pasien Contoh Rawat Inap')).toBeUndefined()
  })

  it('409: pesan server tampil di dialog dan daftar dimuat ulang', async () => {
    await pasang()
    tombol(/Ambil sampel/, baris(seksi('Rutin'), 'Pasien Contoh Tanpa Lokasi')!)!.click()
    await tenang()
    lab.batalkan(lab.order.find((o) => o.pasien.nama === 'Pasien Contoh Tanpa Lokasi')!.id)
    const d = dialog()!
    const isian = d.querySelector<HTMLInputElement>('input[type="text"]')!
    isian.value = 'Darah'
    isian.dispatchEvent(new Event('input'))
    d.querySelector<HTMLInputElement>('input[value="instalasi"]')!.click()
    await tenang()
    const sebelum = permintaan.length
    tombol('Catat sampel dan buat barcode', document)!.click()
    await tenang()
    expect(dialog()!.textContent).toContain('Sampel butir ini sudah diambil atau order sudah dibatalkan. Daftar kerja sudah dimuat ulang.')
    expect(permintaan.slice(sebelum).some((p) => p.jalur === '/lab/daftar-kerja')).toBe(true)
  })

  it('tanpa izin input/verifikasi: tidak ada tombol aksi; 403 daftar → akses ditolak', async () => {
    izinServer = new Set(['penunjang.laboratorium.lihat'])
    await pasang()
    expect(tombol(/Ambil sampel|Terima di lab/)).toBeUndefined()
    document.body.innerHTML = ''
    izinServer = new Set()
    await pasang()
    expect(seksi('Cito').textContent).toContain('Akses ditolak')
  })

  it('Muat lagi: halaman berikut seksi Rutin dimuat dengan kursor', async () => {
    // Batas 1 per halaman agar dua order rutin sintetis terbagi dua halaman.
    pasangTiruan(klien, {
      tangani: (r: PermintaanTiruan) => {
        if (r.jalur === '/lab/daftar-kerja') r.kueri.set('batas', '1')
        permintaan.push(r)
        return lab.tangani(r) ?? gabungan.tangani(r)
      },
    })
    await pasang()
    const rutin = seksi('Rutin')
    expect(baris(rutin, 'Pasien Contoh Rawat Inap')).toBeDefined()
    expect(baris(rutin, 'Pasien Contoh Tanpa Lokasi')).toBeUndefined()
    tombol('Muat lagi', rutin)!.click()
    await tenang()
    expect(baris(rutin, 'Pasien Contoh Tanpa Lokasi')).toBeDefined()
    expect(permintaan.some((p) => p.jalur === '/lab/daftar-kerja' && p.kueri.get('prioritas') === 'rutin' && p.kueri.get('kursor') === '1')).toBe(true)
    expect(tombol('Muat lagi', rutin)).toBeUndefined()
  })
})

describe('OrderPenunjang: status butir lab (v0.40.0)', () => {
  const ORDER = (status: StatusPermintaanItem[], batal: boolean): PermintaanPemeriksaan => ({
    id: 'order-1', kunjungan_id: 'k', episode_id: 'e', jenis: 'lab', prioritas: 'cito', diagnosis_kerja: 'Indikasi sintetis', catatan: null,
    alasan_ganda: null, status: 'dipesan', pemesan: { id: 'p', nama: 'Contoh Dokter Poli' }, dipesan_pada: '2026-11-02T01:00:00.000Z',
    item: status.map((s, n) => ({ id: `i${n}`, tarif_id: `t${n}`, kode_item: `CONTOH-LAB-0${n + 1}`, nama: `Pemeriksaan lab contoh ${n + 1}`, status: s })),
    alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, dapat: { batal },
  })
  async function pasangOrder(order: PermintaanPemeriksaan) {
    pasangTiruan(klien, {
      tangani: (r: PermintaanTiruan) =>
        r.jalur === '/episode/e/permintaan-pemeriksaan' ? { status: 200, isi: { data: [order], meta: { kursor_berikutnya: null } } } : gabungan.tangani(r),
    })
    const { global } = plugin()
    mount(OrderPenunjang, { attachTo: document.body, global, props: { kunjunganId: 'k', episodeId: 'e', bisaPesan: false } })
    await tenang()
    return document.querySelector('li')!
  }

  it('butir berstatus berteks; sudah diterima lab → tanpa tombol Batalkan dan keterangan tampil', async () => {
    const li = await pasangOrder(ORDER(['diterima_lab', 'sampel_diambil'], false))
    expect(li.textContent).toContain('Diterima lab')
    expect(li.textContent).toContain('Sampel diambil')
    expect(li.textContent).toContain('Sudah diterima lab, tidak bisa dibatalkan.')
    expect(tombol('Batalkan', li)).toBeUndefined()
  })

  it('sampel diambil tapi belum diterima: Batalkan mengikuti dapat.batal, tanpa keterangan diterima', async () => {
    const li = await pasangOrder(ORDER(['sampel_diambil', 'dipesan'], true))
    expect(li.textContent).toContain('Sampel diambil')
    expect(li.textContent).not.toContain('Dipesan')
    expect(li.textContent).not.toContain('Sudah diterima lab')
    expect(tombol('Batalkan', li)).toBeDefined()
  })
})
