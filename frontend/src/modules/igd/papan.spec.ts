import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import type { PermintaanTiruan, ResponsTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { AREA_IGD_CONTOH } from '@/modules/papan-igd/tiruan/serverPapanIgd'
import { ambilAturanPapanIgd, daftarAreaIgd, etagArea, ubahAreaIgd, ubahAturanPapanIgd } from '@/modules/papan-igd/api'
import { buatKunjunganIgd, catatDisposisi, catatTriase } from '@/modules/pendaftaran/apiKunjungan'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { bacaPapanIgd, tempatkanAreaIgd } from './apiPapan'
import PapanIgdPage from './pages/PapanIgdPage.vue'
import { formatMenit, INTERVAL_PAPAN, pesanGalatTempatkan, pilihanArea, tandaLamaIgd, tandaTanggap, teksDisposisiMenunggu, uraianAturan, waktuTiba } from './papan'
import type { BarisPapanIgd, PapanIgd } from './tipePapan'

/** Tiket 2.25: server tiruan kontrak v0.34.0 (papan IGD ber-ETag, tempatkan area) dan layar IGD → Papan pasien IGD. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let jam = new Date('2026-11-02T03:00:00Z')
const IZIN = ['igd.pendaftaran.lihat', 'igd.pendaftaran.input', 'igd.triase.lihat', 'igd.triase.input', 'igd.disposisi.lihat', 'igd.disposisi.input', 'igd.papan.lihat', 'igd.papan.input', 'master_data.papan_igd.lihat', 'master_data.papan_igd.perbaiki']
const [RESUS, OBS] = AREA_IGD_CONTOH
let permintaan: PermintaanTiruan[] = []

beforeEach(() => {
  jam = new Date('2026-11-02T03:00:00Z')
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => jam })
  permintaan = []
  pasangTiruan(klien, { tangani: (r: PermintaanTiruan) => (permintaan.push(r), gabungan.tangani(r)) })
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'perawat', mfa_aktif: true })
  aturIzin(IZIN)
})
afterEach(() => {
  vi.useRealTimers()
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

const maju = (menit: number) => (jam = new Date(jam.getTime() + menit * 60_000))
const igd = async (pasien: 'satu' | 'dua' | 'tiga', keluhan?: string) => (await buatKunjunganIgd({ pasien_id: gabungan.pasien.contoh[pasien].id, cara_bayar: 'non_jkn', keluhan })).data
const papan = async (areaId: string | null = null) => {
  const r = await bacaPapanIgd(areaId)
  if (!r.berubah) throw new Error('papan tidak berubah')
  return r.data
}

describe('papan IGD: server tiruan kontrak v0.34.0', () => {
  it('urut belum ditriase, lalu merah, kuning, hijau, lalu jam tiba; keluhan triase menggantikan keluhan registrasi', async () => {
    const satu = await igd('satu', 'Keluhan registrasi')
    maju(1)
    const dua = await igd('dua')
    maju(1)
    const tiga = await igd('tiga')
    await catatTriase(satu.id, { kategori: 'hijau', instrumen_triase_id: '00000000-0000-7000-a000-000000000001', skala: 'C3', keluhan: 'Keluhan triase' })
    await catatTriase(tiga.id, { kategori: 'merah', instrumen_triase_id: '00000000-0000-7000-a000-000000000001', skala: 'C1' })
    const isi = await papan()
    expect(isi.data.map((b) => b.kunjungan_id)).toEqual([dua.id, tiga.id, satu.id])
    expect(isi.data.map((b) => b.triase?.kategori ?? null)).toEqual([null, 'merah', 'hijau'])
    expect(isi.data[2]!.keluhan).toBe('Keluhan triase')
    expect(isi.meta).toMatchObject({ terpotong: false, aturan: { batas_tanggap_menit: 5, batas_lama_igd_menit: 360, peringatan_lama_igd_menit: null } })
    expect(isi.meta.area.map((a) => a.kode)).toEqual(['CONTOH-RESUSITASI', 'CONTOH-OBSERVASI', 'CONTOH-HIJAU'])
  })

  it('tanggap: menunggu sampai tepat batas, lalu lewat batas; sesudah asesmen dokter tercapai atau terlambat (menit dibulatkan ke atas)', async () => {
    const a = await igd('satu')
    const b = await igd('dua')
    maju(5)
    let isi = await papan()
    expect(isi.data.map((x) => x.tanggap.tanda)).toEqual(['menunggu', 'menunggu'])
    gabungan.kunjungan.tandaiAsesmenDokter(a.id)
    jam = new Date(jam.getTime() + 1_000)
    isi = await papan()
    const cari = (id: string) => isi.data.find((x) => x.kunjungan_id === id)!
    expect(cari(a.id).tanggap).toEqual({ asesmen_dokter_pada: '2026-11-02T03:05:00.000Z', menit: 5, menunggu_asesmen: false, tanda: 'tercapai' })
    expect(cari(b.id).tanggap).toMatchObject({ menit: null, menunggu_asesmen: true, tanda: 'lewat_batas' })
    gabungan.kunjungan.tandaiAsesmenDokter(b.id)
    isi = await papan()
    expect(cari(b.id).tanggap).toMatchObject({ menit: 6, tanda: 'terlambat' })
  })

  it('tanda 6 jam: ambang kosong tidak pernah mendekati; ambang 60 menit mendekati sejak 5 jam; lebih sesudah 6 jam', async () => {
    await igd('satu')
    maju(330)
    expect((await papan()).data[0]).toMatchObject({ lama_di_igd_menit: 330, tanda_lama_igd: 'aman', lebih_6_jam: false })
    const aturan = await ambilAturanPapanIgd()
    await ubahAturanPapanIgd(aturan.etag, { peringatan_lama_igd_menit: 60, dasar_perubahan: 'SK contoh' })
    expect((await papan()).data[0]!.tanda_lama_igd).toBe('mendekati_6_jam')
    jam = new Date(jam.getTime() + 30 * 60_000 + 1_000)
    expect((await papan()).data[0]).toMatchObject({ lama_di_igd_menit: 361, tanda_lama_igd: 'lebih_6_jam', lebih_6_jam: true })
  })

  it('ETag: If-None-Match yang sama → 304 tanpa isi; isi berubah (penempatan) → 200 dengan ETag baru', async () => {
    const k = await igd('satu')
    const pertama = await bacaPapanIgd(null)
    expect(pertama.berubah).toBe(true)
    const etag = pertama.berubah ? pertama.etag : null
    expect(etag).toMatch(/^"/)
    expect(await bacaPapanIgd(null, { etag, latar: true })).toEqual({ berubah: false })
    expect(permintaan[permintaan.length - 1]!.header).toMatchObject({ 'if-none-match': etag, 'x-permintaan-latar': '1' })

    const hasil = await tempatkanAreaIgd(k.id, RESUS.id)
    expect(hasil).toMatchObject({ kunjungan_id: k.id, area: { id: RESUS.id, nama: RESUS.nama } })
    const kedua = await bacaPapanIgd(null, { etag, latar: true })
    expect(kedua.berubah).toBe(true)
    if (kedua.berubah) expect(kedua.data.data[0]!.area?.kode).toBe('CONTOH-RESUSITASI')
  })

  it('tempatkan: filter area, area tak aktif 422, kunjungan selesai 409, kunjungan tidak ada 404', async () => {
    const k = await igd('satu')
    const lain = await igd('dua')
    await tempatkanAreaIgd(k.id, OBS.id)
    expect((await papan(OBS.id)).data.map((b) => b.kunjungan_id)).toEqual([k.id])
    expect((await papan(RESUS.id)).data).toEqual([])
    await expect(tempatkanAreaIgd(k.id, '00000000-0000-7000-a100-0000000000ff')).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'area_igd_id' }] })
    await catatDisposisi(lain.id, { tujuan: 'pulang', alasan: 'Membaik (uji sintetis)' })
    await expect(tempatkanAreaIgd(lain.id, OBS.id)).rejects.toMatchObject({ status: 409, kode: 'status_tidak_sesuai' })
    await expect(tempatkanAreaIgd('00000000-0000-7000-c000-0000000000ff', OBS.id)).rejects.toMatchObject({ status: 404 })
  })

  it('disposisi menunggu kamar tampil dengan sejak', async () => {
    const k = await igd('satu')
    maju(10)
    await catatDisposisi(k.id, { tujuan: 'rawat_inap', alasan: 'Perlu rawat inap (uji)', serah_terima: 'Infus terpasang (uji sintetis)' })
    expect((await papan()).data[0]!.disposisi_menunggu).toEqual({ status: 'menunggu_kamar', sejak: '2026-11-02T03:10:00.000Z' })
  })
})

describe('papan IGD: aturan tampil', () => {
  const baris = (x: Partial<BarisPapanIgd>) => x as BarisPapanIgd
  it('tanda tanggap per keadaan, selalu dengan teks dan ikon', () => {
    const t = (tanda: BarisPapanIgd['tanggap']['tanda'], menit: number | null = null) => tandaTanggap(baris({ tanggap: { tanda, menit, asesmen_dokter_pada: null, menunggu_asesmen: menit === null } }))
    expect(t('menunggu')).toEqual({ label: 'Menunggu dokter', jenis: 'info', ikon: 'jam' })
    expect(t('lewat_batas')).toEqual({ label: 'Lewat batas tanggap', jenis: 'bahaya', ikon: 'kritis' })
    expect(t('tercapai', 4)).toEqual({ label: 'Tercapai, 4 menit', jenis: 'ok', ikon: 'centang' })
    expect(t('terlambat', 75)).toEqual({ label: 'Terlambat, 1 jam 15 menit', jenis: 'peringatan', ikon: 'peringatan' })
    // Nilai di luar kontrak: lencana netral berteks, tabel tetap tampil.
    expect(t('baru' as 'menunggu')).toEqual({ label: 'Tanda tanggap tidak dikenal', jenis: 'netral', ikon: 'info' })
    expect(tandaLamaIgd(baris({ tanda_lama_igd: 'baru' as 'aman' }))).toEqual({ label: 'Tanda lama tidak dikenal', jenis: 'netral', ikon: 'info' })
  })
  it('jam tiba menyebut tanggal bila bukan hari ini (zona RS)', () => {
    const sekarang = new Date('2026-11-02T05:00:00Z')
    expect(waktuTiba(baris({ tiba_pada: '2026-11-02T03:10:00Z' }), sekarang)).toBe('Pukul 10.10 WIB')
    // 1 Nov 17.30 UTC = 2 Nov 00.30 WIB: hari yang sama menurut zona RS.
    expect(waktuTiba(baris({ tiba_pada: '2026-11-01T17:30:00Z' }), sekarang)).toBe('Pukul 00.30 WIB')
    expect(waktuTiba(baris({ tiba_pada: '2026-11-01T03:10:00Z' }), sekarang)).toBe('1 November 2026, pukul 10.10 WIB')
  })
  it('tanda lama di IGD: aman tanpa lencana, mendekati dan lebih 6 jam berteks', () => {
    expect(tandaLamaIgd(baris({ tanda_lama_igd: 'aman' }))).toBeNull()
    expect(tandaLamaIgd(baris({ tanda_lama_igd: 'mendekati_6_jam' }))?.label).toBe('Mendekati 6 jam')
    expect(tandaLamaIgd(baris({ tanda_lama_igd: 'lebih_6_jam' }))?.label).toBe('Lebih dari 6 jam')
  })
  it('uraian aturan menyebut ambang kosong sebagai tidak dipakai', () => {
    expect(uraianAturan({ batas_tanggap_menit: 5, batas_lama_igd_menit: 360, peringatan_lama_igd_menit: null })).toBe(
      'Batas tanggap 5 menit sejak tiba sampai dokter mulai mengisi asesmen awal. Tanda mendekati 6 jam tidak dipakai.',
    )
    expect(uraianAturan({ batas_tanggap_menit: 5, batas_lama_igd_menit: 360, peringatan_lama_igd_menit: 60 })).toContain('muncul 1 jam sebelum 6 jam')
    expect(formatMenit(0)).toBe('0 menit')
    expect(formatMenit(120)).toBe('2 jam')
  })
  it('disposisi menunggu, pilihan area dengan area nonaktif, dan pesan galat penempatan', () => {
    const sekarang = new Date('2026-11-02T05:00:00Z')
    expect(teksDisposisiMenunggu(baris({ disposisi_menunggu: { status: 'menunggu_kamar', sejak: '2026-11-02T03:10:00Z' } }), sekarang)).toBe('Menunggu kamar sejak pukul 10.10 WIB')
    expect(teksDisposisiMenunggu(baris({ disposisi_menunggu: null }), sekarang)).toBeNull()
    const lama = { id: 'x', kode: 'LAMA', nama: 'Area lama' }
    expect(pilihanArea(baris({ area: lama }), [RESUS]).map((p) => [p.area.kode, p.nonaktif])).toEqual([['CONTOH-RESUSITASI', false], ['LAMA', true]])
    expect(pesanGalatTempatkan(new ApiError(422, 'validasi_gagal', 'x'))).toMatch(/tidak lagi aktif/)
    expect(pesanGalatTempatkan(new ApiError(409, 'status_tidak_sesuai', 'x'))).toMatch(/tidak lagi aktif di IGD/)
  })
})

describe('PapanIgdPage: layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/igd/papan')
    const wrapper = mount(PapanIgdPage, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return wrapper
  }
  const namaBaris = () => [...document.querySelectorAll('tbody tr')].map((tr) => tr.querySelector('b')?.textContent?.trim())
  const barisDari = (nama: string) => [...document.querySelectorAll<HTMLElement>('tbody tr')].find((tr) => tr.textContent?.includes(nama))!
  const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined

  it('urutan baris mengikuti server apa adanya (tidak diurut ulang di klien)', async () => {
    const isi: PapanIgd = {
      data: (['Pasien Hijau Dulu', 'Pasien Merah', 'Pasien Belum'] as const).map((nama, n) => ({
        kunjungan_id: `00000000-0000-7000-c000-00000000000${n}`, nomor: `IGD-20261102-000${n}`, status: 'terdaftar',
        pasien: { id: `p${n}`, no_rm: '00000' + n, nama, jenis_kelamin: 'perempuan', tanggal_lahir: '1990-01-01' },
        keluhan: null, triase: n === 2 ? null : { kategori: n === 0 ? 'hijau' : 'merah', ditriase_pada: '2026-11-02T03:00:00Z' }, area: null,
        tiba_pada: '2026-11-02T03:00:00Z', lama_di_igd_menit: 3, tanda_lama_igd: 'aman', lebih_6_jam: false,
        tanggap: { asesmen_dokter_pada: null, menit: null, menunggu_asesmen: true, tanda: 'menunggu' }, disposisi_menunggu: null,
      })),
      meta: { area: [], aturan: { batas_tanggap_menit: 5, batas_lama_igd_menit: 360, peringatan_lama_igd_menit: null }, terpotong: true, waktu_server: '2026-11-02T03:03:00Z' },
    }
    pasangTiruan(klien, { tangani: (r: PermintaanTiruan): ResponsTiruan | null => (r.jalur === '/igd/papan' ? { status: 200, isi, header: { ETag: '"x"' } } : gabungan.tangani(r) as ResponsTiruan | null) })
    await pasang()
    expect(namaBaris()).toEqual(['Pasien Hijau Dulu', 'Pasien Merah', 'Pasien Belum'])
    expect(barisDari('Pasien Belum').textContent).toContain('Belum ditriase')
    expect(document.body.textContent).toContain('Papan hanya memuat 200 pasien pertama menurut prioritas.')
  })

  it('polling 15 detik: permintaan latar ber-If-None-Match; 304 tidak mengganti data; perubahan server tampil di detak berikutnya', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const k = await igd('satu')
    await pasang()
    expect(barisDari('Contoh Pasien Satu').textContent).toContain('Menunggu dokter')
    expect(barisDari('Contoh Pasien Satu').textContent).toContain('Lama 0 menit')
    const awal = permintaan.length
    vi.advanceTimersByTime(INTERVAL_PAPAN)
    await tenang()
    const latar = permintaan.slice(awal).filter((p) => p.jalur === '/igd/papan')
    expect(latar).toHaveLength(1)
    expect(latar[0]!.header['x-permintaan-latar']).toBe('1')
    expect(latar[0]!.header['if-none-match']).toMatch(/^"/)
    expect(barisDari('Contoh Pasien Satu').textContent).toContain('Menunggu dokter')

    // Jam server maju: lama di IGD dan tanda tanggap berubah dari server, bukan dihitung layar.
    jam = new Date(jam.getTime() + 6 * 60_000)
    gabungan.kunjungan.tandaiAsesmenDokter(k.id, new Date(jam.getTime() - 60_000))
    vi.advanceTimersByTime(INTERVAL_PAPAN)
    await tenang()
    expect(barisDari('Contoh Pasien Satu').textContent).toContain('Lama 6 menit')
    expect(barisDari('Contoh Pasien Satu').textContent).toContain('Tercapai, 5 menit')
  })

  it('tempatkan ke area: pilih lalu Tempatkan; area tampil sesudah dimuat ulang; filter area', async () => {
    await igd('satu')
    await igd('dua')
    await pasang()
    const select = document.querySelector<HTMLSelectElement>('select[id^="area-"]')!
    expect(document.querySelector(`label[for="${select.id}"]`)!.textContent).toMatch(/^Area Contoh Pasien/)
    expect([...select.options].map((o) => o.textContent?.trim())).toEqual(['Belum ditempatkan', 'Resusitasi (contoh)', 'Observasi (contoh)', 'Hijau (contoh)'])
    const nama = barisDari('Contoh Pasien Satu').contains(select) ? 'Contoh Pasien Satu' : 'Contoh Pasien Dua'
    expect(tombol('Tempatkan', barisDari(nama))).toBeUndefined()
    select.value = OBS.id
    select.dispatchEvent(new Event('change'))
    await tenang()
    tombol('Tempatkan', barisDari(nama))!.click()
    await tenang()
    expect(daftarToast.value.map((t) => t.pesan)).toContain(`${nama} ditempatkan di Observasi (contoh).`)
    expect(permintaan.some((p) => p.metode.toUpperCase() === 'PUT' && p.jalur.endsWith('/area-igd'))).toBe(true)
    expect(tombol('Tempatkan', barisDari(nama))).toBeUndefined()
    expect(barisDari(nama).querySelector<HTMLSelectElement>('select')!.value).toBe(OBS.id)
    // Tombol hilang: fokus kembali ke pilihan area baris itu, bukan ke body.
    expect(document.activeElement).toBe(barisDari(nama).querySelector('select'))

    const filter = document.querySelector<HTMLSelectElement>('#filter-area-papan')!
    filter.value = OBS.id
    filter.dispatchEvent(new Event('change'))
    await tenang()
    expect(namaBaris()).toEqual([nama])
    expect(document.querySelector('#judul-papan-igd')!.textContent).toBe('Pasien di Observasi (contoh)')
  })

  it('belum ada area aktif: petunjuk ke data induk, tanpa pilihan area dan filter', async () => {
    await igd('satu')
    for (const a of AREA_IGD_CONTOH) {
      const isi = await daftarAreaIgd('aktif')
      const x = isi.data.find((d) => d.id === a.id)!
      await ubahAreaIgd(x.id, etagArea(x), { status: 'nonaktif', dasar_perubahan: 'SK contoh' })
    }
    await pasang()
    expect(document.body.textContent).toContain('Belum ada area aktif; atur di Master Data → Area & batas papan IGD.')
    expect(document.querySelector('select[id^="area-"]')).toBeNull()
    expect(document.querySelector('#filter-area-papan')).toBeNull()
    expect(barisDari('Contoh Pasien Satu').textContent).toContain('Belum ditempatkan')
  })

  it('lihat saja: area sebagai teks tanpa pilihan penempatan', async () => {
    await igd('satu')
    aturIzin(['igd.papan.lihat'])
    await pasang()
    expect(document.querySelector('select[id^="area-"]')).toBeNull()
    expect(barisDari('Contoh Pasien Satu').textContent).toContain('Belum ditempatkan')
  })
})
