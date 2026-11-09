import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { aksiAntrean } from '@/modules/antrean/api'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, ID_SUPERADMIN_DUA } from '@/modules/akses/tiruan/serverTiruan'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { PIN_TIRUAN } from '@/modules/ttd/tiruan/serverTtd'
import { adendumCppt, ambilAsesmenKunjungan, batalkanAlergi, catatAlergi, catatDiagnosis, cariKodeIcd10, daftarAlergi, daftarDiagnosis, daftarWorklist, linimasaCppt, mulaiAsesmen, mulaiPeriksa, ringkasanKlinis, sahkanCppt, selesaikanAsesmen, selesaiPeriksa, tulisCppt, ubahAsesmen, ubahCppt, ubahDiagnosis } from './api'
import CpptLinimasa from './components/CpptLinimasa.vue'
import DialogAksesDarurat from './components/DialogAksesDarurat.vue'
import DialogAsesmen from './components/DialogAsesmen.vue'
import PanelPemeriksaan from './components/PanelPemeriksaan.vue'
import { pesanBukaAksesDarurat, pesanTinjauAksesDarurat } from '@/modules/rekam-medis/aksesDarurat'
import { ringkasItemResep } from '@/shared/api/resep'
import { lamaMenunggu, pesanCppt, pesanKlinis, pesanPemeriksaan, pesanResep, sisaPerbaikan } from './rawatJalan'
import type { Cppt } from './tipe'

const adapterAsli = klien.defaults.adapter
const SEKARANG = new Date('2026-11-02T03:00:00Z')
const HARI_INI = '2026-11-02'
const IZIN = [
  'rawat_jalan.worklist.lihat', 'rawat_jalan.asesmen_perawat.lihat', 'rawat_jalan.asesmen_perawat.input', 'rawat_jalan.asesmen_perawat.perbaiki', 'pendaftaran.antrean.perbaiki',
  'rawat_jalan.cppt.lihat', 'rawat_jalan.cppt.input', 'rawat_jalan.cppt.perbaiki', 'rawat_jalan.cppt.sahkan',
  'rawat_jalan.pemeriksaan.lihat', 'rawat_jalan.pemeriksaan.input', 'rawat_jalan.pemeriksaan.perbaiki', 'rawat_jalan.pemeriksaan.sahkan',
]
let gabungan: ReturnType<typeof buatServerGabungan>
let waktu = SEKARANG
let POLI: string

beforeEach(() => {
  setActivePinia(createPinia())
  waktu = SEKARANG
  gabungan = buatServerGabungan({ sekarang: () => waktu })
  pasangTiruan(klien, gabungan)
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'perawat', mfa_aktif: true })
  POLI = gabungan.lokasi.contoh.poliAnak.id
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

const kode = async (p: Promise<unknown>) => ((await p.then(() => null, (e: unknown) => e)) as ApiError | null)?.kode
const worklist = async (tahap?: 'perawat' | 'dokter') => (await daftarWorklist({ tanggal: HARI_INI, poli_id: POLI, tahap })).data
const SOAP = { subjektif: 'Batuk dua hari', objektif: null, asesmen: 'ISPA', plan: 'Edukasi minum' }
const ttd = (pin: string | null = PIN_TIRUAN) => ({ pin, pernyataan_disetujui: true })
const LENGKAP = { keluhan_utama: 'Demam tiga hari', tanda_vital: { sistolik: 120, diastolik: 80, nadi: 80, napas: 18, suhu: 37.2, berat_badan: 70, tinggi_badan: 170 } }

describe('pembantu tampilan', () => {
  it('lama menunggu dalam menit dan jam', () => {
    const t = new Date('2026-11-02T03:00:00Z').getTime()
    expect(lamaMenunggu('2026-11-02T02:59:50Z', t)).toBe('kurang dari 1 menit')
    expect(lamaMenunggu('2026-11-02T02:53:00Z', t)).toBe('7 menit')
    expect(lamaMenunggu('2026-11-02T01:55:00Z', t)).toBe('1 jam 5 menit')
  })
  it('sisa waktu perbaikan: hari dan jam, menit, kosong bila lewat', () => {
    const t = new Date('2026-11-02T03:00:00Z').getTime()
    expect(sisaPerbaikan('2026-11-03T07:00:00Z', t)).toBe('1 hari 4 jam')
    expect(sisaPerbaikan('2026-11-02T03:35:00Z', t)).toBe('35 menit')
    expect(sisaPerbaikan('2026-11-02T02:00:00Z', t)).toBeNull()
    expect(sisaPerbaikan(null, t)).toBeNull()
  })
  it('pesan galat memberi petunjuk dari kodenya', () => {
    expect(pesanKlinis(new ApiError(409, 'batas_perbaikan_lewat', 'x'))).toContain('adendum')
    expect(pesanKlinis(new ApiError(409, 'alergi_sudah_tercatat', 'x'))).toContain('sudah tercatat')
    expect(pesanKlinis(new ApiError(412, 'versi_usang', 'x'))).toContain('Muat ulang')
  })
  it('e-resep: galat lapis 5 dan peringatan baru diberi langkah berikutnya; butir dibaca dalam satu baris', () => {
    expect(pesanResep(new ApiError(403, 'izin_praktik_tidak_berlaku', 'x'))).toContain('SIP/SPK')
    expect(pesanResep(new ApiError(409, 'peringatan_belum_ditanggapi', 'x'))).toContain('alasan')
    expect(pesanResep(new ApiError(409, 'sudah_disahkan', 'x'))).toContain('Batalkan')
    expect(pesanResep(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'item.0.barang_id' }]))).toContain('pilih ulang')
    const barang = { id: 'b', kode: 'K', nama: 'Obat contoh', zat_aktif: null, kekuatan: '500 mg', sediaan: null, satuan_terkecil: 'tablet' }
    expect(ringkasItemResep({ id: 'i', barang, dosis: null, frekuensi_per_hari: 3, jumlah_per_pakai: 1, hari: 5, jumlah: 15, rute: 'oral', aturan_pakai: null, catatan: null }))
      .toBe('Obat contoh 500 mg · 3× sehari 1 tablet, 5 hari · oral · jumlah 15 tablet')
  })
})

describe('pesan akses darurat', () => {
  const galat = (status: number, k: string) => new ApiError(status, k, `pesan server ${k}`)
  it('buka: tiap kode server punya pesan sendiri, selain itu pesan server', () => {
    expect(pesanBukaAksesDarurat(galat(403, 'izin_praktik_tidak_berlaku'))).toContain('izin praktik')
    expect(pesanBukaAksesDarurat(galat(403, 'mfa_belum_terdaftar'))).toContain('verifikasi dua langkah')
    expect(pesanBukaAksesDarurat(galat(403, 'akses_ditolak'))).toContain('tidak berwenang')
    expect(pesanBukaAksesDarurat(galat(422, 'lain'))).toBe('pesan server lain')
    expect(pesanBukaAksesDarurat(new Error('x'))).toBe('Terjadi kesalahan. Coba lagi.')
  })
  it('tinjau: sudah ditinjau dan diri sendiri punya pesan sendiri', () => {
    expect(pesanTinjauAksesDarurat(galat(409, 'sudah_ditinjau'))).toContain('sudah ditinjau')
    expect(pesanTinjauAksesDarurat(galat(403, 'diri_sendiri'))).toContain('buka sendiri')
    expect(pesanTinjauAksesDarurat(galat(422, 'lain'))).toBe('pesan server lain')
  })
})

describe('worklist dan asesmen awal (server tiruan v0.23.0)', () => {
  it('alur: panggil → draf → selesai memindahkan pasien ke tahap dokter, IMT dari server', async () => {
    const [b] = await worklist('perawat')
    expect(b!.asesmen_awal).toBeNull()
    expect((await aksiAntrean(b!.antrean_id, 'panggil')).status).toBe('dipanggil')

    const { data: draf, etag } = await mulaiAsesmen(b!.kunjungan_id, LENGKAP)
    expect(draf).toMatchObject({ status: 'draf', imt: 24.22 })
    expect(await kode(mulaiAsesmen(b!.kunjungan_id, {}))).toBe('asesmen_sudah_ada')

    const { data: selesai } = await selesaikanAsesmen(draf.id, etag)
    expect(selesai.status).toBe('selesai')
    expect(selesai.batas_perbaikan_pada).toBe('2026-11-04T03:00:00.000Z')
    expect((await worklist('dokter')).map((x) => x.nomor_tampil)).toContain(b!.nomor_tampil)
    expect((await worklist('perawat')).map((x) => x.nomor_tampil)).not.toContain(b!.nomor_tampil)
  })

  it('selesai ditolak 422 bila syarat minimal kurang', async () => {
    const [b] = await worklist()
    const { data, etag } = await mulaiAsesmen(b!.kunjungan_id, { keluhan_utama: 'Pusing' })
    expect(await kode(selesaikanAsesmen(data.id, etag))).toBe('validasi_gagal')
  })

  it('perbaikan sesudah selesai wajib beralasan dan menambah versi; lewat 2 × 24 jam → 409 batas_perbaikan_lewat', async () => {
    const [b] = await worklist()
    const { data: d, etag } = await mulaiAsesmen(b!.kunjungan_id, LENGKAP)
    const { data: s, etag: e2 } = await selesaikanAsesmen(d.id, etag)
    expect(await kode(ubahAsesmen(s.id, e2, { catatan: 'koreksi' }))).toBe('validasi_gagal')
    const { data: p, etag: e3 } = await ubahAsesmen(s.id, e2, { catatan: 'koreksi', alasan_perubahan: 'Salah ketik berat badan' })
    expect(p.jumlah_versi).toBe(1)
    expect(await kode(ubahAsesmen(s.id, e2, { catatan: 'lagi', alasan_perubahan: 'Salah ketik lagi' }))).toBe('versi_usang')
    waktu = new Date(SEKARANG.getTime() + 49 * 3_600_000)
    expect(await kode(ubahAsesmen(p.id, e3, { catatan: 'terlambat', alasan_perubahan: 'Salah ketik lagi' }))).toBe('batas_perbaikan_lewat')
    expect((await ambilAsesmenKunjungan(b!.kunjungan_id)).data).toMatchObject({ jumlah_versi: 1 })
  })

  it('tanpa izin input → 403; tanpa asesmen → 404', async () => {
    const [b] = await worklist()
    expect(await kode(ambilAsesmenKunjungan(b!.kunjungan_id))).toBe('tidak_ditemukan')
    gabungan.akses.sebagai(ID_SUPERADMIN_DUA)
    expect(await kode(mulaiAsesmen(b!.kunjungan_id, {}))).toBe('akses_ditolak')
  })

  it('alergi: dicatat sekali, tampil di baris worklist, dibatalkan dengan alasan', async () => {
    const [b] = await worklist()
    const a = await catatAlergi(b!.pasien.id, { zat: 'Amoksisilin', jenis: 'obat', tingkat: 'berat' })
    expect(await kode(catatAlergi(b!.pasien.id, { zat: ' amoksisilin ', jenis: 'obat' }))).toBe('alergi_sudah_tercatat')
    expect((await worklist()).find((x) => x.antrean_id === b!.antrean_id)!.ada_alergi).toBe(true)
    expect(await kode(batalkanAlergi(a.id, 'x'))).toBe('validasi_gagal')
    expect((await batalkanAlergi(a.id, 'Salah catat pasien')).status).toBe('batal')
    expect((await daftarAlergi(b!.pasien.id)).data).toHaveLength(0)
    expect((await worklist()).find((x) => x.antrean_id === b!.antrean_id)!.ada_alergi).toBe(false)
  })
})

describe('DialogAsesmen — layar', () => {
  async function tenang() {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, 10))
    }
  }
  async function pasang(baris: Awaited<ReturnType<typeof worklist>>[number]) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/rawat-jalan/worklist')
    mount(DialogAsesmen, {
      attachTo: document.body,
      props: { baris, open: true },
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
  }
  const tombol = (nama: string) => [...document.querySelectorAll('button')].find((x) => x.textContent?.trim() === nama) as HTMLButtonElement | undefined

  it('menampilkan dua penanda identitas, formulir, panel alergi, tanpa penanda ambang', async () => {
    const [b] = await worklist()
    await pasang(b!)
    const teks = document.body.textContent ?? ''
    expect(teks).toContain(b!.pasien.nama)
    expect(teks).toContain('No. RM')
    expect(teks).toContain('Keluhan utama')
    expect(teks).toContain('Alergi pasien')
    expect(tombol('Selesaikan asesmen')).toBeTruthy()
    expect(document.querySelector('[data-ambang], .tv-kritis')).toBeNull()
  })

  it('asesmen selesai menampilkan sisa waktu perbaikan dan mewajibkan alasan', async () => {
    waktu = new Date() // layar menghitung sisa waktu dari jam perangkat
    const [b] = await worklist()
    const { data, etag } = await mulaiAsesmen(b!.kunjungan_id, LENGKAP)
    await selesaikanAsesmen(data.id, etag)
    await pasang({ ...b!, asesmen_awal: { id: data.id, status: 'selesai' } })
    expect(document.body.textContent).toContain('Perbaikan masih bisa dalam 1 hari')
    expect(tombol('Simpan perbaikan')!.disabled).toBe(true)
    expect(document.body.textContent).toContain('24,22')
  })

  it('tanpa izin input dan tanpa asesmen: formulir tidak dirender', async () => {
    aturIzin(['rawat_jalan.asesmen_perawat.lihat'])
    const [b] = await worklist()
    await pasang(b!)
    expect(document.body.textContent).toContain('tidak punya izin mengisi asesmen')
    expect(document.querySelector('#form-asesmen')).toBeNull()
  })
})

describe('CPPT (server tiruan v0.24.0)', () => {

  it('draf → sahkan dengan PIN penulis (salah: tetap draf) → perbaikan beralasan menambah versi → lewat batas 409 → adendum', async () => {
    const [b] = await worklist()
    const { data: d, etag } = await tulisCppt(b!.kunjungan_id, SOAP)
    expect(d).toMatchObject({ status: 'draf', dapat: { ubah: true, sahkan: true, adendum: false } })
    expect(await kode(tulisCppt(b!.kunjungan_id, { subjektif: ' ', objektif: null, asesmen: null, plan: null }))).toBe('validasi_gagal')

    expect(await kode(sahkanCppt(d.id, etag, ttd('000000')))).toBe('pin_salah')
    expect((await linimasaCppt('pasien', b!.pasien.id, {})).data[0]).toMatchObject({ status: 'draf' })
    const { data: sah, etag: e2 } = await sahkanCppt(d.id, etag, ttd())
    expect(sah).toMatchObject({ status: 'sah', batas_perbaikan_pada: '2026-11-04T03:00:00.000Z' })

    expect(await kode(ubahCppt(sah.id, e2, { plan: 'Kontrol 3 hari', ...ttd() }))).toBe('validasi_gagal')
    const { data: p, etag: e3 } = await ubahCppt(sah.id, e2, { plan: 'Kontrol 3 hari', alasan_perubahan: 'Melengkapi rencana', ...ttd() })
    expect(p.jumlah_versi).toBe(1)
    expect(await kode(adendumCppt(p.id, e3, { isi: 'x', alasan: 'Koreksi dosis', ...ttd() }))).toBe('masih_bisa_diperbaiki')

    waktu = new Date(SEKARANG.getTime() + 49 * 3_600_000)
    expect(await kode(ubahCppt(p.id, e3, { plan: 'terlambat', alasan_perubahan: 'Koreksi rencana', ...ttd() }))).toBe('batas_perbaikan_lewat')
    const { data: a } = await adendumCppt(p.id, e3, { isi: 'Dosis parasetamol 500 mg', alasan: 'Koreksi dosis', ...ttd() })
    expect(a.adendum).toHaveLength(1)
    expect(a.isi.plan).toBe('Kontrol 3 hari')
  })

  it('pesan galat CPPT memberi langkah berikutnya', () => {
    expect(pesanCppt(new ApiError(409, 'batas_perbaikan_lewat', 'x'))).toContain('adendum')
    expect(pesanCppt(new ApiError(412, 'versi_usang', 'x'))).toContain('Muat ulang')
  })
})

describe('CpptLinimasa — layar', () => {
  // Lepas tiap layar sebelum body dikosongkan: layar yang tertinggal ikut bereaksi saat uji berikutnya mengubah izin global
  // (mis. dialog akses darurat terpasang/terlepas) dan gagal melepas simpul yang sudah dihapus.
  const terpasang: VueWrapper[] = []
  afterEach(() => terpasang.splice(0).forEach((w) => w.unmount()))
  async function tenang() {
    for (let i = 0; i < 8; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, 10))
    }
  }
  async function pasang(baris: Awaited<ReturnType<typeof worklist>>[number]) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/rawat-jalan/cppt')
    const wrapper = mount(CpptLinimasa, {
      attachTo: document.body,
      props: { baris },
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    terpasang.push(wrapper)
    await tenang()
    return wrapper
  }
  const tombol = (nama: string) => [...document.querySelectorAll('button')].find((x) => x.textContent?.trim() === nama) as HTMLButtonElement | undefined
  const isi = (label: string, nilai: string) => {
    const id = [...document.querySelectorAll('label')].find((l) => l.textContent?.trim().startsWith(label))!.getAttribute('for')!
    const el = document.getElementById(id) as HTMLInputElement | HTMLTextAreaElement
    el.value = nilai
    el.dispatchEvent(new Event('input'))
  }
  const aktifkanTtd = () => {
    const s = useSesiStore()
    s.pengguna = { ...s.pengguna!, ttd: { ...s.pengguna!.ttd, aktif: true } }
  }

  it('entri berlabel tampil "Terbatas" tanpa isi; dengan filter profesi tidak dikirim sama sekali', async () => {
    waktu = new Date()
    const [b] = await worklist()
    const { data } = await tulisCppt(b!.kunjungan_id, { subjektif: 'Rahasia klinis', objektif: null, asesmen: null, plan: null, label: 'psikiatri' })
    const c = gabungan.rawatJalan.contoh.cppt.find((x: Cppt) => x.id === data.id)!
    Object.assign(c, { status: 'sah', terbatasBagiSaya: true })
    await pasang(b!)
    expect(document.body.textContent).toContain('Terbatas')
    expect(document.body.textContent).not.toContain('Rahasia klinis')
    expect(tombol('Buka akses darurat')).toBeUndefined()
    expect((await linimasaCppt('pasien', b!.pasien.id, { profesi: 'perawat' })).data).toHaveLength(0)
  })

  it('pemegang izin darurat melihat satu tombol akses darurat per pasien, bukan per entri Terbatas', async () => {
    waktu = new Date()
    aturIzin([...IZIN, 'rawat_jalan.cppt.darurat'])
    const [b] = await worklist()
    for (const subjektif of ['Rahasia satu', 'Rahasia dua']) {
      const { data } = await tulisCppt(b!.kunjungan_id, { subjektif, objektif: null, asesmen: null, plan: null, label: 'psikiatri' })
      Object.assign(gabungan.rawatJalan.contoh.cppt.find((x: Cppt) => x.id === data.id)!, { status: 'sah', terbatasBagiSaya: true })
    }
    await pasang(b!)
    expect(document.querySelectorAll('[role="note"]').length).toBeGreaterThanOrEqual(2)
    expect([...document.querySelectorAll('button')].filter((x) => x.textContent?.trim() === 'Buka akses darurat')).toHaveLength(1)
    expect(document.body.textContent).toContain('Sebagian catatan pasien ini terbatas untuk akun Anda.')
  })

  it('dialog akses darurat menolak uraian pendek tanpa memanggil server', async () => {
    const wrapper = mount(DialogAksesDarurat, { attachTo: document.body, props: { open: true, pasienId: 'p1', identitas: 'Pasien Contoh' } })
    await tenang()
    expect(document.body.textContent).toContain('tercatat atas nama Anda')
    isi('Uraian keadaan darurat', 'pendek')
    ;(document.getElementById('form-akses-darurat') as HTMLFormElement).requestSubmit()
    await tenang()
    expect(document.body.textContent).toContain('minimal 10 karakter')
    wrapper.unmount()
  })

  it('tulis draf dari formulir SOAP polos lalu sahkan dengan PIN sendiri', async () => {
    waktu = new Date()
    aktifkanTtd()
    const [b] = await worklist()
    await pasang(b!)
    expect(document.body.textContent).toContain('Belum ada catatan CPPT')
    tombol('Tulis CPPT')!.click()
    await tenang()
    isi('S · Subjektif', 'Nyeri ulu hati')
    await tenang()
    tombol('Simpan draf')!.click()
    await tenang()
    expect(document.body.textContent).toContain('Nyeri ulu hati')
    expect(document.body.textContent).toContain('Draf hanya terlihat oleh Anda')

    tombol('Sahkan dengan PIN')!.click()
    await tenang()
    const dialog = document.querySelector('[role="dialog"]')!
    expect(dialog.textContent).toContain('Penanda tangan')
    ;(dialog.querySelector('input[type="checkbox"]') as HTMLInputElement).click()
    isi('PIN tanda tangan', PIN_TIRUAN)
    await tenang()
    tombol('Tanda tangani')!.click()
    await tenang()
    expect(document.body.textContent).toContain('Disahkan')
    expect(document.body.textContent).toMatch(/Perbaikan masih bisa dalam [12] hari/)
  })

  it('perbaikan yang ditolak karena batas 2 × 24 jam lewat beralih ke adendum (B-14)', async () => {
    waktu = new Date()
    aktifkanTtd()
    const [b] = await worklist()
    const { data, etag } = await tulisCppt(b!.kunjungan_id, SOAP)
    await sahkanCppt(data.id, etag, ttd())
    await pasang(b!)
    tombol('Perbaiki')!.click()
    await tenang()
    isi('Alasan perubahan', 'Melengkapi rencana')
    await tenang()
    waktu = new Date(Date.now() + 49 * 3_600_000)
    tombol('Simpan perbaikan dan tandatangani')!.click()
    await tenang()
    const dialog = document.querySelector('[role="dialog"]')!
    ;(dialog.querySelector('input[type="checkbox"]') as HTMLInputElement).click()
    isi('PIN tanda tangan', PIN_TIRUAN)
    await tenang()
    tombol('Tanda tangani')!.click()
    await tenang()
    expect(document.body.textContent).toContain('Tulis koreksinya sebagai adendum')
    expect(tombol('Tandatangani adendum')).toBeTruthy()
  })
})

describe('pemeriksaan dokter (server tiruan v0.25.0)', () => {
  it('mulai periksa, diagnosis dari paket RS, syarat selesai, lalu selesai', async () => {
    const [b] = await worklist()
    const id = b!.kunjungan_id
    expect(await kode(catatDiagnosis(id, 'ZZ01', 'utama'))).toBe('status_tidak_sesuai')
    const mulai = await mulaiPeriksa(id)
    expect(mulai.data.status).toBe('dilayani')
    expect((await cariKodeIcd10('batuk')).map((k) => k.kode)).toEqual(['ZZ02'])
    expect(await kode(catatDiagnosis(id, 'A00', 'utama'))).toBe('validasi_gagal')

    const e = await selesaiPeriksa(id, mulai.etag).then(() => null, (x: unknown) => x as ApiError)
    expect(e?.kode).toBe('syarat_selesai_belum_lengkap')
    expect(pesanPemeriksaan(e)).toContain('diagnosis utama belum dicatat')

    const utama = await catatDiagnosis(id, 'ZZ01', 'utama')
    expect(await kode(catatDiagnosis(id, 'ZZ02', 'utama'))).toBe('diagnosis_utama_sudah_ada')
    const sekunder = await catatDiagnosis(id, 'ZZ02', 'sekunder')
    expect(await kode(ubahDiagnosis(sekunder, { jenis: 'utama' }))).toBe('diagnosis_utama_sudah_ada')
    expect((await ubahDiagnosis(sekunder, { status: 'batal', alasan_batal: 'Salah pilih' })).status).toBe('batal')
    expect((await daftarDiagnosis(id)).data.map((d) => d.kode)).toEqual(['ZZ01'])
    expect((await ringkasanKlinis(b!.pasien.id)).kunjungan_terakhir[0]?.diagnosis_utama?.kode).toBe(utama.kode)

    expect((await selesaiPeriksa(id, mulai.etag)).data.status).toBe('selesai')
    expect(await kode(mulaiPeriksa(id))).toBe('status_tidak_sesuai')
  })

  it('pesan galat menunjuk langkah berikutnya', () => {
    expect(pesanPemeriksaan(new ApiError(409, 'paket_referensi_belum_dimuat', 'x'))).toContain('langkah 4')
    expect(pesanPemeriksaan(new ApiError(409, 'syarat_selesai_belum_lengkap', 'x', [{ kolom: 'cppt_dokter_draf', jumlah: 2 }]))).toContain('2 CPPT dokter masih draf')
    expect(pesanPemeriksaan(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'kode' }]))).toContain('paket ICD-10 RS')
  })
})

describe('PanelPemeriksaan — layar', () => {
  async function tenang() {
    for (let i = 0; i < 8; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, 10))
    }
  }
  it('membuka panel memulai pemeriksaan; selesai ditolak dengan daftar yang kurang', async () => {
    const [b] = await worklist()
    mount(PanelPemeriksaan, {
      attachTo: document.body,
      props: { baris: b! },
      global: { plugins: [[VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    expect(document.body.textContent).toContain(b!.pasien.nama)
    expect(document.body.textContent).toContain('Belum ada diagnosis')
    expect(document.body.textContent).toContain('Belum ada order laboratorium atau radiologi di episode ini.') // OrderPenunjang (2.16)
    expect((await mulaiPeriksa(b!.kunjungan_id)).data.status).toBe('dilayani') // sudah dimulai oleh panel
    const selesai = [...document.querySelectorAll('button')].find((x) => x.textContent?.trim() === 'Selesaikan kunjungan')!
    selesai.click()
    await tenang()
    expect(document.body.textContent).toContain('diagnosis utama belum dicatat')
  })
})
