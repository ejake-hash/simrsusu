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
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { adendumHasilLab, ambilSpesimen, daftarKerjaLab, inputHasilLab, linimasaHasilLab, terimaSpesimen, validasiHasilLab } from './api'
import LinimasaHasilLab from './components/LinimasaHasilLab.vue'
import TampilanHasilLab from './components/TampilanHasilLab.vue'
import { bacaIsianHasil, bolehAdendum, bolehInput, bolehValidasi, kurangAlasanAdendum, LABEL_PENANDA, perluMuatUlang, pesanGalatLab, tahapTersedia, teksBatasKritis, teksNilai, teksRujukan } from './lab'
import DaftarKerjaLabPage from './pages/DaftarKerjaLabPage.vue'
import { buatServerLab } from './tiruan/serverLab'
import type { HasilLab } from './tipe'

/** Tiket 2.31 FE: hasil, penanda berteks, validasi/otorisasi beridentitas, adendum, tahap, linimasa. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let lab: ReturnType<typeof buatServerLab>
let permintaan: PermintaanTiruan[] = []
let izinServer = new Set<string>()
let pelakuSaatIni = { id: '00000000-0000-7000-b230-000000000900', nama: 'Contoh Analis Lab' }
const ANALIS = ['penunjang.laboratorium.lihat', 'penunjang.laboratorium.input', 'penunjang.laboratorium.verifikasi', 'penunjang.laboratorium.sahkan']
const SPPK = ['penunjang.laboratorium.lihat', 'penunjang.laboratorium.setujui']
const SEKARANG = () => new Date('2026-11-02T03:00:00Z')

function jadi(izin: string[], pelaku = pelakuSaatIni) {
  izinServer = new Set(izin)
  pelakuSaatIni = pelaku
  aturIzin(izin)
}

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: SEKARANG })
  permintaan = []
  pelakuSaatIni = { id: '00000000-0000-7000-b230-000000000900', nama: 'Contoh Analis Lab' }
  lab = buatServerLab({ boleh: (k) => izinServer.has(k), sekarang: SEKARANG, pelaku: () => pelakuSaatIni })
  pasangTiruan(klien, { tangani: (r: PermintaanTiruan) => (permintaan.push(r), lab.tangani(r) ?? gabungan.tangani(r)) })
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'analis', mfa_aktif: true })
  jadi(ANALIS)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

/** Butir order cito (CONTOH-LAB-01 dan -02) sampai diterima di lab. */
async function siapkanCito() {
  const cito = (await daftarKerjaLab('cito')).data[0]!
  for (const i of cito.item) {
    const sp = await ambilSpesimen(i.id, { jenis: 'Darah vena', lokasi_pengambilan: 'instalasi' })
    await terimaSpesimen(sp.id)
  }
  return cito
}
/** Butir rutin CONTOH-LAB-03 (butuh Sp.PK) sampai diterima di lab. */
async function siapkanSpk() {
  const o = (await daftarKerjaLab('rutin')).data.find((b) => b.item[0]!.kode_item === 'CONTOH-LAB-03')!
  const sp = await ambilSpesimen(o.item[0]!.id, { jenis: 'Darah vena', lokasi_pengambilan: 'instalasi' })
  await terimaSpesimen(sp.id)
  return o
}

const DASAR: HasilLab = {
  id: 'h', permintaan_item_id: 'i', permintaan_pemeriksaan_id: 'p', episode_id: 'e', kode_item: 'CONTOH-LAB-01', nama: 'Pemeriksaan lab contoh satu',
  status: 'divalidasi', jenis_hasil: 'numerik', nilai: 5, nilai_teks: null, satuan: '{contoh}', kode_loinc: null, rujukan_min: 10, rujukan_maks: 20,
  kritis_min: 5, kritis_maks: 30, penanda: 'l', kritis: false, peringatan: null, catatan: null, butuh_otorisasi_spk: false,
  pasien: { id: 'ps', no_rm: '900001', nama: 'Pasien Contoh Cito', tanggal_lahir: '1970-01-01' }, diinput_oleh: null, diinput_pada: '2026-11-02T03:00:00Z',
  divalidasi_oleh: { id: 'a', nama: 'Contoh Analis Lab' }, divalidasi_pada: '2026-11-02T03:00:00Z', diotorisasi_oleh: null, diotorisasi_pada: null,
  dikeluarkan_pada: '2026-11-02T03:00:00Z', adendum: [], dapat: { input: false, validasi: false, adendum: true },
}

describe('hasil lab: server tiruan kontrak v0.41.0', () => {
  it('tahap: order masuk Hasil sesudah diterima, Otorisasi sesudah validasi analis butir Sp.PK; butir divalidasi tidak masuk tahap mana pun', async () => {
    await siapkanCito()
    expect((await daftarKerjaLab('cito')).data).toEqual([])
    const hasil = (await daftarKerjaLab('cito', null, 50, 'hasil')).data[0]!
    expect(hasil.item.map((i) => [i.status, i.dapat?.input, i.hasil])).toEqual([['diterima_lab', true, null], ['diterima_lab', true, null]])
    expect(permintaan[permintaan.length - 1]!.kueri.get('tahap')).toBe('hasil')

    const spk = await siapkanSpk()
    const h = await inputHasilLab(spk.item[0]!.id, { nilai: 5 })
    expect(h).toMatchObject({ status: 'menunggu_validasi', penanda: 'h', butuh_otorisasi_spk: true, dapat: { input: true, validasi: true } })
    expect(await validasiHasilLab(h.id)).toMatchObject({ status: 'menunggu_otorisasi', dikeluarkan_pada: null })
    expect((await daftarKerjaLab('rutin', null, 50, 'otorisasi')).data.map((b) => b.id)).toEqual([spk.id])
    // Validator sendiri tidak boleh mengotorisasi (empat mata).
    jadi([...ANALIS, 'penunjang.laboratorium.setujui'])
    await expect(validasiHasilLab(h.id)).rejects.toMatchObject({ status: 409, kode: 'sudah_memvalidasi' })
    jadi(SPPK, { id: '00000000-0000-7000-b230-000000000901', nama: 'Contoh Spesialis Patologi Klinik' })
    expect(await validasiHasilLab(h.id)).toMatchObject({ status: 'divalidasi', diotorisasi_oleh: { nama: 'Contoh Spesialis Patologi Klinik' } })
    expect((await daftarKerjaLab('rutin', null, 50, 'otorisasi')).data).toEqual([])
  })

  it('input: jenis salah 422 per kolom, tanpa rujukan → peringatan, input ulang 200, divalidasi 409, adendum menyimpan hasil asli', async () => {
    const cito = await siapkanCito()
    const [satu, dua] = cito.item
    await expect(inputHasilLab(dua!.id, { nilai: 5 })).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'nilai_teks' }] })
    expect(await inputHasilLab(dua!.id, { nilai_teks: 'contoh KRITIS' })).toMatchObject({ penanda: 'kritis', kritis: true })
    const h = await inputHasilLab(satu!.id, { nilai: 50 })
    expect(h).toMatchObject({ penanda: 'hh', kritis: true })
    expect(await inputHasilLab(satu!.id, { nilai: 5 })).toMatchObject({ id: h.id, penanda: 'l', kritis: false })
    await validasiHasilLab(h.id)
    await expect(inputHasilLab(satu!.id, { nilai: 6 })).rejects.toMatchObject({ status: 409, kode: 'hasil_sudah_divalidasi' })
    await expect(adendumHasilLab(h.id, { nilai: 10, alasan: 'x' })).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'alasan' }] })
    const a = await adendumHasilLab(h.id, { nilai: 10, alasan: 'Salah ketik sintetis' })
    expect(a).toMatchObject({ nilai: 5, penanda: 'l', adendum: [{ nilai: 10, penanda: null, alasan: 'Salah ketik sintetis' }] })
    jadi([...ANALIS, 'rawat_jalan.pemeriksaan.lihat'])
    const linimasa = await linimasaHasilLab(h.episode_id)
    expect(linimasa.data.map((x) => x.id)).toEqual([h.id])
    jadi(['penunjang.laboratorium.lihat'])
    await expect(linimasaHasilLab(h.episode_id)).rejects.toMatchObject({ status: 403 })
  })
})

describe('hasil lab: aturan layar', () => {
  it('penanda selalu berteks; rentang rujukan, batas kritis, peringatan tanpa rujukan', () => {
    expect(LABEL_PENANDA).toEqual({ l: 'Rendah', h: 'Tinggi', ll: 'Sangat rendah', hh: 'Sangat tinggi', kritis: 'Kritis' })
    expect(teksNilai(DASAR)).toBe('5 {contoh}')
    expect(teksNilai({ ...DASAR, nilai: 4.5 })).toBe('4,5 {contoh}')
    expect(teksNilai({ ...DASAR, nilai: null, nilai_teks: 'Contoh normal' })).toBe('Contoh normal')
    expect(teksRujukan(DASAR)).toBe('Rujukan 10–20 {contoh}')
    expect(teksRujukan({ ...DASAR, rujukan_min: null })).toBe('Rujukan paling tinggi 20 {contoh}')
    expect(teksRujukan({ ...DASAR, rujukan_min: null, rujukan_maks: null })).toBeNull()
    expect(teksBatasKritis(DASAR)).toBe('Kritis bila di bawah 5 atau di atas 30')
    expect(teksBatasKritis({ ...DASAR, kritis_min: null })).toBe('Kritis bila di atas 30')
  })

  it('isian hasil: angka (koma/titik) → nilai, teks → nilai_teks, jenis diketahui dipaksa', () => {
    expect(bacaIsianHasil(' 4,5 ', null)).toEqual({ isian: { nilai: 4.5 } })
    expect(bacaIsianHasil('12.25', null)).toEqual({ isian: { nilai: 12.25 } })
    expect(bacaIsianHasil('Positif', null)).toEqual({ isian: { nilai_teks: 'Positif' } })
    expect(bacaIsianHasil('5', 'teks')).toEqual({ isian: { nilai_teks: '5' } })
    expect(bacaIsianHasil('Positif', 'numerik')).toEqual({ galat: 'Hasil pemeriksaan ini berupa angka, mis. 4,5.' })
    expect(bacaIsianHasil('  ', null)).toEqual({ galat: 'Isi hasil pemeriksaan.' })
    expect(kurangAlasanAdendum('abc')).toMatch(/minimal 5/)
    expect(kurangAlasanAdendum('Salah ketik')).toBeNull()
  })

  it('tahap menurut izin sesi; tombol hanya menurut dapat server', () => {
    const izin = (daftar: string[]) => (k: string) => daftar.includes(k)
    expect(tahapTersedia(izin(ANALIS))).toEqual(['spesimen', 'hasil'])
    expect(tahapTersedia(izin(SPPK))).toEqual(['otorisasi'])
    expect(tahapTersedia(izin(['penunjang.laboratorium.lihat']))).toEqual(['spesimen'])
    const butir = { id: 'i', kode_item: 'K', nama: 'N', spesimen: null }
    expect(bolehInput({ ...butir, status: 'diterima_lab', dapat: { ambil: false, terima: false, input: true } })).toBe(true)
    expect(bolehInput({ ...butir, status: 'diterima_lab', dapat: { ambil: false, terima: false } })).toBe(false)
    expect(bolehValidasi({ ...butir, status: 'menunggu_validasi', hasil: DASAR, dapat: { ambil: false, terima: false, validasi: true } })).toBe(true)
    expect(bolehValidasi({ ...butir, status: 'menunggu_validasi', hasil: null, dapat: { ambil: false, terima: false, validasi: true } })).toBe(false)
    expect(bolehAdendum(DASAR)).toBe(true)
    expect(bolehAdendum({ ...DASAR, dapat: { ...DASAR.dapat, adendum: false } })).toBe(false)
  })

  it('pesan 409/422 bisa dipahami dan daftar dimuat ulang', () => {
    expect(pesanGalatLab(new ApiError(409, 'hasil_sudah_divalidasi', 'x'))).toBe('Hasil ini sudah divalidasi, jadi tidak bisa diubah lagi. Koreksi lewat adendum. Daftar kerja sudah dimuat ulang.')
    expect(pesanGalatLab(new ApiError(409, 'sudah_memvalidasi', 'x'))).toMatch(/^Anda yang memvalidasi hasil ini\. Otorisasi harus oleh Sp\.PK lain\./)
    expect(pesanGalatLab(new ApiError(422, 'pemeriksaan_lab_belum_diatur', 'x'))).toMatch(/belum punya data induk lab.*Daftar kerja sudah dimuat ulang\.$/)
    expect(perluMuatUlang(new ApiError(422, 'pemeriksaan_lab_belum_diatur', 'x'))).toBe(true)
    expect(perluMuatUlang(new ApiError(422, 'validasi_gagal', 'x'))).toBe(false)
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
const tombolLabel = (label: string) => document.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`)
const dialog = () => document.querySelector<HTMLElement>('[role="dialog"]')
const tab = (nama: string) => [...document.querySelectorAll<HTMLButtonElement>('[role="tab"]')].find((t) => t.textContent?.trim() === nama)
async function isi(el: HTMLInputElement | HTMLTextAreaElement, nilai: string) {
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang()
}
async function pilihTab(nama: string) {
  const t = tab(nama)!
  t.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
  t.click()
  await tenang()
}

describe('TampilanHasilLab', () => {
  it('penanda berteks, rujukan yang dipakai, peringatan tanpa rujukan, adendum di bawah hasil asli', () => {
    const w = mount(TampilanHasilLab, {
      props: {
        hasil: {
          ...DASAR,
          adendum: [{ id: 'a1', hasil_id: 'h', nilai: null, nilai_teks: null, satuan: '{contoh}', rujukan_min: null, rujukan_maks: null, kritis_min: null, kritis_maks: null, penanda: null, kritis: false, peringatan: 'nilai_rujukan_tidak_ada', alasan: 'Koreksi sintetis', dibuat_oleh: { id: 'x', nama: 'Contoh Analis Lab' }, dibuat_pada: '2026-11-02T04:00:00Z' }],
        },
      },
    })
    const t = w.text()
    expect(t).toContain('Hasil asli')
    expect(t).toContain('5 {contoh}')
    expect(t).toContain('Rendah')
    expect(t).toContain('Rujukan 10–20 {contoh} · Kritis bila di bawah 5 atau di atas 30')
    expect(t.indexOf('Adendum')).toBeGreaterThan(t.indexOf('Rendah'))
    expect(t).toContain('Tanpa nilai rujukan RS, tidak ditandai')
    expect(t).toContain('Alasan: Koreksi sintetis')
    expect(t).toContain('Contoh Analis Lab')
  })
})

describe('DaftarKerjaLabPage: tahap Hasil dan Otorisasi', () => {
  async function pasang() {
    const { router, global } = plugin()
    await router.push('/penunjang/laboratorium')
    mount(DaftarKerjaLabPage, { attachTo: document.body, global })
    await tenang()
  }

  it('tab tahap menurut izin; input hasil → penanda berteks; validasi lewat dialog beridentitas sebelum kirim', async () => {
    await siapkanCito()
    await pasang()
    expect([...document.querySelectorAll('[role="tab"]')].map((t) => t.textContent?.trim())).toEqual(['Spesimen', 'Hasil'])
    await pilihTab('Hasil')
    expect(permintaan.some((p) => p.jalur === '/lab/daftar-kerja' && p.kueri.get('tahap') === 'hasil')).toBe(true)
    tombolLabel('Input hasil Pemeriksaan lab contoh satu Pasien Contoh Cito')!.click()
    await tenang()
    let d = dialog()!
    expect(d.textContent).toContain('Pasien Contoh Cito, No. RM 900.001, lahir 1 Jan 1970')
    tombol('Simpan hasil', document)!.click()
    await tenang()
    expect(d.textContent).toContain('Isi hasil pemeriksaan.')
    await isi(d.querySelector<HTMLInputElement>('input[type="text"]')!, '5')
    tombol('Simpan hasil', document)!.click()
    await tenang()
    expect(dialog()).toBeNull()
    expect(document.body.textContent).toContain('Hasil Pemeriksaan lab contoh satu untuk Pasien Contoh Cito tersimpan: 5 {contoh}, Rendah. Menunggu validasi.')
    const sel = tombolLabel('Validasi Pemeriksaan lab contoh satu Pasien Contoh Cito')!.closest('li')!
    expect(sel.textContent).toContain('Rendah')
    expect(sel.textContent).toContain('Rujukan 10–20 {contoh}')

    const sebelum = permintaan.length
    tombolLabel('Validasi Pemeriksaan lab contoh satu Pasien Contoh Cito')!.click()
    await tenang()
    d = dialog()!
    // Identitas dua penanda dan hasil tampil sebelum kirim; belum ada POST validasi.
    expect(d.textContent).toContain('Pasien Contoh Cito, No. RM 900.001, lahir 1 Jan 1970')
    expect(d.textContent).toContain('Rendah')
    expect(permintaan.slice(sebelum).some((p) => p.jalur.endsWith('/validasi'))).toBe(false)
    tombol('Validasi hasil', d)!.click()
    await tenang()
    expect(permintaan.slice(sebelum).some((p) => p.jalur.endsWith('/validasi'))).toBe(true)
    expect(document.body.textContent).toContain('Hasil Pemeriksaan lab contoh satu untuk Pasien Contoh Cito divalidasi. Hasil keluar ke pemesan dan penanggung jawab pasien.')
    // Butir divalidasi tetap tampil selama butir lain di order ini masih di tahap Hasil; adendum tersedia.
    expect(tombolLabel('Adendum Pemeriksaan lab contoh satu Pasien Contoh Cito')).not.toBeNull()
  })

  it('jenis hasil belum diketahui: angka untuk pemeriksaan teks dikirim ulang sekali sebagai teks', async () => {
    await siapkanCito()
    await pasang()
    await pilihTab('Hasil')
    tombolLabel('Input hasil Pemeriksaan lab contoh dua Pasien Contoh Cito')!.click()
    await tenang()
    await isi(dialog()!.querySelector<HTMLInputElement>('input[type="text"]')!, '7')
    tombol('Simpan hasil', document)!.click()
    await tenang()
    const post = permintaan.filter((p) => p.metode === 'post' && p.jalur.endsWith('/hasil'))
    expect(post.map((p) => p.isi)).toEqual([{ nilai: 7, catatan: null }, { nilai_teks: '7', catatan: null }])
    expect(dialog()).toBeNull()
  })

  it('409 sesudah hasil divalidasi orang lain: pesan jelas di dialog dan daftar dimuat ulang', async () => {
    const cito = await siapkanCito()
    await inputHasilLab(cito.item[0]!.id, { nilai: 5 })
    await pasang()
    await pilihTab('Hasil')
    tombolLabel('Input ulang hasil Pemeriksaan lab contoh satu Pasien Contoh Cito')!.click()
    await tenang()
    expect(dialog()!.textContent).toContain('Hasil sebelumnya')
    await validasiHasilLab((await daftarKerjaLab('cito', null, 50, 'hasil')).data[0]!.item[0]!.hasil!.id)
    await isi(dialog()!.querySelector<HTMLInputElement>('input[type="text"]')!, '6')
    const sebelum = permintaan.length
    tombol('Simpan hasil', document)!.click()
    await tenang()
    expect(dialog()!.textContent).toContain('Hasil ini sudah divalidasi, jadi tidak bisa diubah lagi. Koreksi lewat adendum. Daftar kerja sudah dimuat ulang.')
    expect(permintaan.slice(sebelum).some((p) => p.jalur === '/lab/daftar-kerja')).toBe(true)
  })

  it('adendum: identitas dan hasil asli di dialog, alasan wajib, hasil asli tetap tampil dengan adendum bertanda', async () => {
    const cito = await siapkanCito()
    const h = await inputHasilLab(cito.item[0]!.id, { nilai: 5 })
    await validasiHasilLab(h.id)
    await pasang()
    await pilihTab('Hasil')
    tombolLabel('Adendum Pemeriksaan lab contoh satu Pasien Contoh Cito')!.click()
    await tenang()
    const d = dialog()!
    expect(d.textContent).toContain('Pasien Contoh Cito, No. RM 900.001, lahir 1 Jan 1970')
    expect(d.textContent).toContain('Rendah')
    await isi(d.querySelector<HTMLInputElement>('input[type="text"]')!, '10')
    tombol('Simpan adendum', document)!.click()
    await tenang()
    expect(d.textContent).toContain('Tulis alasan koreksi, minimal 5 karakter.')
    expect(permintaan.some((p) => p.jalur.endsWith('/adendum'))).toBe(false)
    await isi(d.querySelector('textarea')!, 'Salah ketik sintetis')
    tombol('Simpan adendum', document)!.click()
    await tenang()
    expect(permintaan.find((p) => p.jalur.endsWith('/adendum'))?.isi).toEqual({ nilai: 10, alasan: 'Salah ketik sintetis' })
    expect(document.body.textContent).toContain('Adendum Pemeriksaan lab contoh satu untuk Pasien Contoh Cito tercatat: 10 {contoh}. Hasil asli tetap tersimpan.')
    const li = tombolLabel('Adendum Pemeriksaan lab contoh satu Pasien Contoh Cito')!.closest('li')!
    expect(li.textContent).toContain('Hasil asli')
    expect(li.textContent).toContain('Alasan: Salah ketik sintetis')
  })

  it('Sp.PK: hanya tab Otorisasi; dialog otorisasi menyebut validator dan identitas', async () => {
    const spk = await siapkanSpk()
    const h = await inputHasilLab(spk.item[0]!.id, { nilai: 5 })
    await validasiHasilLab(h.id)
    jadi(SPPK, { id: '00000000-0000-7000-b230-000000000901', nama: 'Contoh Spesialis Patologi Klinik' })
    await pasang()
    expect([...document.querySelectorAll('[role="tab"]')].map((t) => t.textContent?.trim())).toEqual(['Otorisasi'])
    tombolLabel('Otorisasi Pemeriksaan lab contoh tiga Pasien Contoh Tanpa Lokasi')!.click()
    await tenang()
    const d = dialog()!
    expect(d.textContent).toContain('Pasien Contoh Tanpa Lokasi, No. RM 900.003, lahir 5 Mei 1985')
    expect(d.textContent).toContain('Divalidasi analis')
    expect(d.textContent).toContain('Contoh Analis Lab')
    expect(d.textContent).toContain('Tinggi')
    tombol('Otorisasi hasil', d)!.click()
    await tenang()
    expect(document.body.textContent).toContain('Hasil Pemeriksaan lab contoh tiga untuk Pasien Contoh Tanpa Lokasi diotorisasi. Hasil keluar ke pemesan dan penanggung jawab pasien.')
  })
})

describe('LinimasaHasilLab', () => {
  it('hasil tervalidasi terbaru dulu dengan penanda berteks dan pelaku; 403 → pesan netral tanpa data', async () => {
    const cito = await siapkanCito()
    const h = await inputHasilLab(cito.item[0]!.id, { nilai: 5 })
    await validasiHasilLab(h.id)
    jadi(['rawat_jalan.pemeriksaan.lihat'])
    const { global } = plugin()
    mount(LinimasaHasilLab, { attachTo: document.body, global, props: { episodeId: h.episode_id } })
    await tenang()
    const s = document.querySelector('section')!
    expect(s.querySelector('h3')!.textContent).toBe('Hasil laboratorium')
    expect(s.textContent).toContain('Pemeriksaan lab contoh satu')
    expect(s.textContent).toContain('Rendah')
    expect(s.textContent).toContain('Divalidasi Contoh Analis Lab')
    expect(tombol('Adendum', s)).toBeUndefined()
    document.body.innerHTML = ''
    jadi([])
    mount(LinimasaHasilLab, { attachTo: document.body, global: plugin().global, props: { episodeId: h.episode_id } })
    await tenang()
    expect(document.body.textContent).toContain('Hasil laboratorium episode ini tidak bisa dibuka dengan akses akun Anda.')
    expect(document.body.textContent).not.toContain('Pemeriksaan lab contoh satu')
  })
})
