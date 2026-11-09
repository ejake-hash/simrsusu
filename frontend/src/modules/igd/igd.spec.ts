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
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { batalkanKunjungan, buatKunjunganIgd, daftarKunjunganIgd } from '@/modules/pendaftaran/apiKunjungan'
import { lamaDiIgd } from '@/modules/pendaftaran/kunjungan'
import { daftarPenjamin } from '@/modules/penjamin/api'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import PendaftaranIgdPage from './pages/PendaftaranIgdPage.vue'

/** Tiket 2.23-fe: server tiruan kontrak v0.31.0 dan layar Pendaftaran IGD. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
/** Jam tiruan yang bisa dimajukan (tanda lebih dari 6 jam). Senin 2 Nov 2026 10.00 WIB. */
let jam = new Date('2026-11-02T03:00:00Z')
const IZIN = ['igd.pendaftaran.lihat', 'igd.pendaftaran.input', 'pendaftaran.pasien.lihat', 'master_data.penjamin.lihat']

let PASIEN1: string
let PASIEN2: string

beforeEach(() => {
  jam = new Date('2026-11-02T03:00:00Z')
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => jam })
  pasangTiruan(klien, gabungan)
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.perawat', mfa_aktif: true })
  PASIEN1 = gabungan.pasien.contoh.satu.id
  PASIEN2 = gabungan.pasien.contoh.dua.id
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

describe('kunjungan IGD — server tiruan kontrak v0.31.0', () => {
  it('registrasi minimal: nomor IGD-, jam tiba = jam server, tanpa poli dan dokter, episode rawat jalan harian', async () => {
    const { data } = await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })
    expect(data.jenis_kunjungan).toBe('igd')
    expect(data.nomor).toMatch(/^IGD-20261102-\d{4}$/)
    expect(data.tiba_pada).toBe(jam.toISOString())
    expect(data.poliklinik).toBeNull()
    expect(data.dokter).toBeNull()
    expect(data.episode.jenis).toBe('rawat_jalan_harian')
    expect(data.lebih_6_jam).toBe(false)
    expect((await daftarKunjunganIgd()).data.map((k) => k.id)).toEqual([data.id])
  })

  it('pasien yang masih di IGD ditolak 409 kunjungan_igd_aktif; sesudah batal boleh didaftarkan lagi', async () => {
    const pertama = (await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data
    await expect(buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).rejects.toMatchObject({ kode: 'kunjungan_igd_aktif', rincian: [{ kunjungan_id: pertama.id }] })
    await batalkanKunjungan(pertama.id, 'Uji daftar ulang')
    expect((await daftarKunjunganIgd()).data).toEqual([])
    expect((await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data.status).toBe('terdaftar')
  })

  it('JKN wajib penjamin BPJS (422 penjamin_wajib); penjamin tak dikenal dan keluhan > 1000 karakter → 422 validasi_gagal', async () => {
    await expect(buatKunjunganIgd({ pasien_id: PASIEN1, cara_bayar: 'jkn' })).rejects.toMatchObject({ kode: 'penjamin_wajib' })
    await expect(buatKunjunganIgd({ pasien_id: PASIEN1, cara_bayar: 'jkn', penjamin_id: '00000000-0000-7000-8000-00000000ffff' })).rejects.toMatchObject({ status: 422, kode: 'validasi_gagal' })
    await expect(buatKunjunganIgd({ pasien_id: PASIEN1, cara_bayar: 'non_jkn', keluhan: 'x'.repeat(1001) })).rejects.toMatchObject({ status: 422, kode: 'validasi_gagal' })
    const bpjs = (await daftarPenjamin({ status: 'aktif', jenis: 'bpjs' }, null, 200)).data[0]!
    expect((await buatKunjunganIgd({ pasien_id: PASIEN1, cara_bayar: 'jkn', penjamin_id: bpjs.id })).data.penjamin?.jenis).toBe('bpjs')
  })

  it('tanda lebih_6_jam menyala sesudah 6 jam sejak tiba; urut jam tiba terlama dulu', async () => {
    const lama = (await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data
    jam = new Date(jam.getTime() + 2 * 3_600_000)
    const baru = (await buatKunjunganIgd({ pasien_id: PASIEN1, cara_bayar: 'non_jkn' })).data
    jam = new Date(jam.getTime() + 4 * 3_600_000 + 60_000)
    const daftar = (await daftarKunjunganIgd()).data
    expect(daftar.map((k) => [k.id, k.lebih_6_jam])).toEqual([[lama.id, true], [baru.id, false]])
  })

  it('lamaDiIgd: menit dan jam', () => {
    const tiba = '2026-11-02T03:00:00Z'
    expect(lamaDiIgd(tiba, new Date('2026-11-02T03:45:00Z'))).toBe('45 menit')
    expect(lamaDiIgd(tiba, new Date('2026-11-02T05:00:00Z'))).toBe('2 jam')
    expect(lamaDiIgd(tiba, new Date('2026-11-02T09:05:00Z'))).toBe('6 jam 5 menit')
  })
})

describe('PendaftaranIgdPage — layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/igd/pendaftaran')
    const wrapper = mount(PendaftaranIgdPage, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return wrapper
  }
  const tombolDaftar = () => [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Daftarkan ke IGD') as HTMLButtonElement | undefined
  async function pilihPasien(wrapper: Awaited<ReturnType<typeof pasang>>, noRm: string) {
    await wrapper.find('#cari-pasien-igd').setValue(noRm)
    await tenang(80)
    const hasil = [...document.querySelectorAll<HTMLButtonElement>('.baris-pasien')]
    expect(hasil.length).toBeGreaterThan(0)
    hasil[0]!.click()
    await tenang()
  }

  it('registrasi sukses: toast berisi nomor IGD dan jam tiba; pasien muncul di daftar IGD', async () => {
    const wrapper = await pasang()
    expect(document.body.textContent).toContain('Pendaftaran IGD')
    expect(tombolDaftar()!.disabled).toBe(true)

    await pilihPasien(wrapper, gabungan.pasien.contoh.dua.no_rm)
    expect(document.body.textContent).toContain('Contoh Pasien Dua')
    // Tanpa No. kartu BPJS → cara bayar non-JKN terpilih, tombol aktif tanpa penjamin.
    expect(tombolDaftar()!.disabled).toBe(false)
    tombolDaftar()!.click()
    await tenang()

    const toast = daftarToast.value.map((t) => t.pesan).join(' | ')
    expect(toast).toMatch(/Contoh Pasien Dua terdaftar di IGD: IGD-20261102-\d{4}, tiba 10\.00 WIB\./)
    const tabel = document.querySelector('table')!
    expect(tabel.textContent).toContain('Contoh Pasien Dua')
    expect(tabel.textContent).toContain('Terdaftar')
  })

  it('409 kunjungan_igd_aktif tampil sebagai peringatan dan baris IGD aktif ditandai', async () => {
    const aktif = (await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data
    const wrapper = await pasang()
    await pilihPasien(wrapper, gabungan.pasien.contoh.dua.no_rm)
    tombolDaftar()!.click()
    await tenang()
    expect(document.body.textContent).toContain('ditandai "Kunjungan aktif pasien ini" di daftar Pasien di IGD')
    // Penanda berupa teks (Lencana), bukan hanya latar baris.
    const baris = [...document.querySelectorAll('tbody tr')].filter((tr) => tr.textContent?.includes('Kunjungan aktif pasien ini'))
    expect(baris).toHaveLength(1)
    expect(baris[0]!.textContent).toContain(aktif.nomor)
  })

  it('daftar menandai kunjungan lebih dari 6 jam dengan lencana teks, bukan hanya warna', async () => {
    await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })
    jam = new Date(jam.getTime() + 6 * 3_600_000 + 60_000)
    await pasang()
    const tabel = document.querySelector('table')!
    expect(tabel.textContent).toContain('Lebih dari 6 jam')
    expect(tabel.textContent).toContain('Contoh Pasien Dua')
  })

  it('tanpa izin input: daftar tampil, formulir dan tombol Daftarkan tidak', async () => {
    aturIzin(['igd.pendaftaran.lihat'])
    await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })
    await pasang()
    expect(tombolDaftar()).toBeUndefined()
    expect(document.querySelector('#cari-pasien-igd')).toBeNull()
    expect(document.querySelector('table')!.textContent).toContain('Contoh Pasien Dua')
  })
})
