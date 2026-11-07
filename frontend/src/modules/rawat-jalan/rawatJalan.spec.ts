import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
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
import { ambilAsesmenKunjungan, batalkanAlergi, catatAlergi, daftarAlergi, daftarWorklist, mulaiAsesmen, selesaikanAsesmen, ubahAsesmen } from './api'
import DialogAsesmen from './components/DialogAsesmen.vue'
import { lamaMenunggu, pesanKlinis, sisaPerbaikan } from './rawatJalan'

const adapterAsli = klien.defaults.adapter
const SEKARANG = new Date('2026-11-02T03:00:00Z')
const HARI_INI = '2026-11-02'
const IZIN = ['rawat_jalan.worklist.lihat', 'rawat_jalan.asesmen_perawat.lihat', 'rawat_jalan.asesmen_perawat.input', 'rawat_jalan.asesmen_perawat.perbaiki', 'pendaftaran.antrean.perbaiki']
let gabungan: ReturnType<typeof buatServerGabungan>
let waktu = SEKARANG
let POLI: string

beforeEach(() => {
  setActivePinia(createPinia())
  waktu = SEKARANG
  gabungan = buatServerGabungan({ sekarang: () => waktu })
  pasangTiruan(klien, gabungan)
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.perawat', mfa_aktif: true })
  POLI = gabungan.lokasi.contoh.poliAnak.id
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

const kode = async (p: Promise<unknown>) => ((await p.then(() => null, (e: unknown) => e)) as ApiError | null)?.kode
const worklist = async (tahap?: 'perawat' | 'dokter') => (await daftarWorklist({ tanggal: HARI_INI, poli_id: POLI, tahap })).data
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
