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
import { batalkanKunjungan, buatKunjunganIgd, catatTriase, daftarInstrumenTriase, daftarKunjunganIgd, daftarTriase } from '@/modules/pendaftaran/apiKunjungan'
import { INSTRUMEN_TRIASE_CONTOH } from '@/modules/pendaftaran/tiruan/serverKunjungan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import PendaftaranIgdPage from './pages/PendaftaranIgdPage.vue'
import TriasePage from './pages/TriasePage.vue'

/** Tiket 2.24: server tiruan kontrak v0.32.0 (triase hanya ditambah) dan layar IGD → Triase. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let jam = new Date('2026-11-02T03:00:00Z')
const IZIN = ['igd.triase.lihat', 'igd.triase.input']
const INSTRUMEN = INSTRUMEN_TRIASE_CONTOH[0]!

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

const majukan = (menit: number) => (jam = new Date(jam.getTime() + menit * 60_000))

describe('triase IGD: server tiruan kontrak v0.32.0', () => {
  it('triase lalu ulang triase: baris baru, terbaru berlaku, jam tiba dari kunjungan', async () => {
    const k = (await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data
    majukan(3)
    const pertama = await catatTriase(k.id, { kategori: 'kuning', instrumen_triase_id: INSTRUMEN.id, skala: 'C2', keluhan: 'Nyeri dada (uji sintetis)' })
    expect(pertama).toMatchObject({ kategori: 'kuning', berlaku: true, skala: { kode: 'C2', label: 'Tingkat contoh 2' }, tiba_pada: k.tiba_pada, ditriase_pada: jam.toISOString() })
    majukan(10)
    await catatTriase(k.id, { kategori: 'merah', instrumen_triase_id: INSTRUMEN.id, skala: 'C1' })

    const riwayat = (await daftarTriase(k.id)).data
    expect(riwayat.map((t) => [t.kategori, t.berlaku])).toEqual([['merah', true], ['kuning', false]])
    expect(riwayat[1]!.keluhan).toBe('Nyeri dada (uji sintetis)')
    expect((await daftarKunjunganIgd()).data[0]!.triase).toEqual({ kategori: 'merah', ditriase_pada: jam.toISOString() })
  })

  it('kategori empat warna, instrumen dan skala dari data induk; kunjungan tidak aktif 409', async () => {
    const k = (await buatKunjunganIgd({ pasien_id: PASIEN1, cara_bayar: 'non_jkn' })).data
    expect((await daftarInstrumenTriase()).map((i) => i.kode)).toEqual(['CONTOH-TRIASE'])
    await expect(catatTriase(k.id, { kategori: 'biru' as 'merah' })).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'kategori' }] })
    await expect(catatTriase(k.id, { kategori: 'hijau' })).rejects.toMatchObject({ rincian: [{ kolom: 'instrumen_triase_id' }] })
    await expect(catatTriase(k.id, { kategori: 'hijau', instrumen_triase_id: INSTRUMEN.id, skala: 'C9' })).rejects.toMatchObject({ rincian: [{ kolom: 'skala' }] })
    await expect(catatTriase('00000000-0000-7000-8000-00000000ffff', { kategori: 'hijau' })).rejects.toMatchObject({ status: 404 })

    await batalkanKunjungan(k.id, 'Uji batal triase')
    await expect(catatTriase(k.id, { kategori: 'hijau', instrumen_triase_id: INSTRUMEN.id, skala: 'C3' })).rejects.toMatchObject({ status: 409, kode: 'status_tidak_sesuai' })
  })
})

describe('TriasePage: layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang(komponen: typeof TriasePage | typeof PendaftaranIgdPage = TriasePage) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/igd/triase')
    const wrapper = mount(komponen, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return wrapper
  }
  const tombol = (teks: RegExp) => [...document.querySelectorAll('button')].find((b) => teks.test(b.textContent?.trim() ?? '')) as HTMLButtonElement | undefined
  async function pilihPasien(nama: string) {
    const baris = [...document.querySelectorAll<HTMLElement>('tbody tr')].find((tr) => tr.textContent?.includes(nama))
    expect(baris).toBeDefined()
    baris!.click()
    await tenang()
  }
  async function isiTriase(wrapper: Awaited<ReturnType<typeof pasang>>, kategori: string, skala: string) {
    await wrapper.find(`input[name="kategori-triase"][value="${kategori}"]`).setValue(true)
    // Instrumen terpilih otomatis (satu instrumen aktif); select terakhir = skala.
    const select = wrapper.findAll('select')
    await select[select.length - 1]!.setValue(skala)
    await tenang()
  }

  it('perawat: triase kuning lalu ulang triase merah; riwayat dua baris, terbaru berlabel Berlaku', async () => {
    await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })
    const wrapper = await pasang()
    expect(document.body.textContent).toContain('Belum ada pasien dipilih')
    // Belum ditriase tampil sebagai teks, bukan sel kosong.
    expect(document.querySelector('table')!.textContent).toContain('Belum ditriase')

    await pilihPasien('Contoh Pasien Dua')
    expect(document.body.textContent).toContain('Pasien ini belum ditriase.')
    expect(tombol(/^Simpan triase$/)!.disabled).toBe(true)
    // Kategori berupa pilihan berteks, bukan hanya warna.
    const label = [...document.querySelectorAll('.pilihan-kategori label')].map((l) => l.textContent?.trim())
    expect(label).toEqual(['Merah', 'Kuning', 'Hijau', 'Hitam'])

    majukan(3)
    await isiTriase(wrapper, 'kuning', 'C2')
    expect(tombol(/^Simpan triase$/)!.disabled).toBe(false)
    tombol(/^Simpan triase$/)!.click()
    await tenang()
    expect(daftarToast.value.map((t) => t.pesan).join(' | ')).toMatch(/Triase Kuning tersimpan untuk Contoh Pasien Dua, pukul 10\.03 WIB\./)

    majukan(10)
    await isiTriase(wrapper, 'merah', 'C1')
    tombol(/^Simpan ulang triase$/)!.click()
    await tenang()

    const item = [...document.querySelectorAll('.daftar-riwayat li')]
    expect(item).toHaveLength(2)
    expect(item[0]!.textContent).toContain('Merah')
    expect(item[0]!.textContent).toContain('Berlaku')
    expect(item[0]!.textContent).toContain('Tingkat contoh 1')
    expect(item[1]!.textContent).toContain('Kuning')
    expect(item[1]!.textContent).not.toContain('Berlaku')
    // Daftar pasien memakai triase berlaku dari server.
    const baris = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent?.includes('Contoh Pasien Dua'))!
    expect(baris.textContent).toContain('Merah')
  })

  it('tanpa izin input: riwayat tampil, formulir tidak', async () => {
    const k = (await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data
    await catatTriase(k.id, { kategori: 'hijau', instrumen_triase_id: INSTRUMEN.id, skala: 'C3' })
    aturIzin(['igd.triase.lihat'])
    await pasang()
    await pilihPasien('Contoh Pasien Dua')
    expect(document.querySelector('input[name="kategori-triase"]')).toBeNull()
    expect(tombol(/Simpan/)).toBeUndefined()
    expect(document.querySelector('.daftar-riwayat')!.textContent).toContain('Hijau')
  })

  it('Pendaftaran IGD menampilkan kolom triase berlaku bagi pemegang igd.triase.lihat', async () => {
    const k = (await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data
    await catatTriase(k.id, { kategori: 'hitam', instrumen_triase_id: INSTRUMEN.id, skala: 'C1' })
    aturIzin(['igd.pendaftaran.lihat', 'igd.triase.lihat'])
    await pasang(PendaftaranIgdPage)
    expect([...document.querySelectorAll('thead th')].map((th) => th.textContent?.trim())).toContain('Triase')
    expect(document.querySelector('tbody')!.textContent).toContain('Hitam')

    document.body.innerHTML = ''
    aturIzin(['igd.pendaftaran.lihat'])
    await pasang(PendaftaranIgdPage)
    expect([...document.querySelectorAll('thead th')].map((th) => th.textContent?.trim())).not.toContain('Triase')
  })
})
