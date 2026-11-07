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
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { daftarBarang, daftarFormularium, imporBarang } from './api'
import { KOLOM_CSV_BARANG, templatCsv } from './formularium'
import FormulariumPage from './pages/FormulariumPage.vue'

const adapterAsli = klien.defaults.adapter
let permintaan: PermintaanTiruan[]
let gabungan: ReturnType<typeof buatServerGabungan>
const IZIN_PENUH = ['farmasi.formularium.lihat', 'farmasi.formularium.input', 'farmasi.formularium.perbaiki']

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan()
  const server = pasangTiruan(klien, gabungan)
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  aturIzin(IZIN_PENUH)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

async function pasang() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/farmasi/formularium')
  const w = mount(FormulariumPage, {
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
const tombol = (teks: string) => [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
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
const kirimForm = (id: string) => klik(document.querySelector<HTMLButtonElement>(`button[form="${id}"]`))
const kodeDiTabel = () => [...document.querySelectorAll('tbody td code')].map((c) => c.textContent)
const dialog = () => document.querySelector('[role="dialog"], [role="alertdialog"]')
async function bukaTabFormularium(w: Awaited<ReturnType<typeof pasang>>) {
  await w.findAll('[role="tab"]')[1]!.trigger('mousedown', { button: 0 })
  await tenang()
}
async function pilihFormularium(kode: string) {
  const baris = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent?.includes(kode)) as HTMLElement
  await klik(baris)
}
function pilihBerkas(teks: string) {
  const el = document.querySelector<HTMLInputElement>('[role="dialog"] input[type="file"]')!
  Object.defineProperty(el, 'files', { value: [new File([teks], 'impor.csv', { type: 'text/csv' })], configurable: true })
  el.dispatchEvent(new Event('change'))
}

describe('klien katalog barang', () => {
  it('keyset dan filter tanpa KFA hanya barang aktif yang kode KFA-nya kosong', async () => {
    const h1 = await daftarBarang({}, null, 2)
    expect(h1.data).toHaveLength(2)
    expect(h1.meta.kursor_berikutnya).toBeTruthy()
    const tanpa = await daftarBarang({ tanpa_kfa: true })
    expect(tanpa.data.length).toBeGreaterThan(0)
    expect(tanpa.data.every((b) => b.kode_kfa === null && b.status === 'aktif')).toBe(true)
    expect((await daftarFormularium()).data.map((f) => f.jenis)).toContain('fornas')
  })

  it('impor pratinjau melaporkan nomor baris; terapkan dengan baris salah = 422 impor_tidak_valid dan tidak ada yang tersimpan', async () => {
    const csv = `${KOLOM_CSV_BARANG.join(';')}\nBARU1;Barang Baru;bmhp;;;;;lembar;;;;\nBARU2;Barang Tanpa Satuan;bmhp;;;;;;;;;\nPCT500;Kode Terpakai;bmhp;;;;;pcs;;;;\nBARU3;Jenis Salah;sihir;;;;;pcs;;;;`
    const hasil = await imporBarang(new File([csv], 'b.csv'), 'pratinjau', 'Uji impor')
    expect(hasil).toMatchObject({ diterapkan: false, jumlah_baris: 4, jumlah_salah: 3 })
    expect(hasil.kesalahan.map((k) => [k.baris, k.kode])).toEqual([[3, 'kolom_wajib'], [4, 'kode_sudah_dipakai'], [5, 'jenis_tidak_dikenal']])
    await expect(imporBarang(new File([csv], 'b.csv'), 'terapkan', 'Uji impor')).rejects.toMatchObject({ status: 422, kode: 'impor_tidak_valid' })
    expect((await daftarBarang({ cari: 'BARU1' })).data).toHaveLength(0)
  })

  it('templat CSV memuat judul kolom persis kontrak', () => {
    expect(templatCsv(KOLOM_CSV_BARANG).replace('﻿', '')).toBe('kode;nama;jenis;generik_paten;zat_aktif;kekuatan;sediaan;satuan_terkecil;kode_kfa;kewaspadaan_tinggi;narkotika;lasa\n')
  })
})

describe('Layar katalog barang', () => {
  it('bawaan menampilkan barang aktif; nonaktif lewat filter status; filter tanpa KFA terkirim', async () => {
    await pasang()
    expect(kodeDiTabel()).toContain('PCT500')
    expect(kodeDiTabel()).not.toContain('OBT-LAMA')
    await pilih('status-barang', 'nonaktif')
    expect(kodeDiTabel()).toEqual(['OBT-LAMA'])
    await pilih('status-barang', 'aktif')
    await klik(document.querySelector<HTMLInputElement>('.centang input'))
    expect(permintaan.some((p) => p.kueri.get('tanpa_kfa') === 'true')).toBe(true)
    expect(kodeDiTabel()).not.toContain('PCT500')
  })

  it('obat wajib generik atau paten sebelum dikirim; generik dan paten zat sama = dua kode; kode ganda 422 di kolom Kode', async () => {
    await pasang()
    await klik(tombol('Tambah barang'))
    await isi('Kode barang', 'amlo5')
    await isi('Nama barang', 'Amlodipin 5 mg')
    await isi('Satuan terkecil', 'tablet')
    await isi('Dasar perubahan', 'Usul KFRS 1/2026')
    await kirimForm('form-barang')
    expect(permintaan.some((p) => p.metode === 'post')).toBe(false)
    expect(dialog()?.textContent).toContain('generik atau paten')
    await isi('Generik atau paten', 'generik')
    await kirimForm('form-barang')
    expect(permintaan.find((p) => p.metode === 'post')?.isi).toMatchObject({ kode: 'AMLO5', jenis: 'obat', generik_paten: 'generik', zat_aktif: null, kode_kfa: null, kewaspadaan_tinggi: false })
    expect(dialog()).toBeNull()
    await klik(tombol('Tambah barang'))
    await isi('Kode barang', 'PCT500')
    await isi('Nama barang', 'Ganda')
    await isi('Generik atau paten', 'paten')
    await isi('Satuan terkecil', 'tablet')
    await isi('Dasar perubahan', 'Uji kode ganda')
    await kirimForm('form-barang')
    expect(dialog()?.textContent).toContain('sudah dipakai barang lain')
  })

  it('ubah mengirim hanya kolom berubah dengan If-Match dari rincian; nonaktifkan lewat dialog', async () => {
    await pasang()
    await klik(tombolLabel('Ubah Kasa steril 10 x 10 cm'))
    await isi('Kode KFA', 'KFA-CONTOH-9')
    await isi('Dasar perubahan', 'Pengisian KFA manual')
    await kirimForm('form-barang')
    const patch = permintaan.find((p) => p.metode === 'patch')
    expect(patch?.isi).toEqual({ kode_kfa: 'KFA-CONTOH-9', dasar_perubahan: 'Pengisian KFA manual' })
    expect(patch?.header['if-match']).toBe('"1"')
    await klik(tombolLabel('Nonaktifkan Kasa steril 10 x 10 cm'))
    await isi('Dasar perubahan', 'Tidak dipakai lagi')
    await kirimForm('form-status-formularium')
    expect(kodeDiTabel()).not.toContain('KASA10')
  })

  it('tanpa izin input dan perbaiki: tombol tulis dan kolom Aksi tidak tampil', async () => {
    aturIzin(['farmasi.formularium.lihat'])
    await pasang()
    expect(tombol('Tambah barang')?.hidden ?? true).toBe(true)
    expect(tombol('Impor CSV')?.hidden ?? true).toBe(true)
    expect([...document.querySelectorAll('th')].map((t) => t.textContent?.trim())).not.toContain('Aksi')
  })

  it('impor CSV: pratinjau menampilkan nomor baris salah dan tombol Terapkan tidak muncul', async () => {
    await pasang()
    await klik(tombol('Impor CSV'))
    pilihBerkas(`${KOLOM_CSV_BARANG.join(';')}\nBARU2;Tanpa Satuan;bmhp;;;;;;;;;\n`)
    await isi('Dasar perubahan', 'Uji impor')
    await kirimForm('form-impor-csv')
    expect(dialog()?.textContent).toContain('1 dari 1 baris salah')
    expect([...document.querySelectorAll('.tabel-galat tbody td')].map((t) => t.textContent?.trim())).toContain('2')
    expect(tombol('Terapkan 1 baris')).toBeUndefined()
  })

  it('impor CSV yang lolos: Terapkan menyimpan dan barang muncul di daftar', async () => {
    await pasang()
    await klik(tombol('Impor CSV'))
    pilihBerkas(`${KOLOM_CSV_BARANG.join(';')}\nBARU9;Selang Contoh;bmhp;;;;;buah;;;;\n`)
    await isi('Dasar perubahan', 'Uji impor')
    await kirimForm('form-impor-csv')
    await klik(tombol('Terapkan 1 baris'))
    expect(dialog()?.textContent).toContain('1 baris disimpan')
    await klik(tombol('Tutup'))
    expect(kodeDiTabel()).toContain('BARU9')
  })
})

describe('Layar formularium', () => {
  it('pilih formularium menampilkan itemnya; tambah item lewat pemilih barang aktif', async () => {
    const w = await pasang()
    await bukaTabFormularium(w)
    expect(document.body.textContent).toContain('Formularium RS Contoh 2026')
    await pilihFormularium('FRM-RS-2026')
    expect(kodeDiTabel()).toEqual(expect.arrayContaining(['PCT500', 'AMX500']))
    await klik(tombol('Tambah barang'))
    await isi('Cari barang aktif', 'kasa')
    await new Promise((r) => setTimeout(r, 400))
    await tenang()
    await klik([...document.querySelectorAll<HTMLButtonElement>('.hasil-baris')].find((b) => b.textContent?.includes('KASA10')))
    await isi('Maksimum per resep', '5')
    await isi('Dasar perubahan', 'Usul KFRS 2/2026')
    await kirimForm('form-item-formularium')
    expect(permintaan.find((p) => p.metode === 'post' && p.jalur.endsWith('/item'))?.isi).toMatchObject({ maks_per_resep: 5, kronis: false, restriksi: null })
    expect(dialog()).toBeNull()
    expect(kodeDiTabel()).toContain('KASA10')
  })

  it('barang yang sudah ada ditolak 422 di kolom pemilih; barang nonaktif tidak muncul di pemilih', async () => {
    const w = await pasang()
    await bukaTabFormularium(w)
    await pilihFormularium('FRM-RS-2026')
    await klik(tombol('Tambah barang'))
    await isi('Cari barang aktif', 'obat contoh')
    await new Promise((r) => setTimeout(r, 400))
    await tenang()
    expect(document.querySelectorAll('.hasil-baris')).toHaveLength(0)
    await isi('Cari barang aktif', 'parasetamol 500')
    await new Promise((r) => setTimeout(r, 400))
    await tenang()
    await klik(document.querySelector<HTMLButtonElement>('.hasil-baris'))
    await isi('Dasar perubahan', 'Uji ganda')
    await kirimForm('form-item-formularium')
    expect(dialog()?.textContent).toContain('sudah ada di formularium')
  })

  it('ubah dan nonaktifkan item: patch dengan If-Match dari baris daftar', async () => {
    const w = await pasang()
    await bukaTabFormularium(w)
    await pilihFormularium('FRM-RS-2026')
    await klik(tombolLabel('Ubah item Amoksisilin 500 mg'))
    await isi('Restriksi penggunaan', 'Dengan resep dokter')
    await isi('Dasar perubahan', 'Fornas 2026')
    await kirimForm('form-item-formularium')
    const patch = permintaan.find((p) => p.metode === 'patch')
    expect(patch?.isi).toEqual({ restriksi: 'Dengan resep dokter', dasar_perubahan: 'Fornas 2026' })
    expect(patch?.header['if-match']).toBe('"1"')
    await klik(tombolLabel('Nonaktifkan item Amoksisilin 500 mg'))
    await isi('Dasar perubahan', 'Keluar formularium')
    await kirimForm('form-status-formularium')
    expect(kodeDiTabel()).not.toContain('AMX500')
  })

  it('tambah formularium jenis penjamin wajib memilih penjamin', async () => {
    const w = await pasang()
    await bukaTabFormularium(w)
    await klik(tombol('Tambah formularium'))
    await isi('Kode formularium', 'frm-asu')
    await isi('Jenis formularium', 'penjamin')
    await isi('Nama formularium', 'Formularium Asuransi Contoh')
    await isi('Berlaku dari', '2026-12-01')
    await isi('Dasar perubahan', 'PKS 2026')
    await kirimForm('form-formularium')
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur.endsWith('/formularium'))).toBe(false)
    expect(dialog()?.textContent).toContain('Pilih penjamin')
    await isi('Penjamin', [...(isian('Penjamin') as unknown as HTMLSelectElement).options].find((o) => o.value)!.value)
    await kirimForm('form-formularium')
    expect(permintaan.find((p) => p.metode === 'post' && p.jalur.endsWith('/formularium'))?.isi).toMatchObject({ kode: 'FRM-ASU', jenis: 'penjamin', berlaku_sampai: null })
    expect(dialog()).toBeNull()
  })

  it('rentang berlaku yang tumpang tindih ditolak 422 di kolom Berlaku dari', async () => {
    const w = await pasang()
    await bukaTabFormularium(w)
    await klik(tombol('Tambah formularium'))
    await isi('Kode formularium', 'frm-rs-dua')
    await isi('Nama formularium', 'Formularium RS ganda')
    await isi('Berlaku dari', '2026-06-01')
    await isi('Dasar perubahan', 'Uji tumpang tindih')
    await kirimForm('form-formularium')
    expect(dialog()?.textContent).toContain('tumpang tindih')
  })

  it('sesudah siap melayani: 409 harus_lewat_usulan memindahkan dialog ke mode usulan dan mengajukan perubahan yang sama', async () => {
    gabungan.formularium.aturSiapMelayani(true)
    aturIzin([...IZIN_PENUH, 'master_data.usulan.lihat', 'master_data.usulan.input'])
    const w = await pasang()
    await bukaTabFormularium(w)
    await pilihFormularium('FRM-RS-2026')
    await klik(tombolLabel('Ubah item Amoksisilin 500 mg'))
    await isi('Maksimum per resep', '7')
    await isi('Dasar perubahan', 'Rapat KFT 3/2026')
    await kirimForm('form-item-formularium')
    expect(dialog()?.textContent).toContain('diajukan sebagai usulan')
    expect(dialog()?.querySelector('[role="alert"]')).toBeNull()
    // Tanggal dan alasan wajib di mode usulan.
    await kirimForm('form-item-formularium')
    expect(dialog()?.textContent).toContain('Pilih tanggal berlaku.')
    await isi('Berlaku mulai', '2030-01-01')
    await isi('Alasan usulan', 'Batas resep disesuaikan KFT')
    await kirimForm('form-item-formularium')
    expect(dialog()).toBeNull()
    const kirim = permintaan.find((r) => r.metode === 'post' && r.jalur === '/usulan-data-induk')
    expect(kirim?.isi).toMatchObject({ jenis_data: 'formularium', berlaku_dari: '2030-01-01', alasan: 'Batas resep disesuaikan KFT', dasar: 'Rapat KFT 3/2026' })
    expect((kirim?.isi as { perubahan: unknown[] }).perubahan).toEqual([expect.objectContaining({ objek_jenis: 'formularium_item', aksi: 'ubah', nilai_baru: { maks_per_resep: 7 } })])
  })

  it('barang tetap bisa ditulis langsung sesudah siap melayani', async () => {
    gabungan.formularium.aturSiapMelayani(true)
    await pasang()
    await klik(tombolLabel('Ubah Kasa steril 10 x 10 cm'))
    await isi('Kode KFA', 'KFA-CONTOH-8')
    await isi('Dasar perubahan', 'Isi KFA')
    await kirimForm('form-barang')
    expect(dialog()).toBeNull()
  })

  it('409 dari server tiruan memakai ApiError berstatus 409', async () => {
    gabungan.formularium.aturSiapMelayani(true)
    const { buatFormularium } = await import('./api')
    await expect(buatFormularium({ kode: 'X', nama: 'Xx', jenis: 'rs', berlaku_dari: '2030-01-01', berlaku_sampai: null, dasar_perubahan: 'uji' })).rejects.toSatisfy((e) => e instanceof ApiError && e.status === 409 && e.kode === 'harus_lewat_usulan')
  })
})
