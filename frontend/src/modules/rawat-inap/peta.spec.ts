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
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { bacaPetaTempatTidur, tandaiKosongSiap } from './api'
import PetaTempatTidurPage from './pages/PetaTempatTidurPage.vue'
import {
  bolehPindahRuang,
  bolehTandaiKosongSiap,
  gabungPilihanLokasi,
  INTERVAL_PETA,
  kelompokkanPeta,
  kunjunganPindahBasi,
  kurangPindahRuang,
  pesanGalatPindahRuang,
  pesanGalatStatus,
  pilihanTtPindah,
  pindahKelas,
  pilihanBangsal,
  pilihanLantai,
  ringkasStatus,
  saringPeta,
  tandaStatus,
  tandaTambahan,
  teksHariRawat,
  TANPA_LANTAI,
} from './peta'
import { buatServerPermintaanKamar } from './tiruan/serverPermintaanKamar'
import { buatServerPetaTempatTidur, LOKASI_PETA_CONTOH as L } from './tiruan/serverPetaTempatTidur'
import type { PetaTempatTidur, TempatTidurPeta } from './tipe'

/** Tiket 2.27: server tiruan kontrak v0.35.0 (peta TT ber-ETag, ubah status) dan layar Rawat Inap → Peta tempat tidur. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let permintaan: PermintaanTiruan[] = []
const IZIN = ['rawat_inap.peta_tempat_tidur.lihat', 'rawat_inap.peta_tempat_tidur.perbaiki']

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => new Date('2026-11-02T03:00:00Z') })
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

const peta = async (gedung: string | null = null, bangsal: string | null = null) => {
  const r = await bacaPetaTempatTidur({ gedung_id: gedung, bangsal_id: bangsal })
  if (!r.berubah) throw new Error('peta tidak berubah')
  return r.data
}
const idTt = (data: PetaTempatTidur, kode: string) => data.data.find((t) => t.kode === kode)!.id

describe('peta TT: server tiruan kontrak v0.35.0', () => {
  it('urut gedung, lantai, bangsal, kode; saring gedung dan bangsal; TT ditutup tampil tidak_dipakai dan tidak dapat dipilih', async () => {
    const semua = await peta()
    expect(semua.meta).toEqual({ terpotong: false })
    expect(semua.data.map((t) => t.kode)).toEqual(['CTHA-1-101-1', 'CTHA-1-101-2', 'CTHA-1-101-3', 'CTHA-1-101-4', 'CTHG-2-201-1', 'CTHG-2-201-2', 'CTHG-2-201-3', 'CTHG-2-202-1', 'CTHG-2-202-2', 'CTHG-2-202-3'])
    expect(semua.data.find((t) => t.kode === 'CTHA-1-101-3')).toMatchObject({ status: 'tidak_dipakai', ditutup: true, dapat_dipilih: false })
    // v0.37.0: hari rawat hanya pada TT berpasien.
    expect(semua.data.filter((t) => t.hari_rawat !== null).map((t) => [t.kode, t.status, t.hari_rawat])).toEqual([['CTHG-2-201-1', 'terisi', 3], ['CTHG-2-202-1', 'rencana_pulang', 5]])
    expect((await peta(L.gedungRawatInap.id)).data).toHaveLength(6)
    expect((await peta(null, L.anggrek.id)).data.map((t) => t.bangsal.kode)).toEqual(Array(4).fill('CONTOH-ANGGREK'))
    await expect(bacaPetaTempatTidur({ gedung_id: 'bukan-uuid', bangsal_id: null })).rejects.toMatchObject({ status: 422 })
  })

  it('ETag: If-None-Match sama → 304; status berubah → 200 dengan ETag baru', async () => {
    const pertama = await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null })
    const etag = pertama.berubah ? pertama.etag : null
    expect(etag).toMatch(/^"/)
    expect(await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null }, { etag, latar: true })).toEqual({ berubah: false })
    expect(permintaan[permintaan.length - 1]!.header).toMatchObject({ 'if-none-match': etag, 'x-permintaan-latar': '1' })
    await tandaiKosongSiap(idTt(await peta(), 'CTHG-2-201-2'))
    expect((await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null }, { etag, latar: true })).berubah).toBe(true)
  })

  it('ubah status: dibersihkan → kosong siap; status lain 409 transisi; ditutup 409; TT tidak ada 404', async () => {
    const data = await peta()
    const hasil = await tandaiKosongSiap(idTt(data, 'CTHG-2-201-2'))
    expect(hasil).toMatchObject({ kode: 'CTHG-2-201-2', status: 'kosong_siap', dapat_dipilih: true, status_sejak: '2026-11-02T03:00:00.000Z' })
    await expect(tandaiKosongSiap(idTt(data, 'CTHG-2-201-2'))).rejects.toMatchObject({
      status: 409, kode: 'transisi_status_tempat_tidur_tidak_sah', rincian: [{ status_sekarang: 'kosong_siap', status_diminta: 'kosong_siap' }],
    })
    await expect(tandaiKosongSiap(idTt(data, 'CTHA-1-101-3'))).rejects.toMatchObject({ status: 409, kode: 'tempat_tidur_ditutup' })
    await expect(tandaiKosongSiap('00000000-0000-7000-b200-0000000000ff')).rejects.toMatchObject({ status: 404 })
    await expect(klien.post(`/tempat-tidur/${idTt(data, 'CTHA-1-101-2')}/status`, { status: 'terisi' })).rejects.toMatchObject({ status: 422 })
  })
})

describe('peta TT: aturan tampil', () => {
  const tt = (x: Partial<TempatTidurPeta>) => ({ ditutup: false, status: 'kosong_siap', ...x }) as TempatTidurPeta

  it('status selalu berteks dan berikon; TT ditutup tampil "Ditutup"; nilai di luar kontrak netral', () => {
    expect(tandaStatus(tt({ status: 'terisi' }))).toEqual({ label: 'Terisi', jenis: 'info', ikon: 'pengguna' })
    expect(tandaStatus(tt({ status: 'dibersihkan' }))).toEqual({ label: 'Dibersihkan', jenis: 'peringatan', ikon: 'tetes' })
    expect(tandaStatus(tt({ status: 'melewati_rencana_lama_rawat' }))).toEqual({ label: 'Melewati rencana lama rawat', jenis: 'bahaya', ikon: 'peringatan' })
    expect(tandaStatus(tt({ status: 'tidak_dipakai', ditutup: true }))).toEqual({ label: 'Ditutup', jenis: 'netral', ikon: 'gembok' })
    expect(tandaStatus(tt({ status: 'baru' as 'terisi' }))).toEqual({ label: 'Status tidak dikenal', jenis: 'netral', ikon: 'info' })
    expect(teksHariRawat(tt({ hari_rawat: 3 }))).toBe('Hari ke-3')
    expect(teksHariRawat(tt({ hari_rawat: null }))).toBeNull()
    expect(tandaTambahan(tt({ isolasi: true, ekstra: true, aturan_jenis_kelamin: 'perempuan' }))).toEqual(['Isolasi', 'TT ekstra', 'Khusus perempuan'])
    expect(tandaTambahan(tt({ isolasi: false, ekstra: false, aturan_jenis_kelamin: 'campur' }))).toEqual([])
  })

  it('aksi tandai kosong siap hanya untuk TT dibersihkan yang tidak ditutup dan pemegang izin perbaiki', () => {
    expect(bolehTandaiKosongSiap(tt({ status: 'dibersihkan' }), true)).toBe(true)
    expect(bolehTandaiKosongSiap(tt({ status: 'dibersihkan' }), false)).toBe(false)
    expect(bolehTandaiKosongSiap(tt({ status: 'terisi' }), true)).toBe(false)
    expect(bolehTandaiKosongSiap(tt({ status: 'tidak_dipakai', ditutup: true }), true)).toBe(false)
  })

  it('kelompok, saring, ringkasan, dan pilihan lokasi dari data server', async () => {
    const { data } = await peta()
    const kelompok = kelompokkanPeta(data)
    expect(kelompok.map((g) => g.nama)).toEqual(['Gedung Anggrek (contoh)', 'Gedung Rawat Inap (contoh)'])
    expect(kelompok[1]!.lantai[0]!.bangsal[0]!.kamar.map((k) => [k.nama, k.tt.map((t) => t.kode)])).toEqual([
      ['Kamar 201 (contoh)', ['CTHG-2-201-1', 'CTHG-2-201-2', 'CTHG-2-201-3']],
      ['Kamar 202 (contoh)', ['CTHG-2-202-1', 'CTHG-2-202-2', 'CTHG-2-202-3']],
    ])
    // TT tanpa lantai/gedung tetap masuk kelompok.
    const tanpa = { ...data[0]!, id: 'x', lantai: null, gedung: null }
    expect(kelompokkanPeta([tanpa])[0]).toMatchObject({ nama: 'Tanpa gedung', lantai: [{ kunci: TANPA_LANTAI, nama: 'Tanpa lantai' }] })

    expect(saringPeta(data, { cari: ' cthg-2-202 ' }).map((t) => t.kode)).toEqual(['CTHG-2-202-1', 'CTHG-2-202-2', 'CTHG-2-202-3'])
    expect(saringPeta(data, { status: 'ditutup' }).map((t) => t.kode)).toEqual(['CTHA-1-101-3'])
    expect(saringPeta(data, { status: 'tidak_dipakai' }).map((t) => t.kode)).toEqual(['CTHG-2-202-2'])
    expect(saringPeta(data, { lantai: L.lantai1.id })).toHaveLength(4)
    expect(pilihanLantai(data)).toEqual([
      { nilai: L.lantai1.id, label: 'Lantai 1 (contoh) · Gedung Anggrek (contoh)' },
      { nilai: L.lantai2.id, label: 'Lantai 2 (contoh) · Gedung Rawat Inap (contoh)' },
    ])
    expect(ringkasStatus(data).map((r) => [r.label, r.jumlah])).toEqual([
      ['Terisi', 1], ['Melewati rencana lama rawat', 0], ['Rencana pulang', 1], ['Dipesan', 1], ['Dibersihkan', 2], ['Kosong siap', 3], ['Tidak dipakai', 1], ['Ditutup', 1],
    ])
    const lokasi = gabungPilihanLokasi(gabungPilihanLokasi({ gedung: [], bangsal: [] }, data.slice(0, 2)), data)
    expect(lokasi.gedung.map((g) => g.kode)).toEqual(['CTHA', 'CTHG'])
    expect(lokasi.bangsal.map((b) => [b.kode, b.gedung])).toEqual([['CONTOH-ANGGREK', [L.gedungAnggrek.id]], ['CONTOH-MELATI', [L.gedungRawatInap.id]]])
    expect(pilihanBangsal(lokasi, L.gedungRawatInap.id).map((b) => b.kode)).toEqual(['CONTOH-MELATI'])
    expect(pilihanBangsal(lokasi, '')).toHaveLength(2)
  })

  it('ringkasan menyebut status di luar kontrak hanya bila ada', async () => {
    const { data } = await peta()
    expect(ringkasStatus(data).map((r) => r.label)).not.toContain('Status tidak dikenal')
    const asing = { ...data[0]!, status: 'karantina' as 'terisi' }
    const r = ringkasStatus([...data, asing, asing])
    expect(r[r.length - 1]).toEqual({ kunci: 'tidak_dikenal', label: 'Status tidak dikenal', jumlah: 2 })
    expect(r.reduce((n, x) => n + x.jumlah, 0)).toBe(data.length + 2)
  })

  it('pesan galat ubah status per kode kontrak', () => {
    expect(pesanGalatStatus(new ApiError(409, 'transisi_status_tempat_tidur_tidak_sah', 'x'), 'A-1')).toBe('Status TT A-1 sudah berubah dan bukan lagi dibersihkan. Periksa peta terbaru.')
    expect(pesanGalatStatus(new ApiError(409, 'tempat_tidur_ditutup', 'x'), 'A-1')).toBe('TT A-1 sudah ditutup, statusnya tidak bisa diubah.')
    expect(pesanGalatStatus(new ApiError(403, 'akses_ditolak', 'x'), 'A-1')).toMatch(/tidak punya akses/)
    expect(pesanGalatStatus(new ApiError(404, 'tidak_ditemukan', 'x'), 'A-1')).toMatch(/tidak ditemukan/)
    expect(pesanGalatStatus(new Error('jaringan'), 'A-1')).toBe('Status TT A-1 gagal diubah. Coba lagi.')
  })

  it('pindah ruang: aksi, pilihan tujuan, pindah kelas, isian wajib, pesan galat per kode', async () => {
    expect(bolehPindahRuang(tt({ kunjungan_rawat_inap_id: 'k' }), true)).toBe(true)
    expect(bolehPindahRuang(tt({ kunjungan_rawat_inap_id: 'k' }), false)).toBe(false)
    expect(bolehPindahRuang(tt({}), true)).toBe(false)
    expect(bolehPindahRuang(tt({ kunjungan_rawat_inap_id: 'k', ditutup: true }), true)).toBe(false)
    const { data } = await peta()
    const asal = data.find((t) => t.kode === 'CTHG-2-201-1')!
    expect(pilihanTtPindah(data, asal).map((t) => t.kode)).toEqual(['CTHA-1-101-1', 'CTHG-2-202-3'])
    const kosong = data.find((t) => t.kode === 'CTHG-2-202-3')!
    expect(pilihanTtPindah(data, kosong).map((t) => t.kode)).toEqual(['CTHA-1-101-1'])
    expect(pindahKelas(asal, kosong)).toBe(false)
    expect(pindahKelas(asal, { kelas_rawat: { id: 'lain', kode: 'K2', nama: 'K2' } })).toBe(true)
    expect(kurangPindahRuang({ tempat_tidur_id: '', alasan: 'Alasan cukup', serah_terima: 'Serah cukup' })).toBe('Pilih TT tujuan.')
    expect(kurangPindahRuang({ tempat_tidur_id: 't', alasan: 'abc', serah_terima: 'Serah cukup' })).toMatch(/alasan pindah/)
    expect(kurangPindahRuang({ tempat_tidur_id: 't', alasan: 'Alasan cukup', serah_terima: '  ab ' })).toMatch(/serah terima/)
    expect(kurangPindahRuang({ tempat_tidur_id: 't', alasan: 'Alasan cukup', serah_terima: 'Serah cukup' })).toBeNull()
    expect(pesanGalatPindahRuang(new ApiError(409, 'tempat_tidur_tidak_tersedia', 'x'), 'B-1')).toMatch(/^TT B-1 sudah tidak kosong siap/)
    expect(pesanGalatPindahRuang(new ApiError(409, 'tempat_tidur_ditutup', 'x'), 'B-1')).toMatch(/^TT B-1 sudah ditutup/)
    expect(pesanGalatPindahRuang(new ApiError(409, 'status_tidak_sesuai', 'x'), 'B-1')).toMatch(/sudah tidak menempati TT ini/)
    expect(pesanGalatPindahRuang(new ApiError(422, 'perpindahan_di_luar_matriks', 'x'), 'B-1')).toMatch(/matriks perpindahan/)
    expect(pesanGalatPindahRuang(new ApiError(403, 'izin_praktik_tidak_berlaku', 'x'), 'B-1')).toMatch(/SIP\/SPK/)
    expect(pesanGalatPindahRuang(new ApiError(403, 'akses_ditolak', 'x'), 'B-1')).toMatch(/cakupan bangsal/)
    expect(pesanGalatPindahRuang(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'serah_terima', pesan: 'y' }]), 'B-1')).toBeNull()
    expect(pesanGalatPindahRuang(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'tempat_tidur_id', pesan: 'y' }]), 'B-1')).toMatch(/tidak sah sebagai tujuan/)
    expect(pesanGalatPindahRuang(new Error('jaringan'), 'B-1')).toBe('Pasien gagal dipindah ke TT B-1. Coba lagi.')
    expect(kunjunganPindahBasi(new ApiError(409, 'status_tidak_sesuai', 'x'))).toBe(true)
    expect(kunjunganPindahBasi(new ApiError(404, 'tidak_ditemukan', 'x'))).toBe(true)
    expect(kunjunganPindahBasi(new ApiError(409, 'tempat_tidur_tidak_tersedia', 'x'))).toBe(false)
  })
})

describe('PetaTempatTidurPage: layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/rawat-inap/peta-tempat-tidur')
    const wrapper = mount(PetaTempatTidurPage, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return wrapper
  }
  const kartu = (kode: string) => document.querySelector<HTMLElement>(`[role="group"][aria-label="TT ${kode}"]`)
  const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
  const ubah = async (sel: string, nilai: string) => {
    const el = document.querySelector<HTMLInputElement | HTMLSelectElement>(sel)!
    el.value = nilai
    el.dispatchEvent(new Event(el instanceof HTMLInputElement ? 'input' : 'change'))
    await tenang()
  }
  const kodeTampil = () => [...document.querySelectorAll('.kode')].map((e) => e.textContent?.trim())

  it('berkelompok gedung → lantai → bangsal → kamar; status berteks; ringkasan per status; aksi hanya di TT dibersihkan', async () => {
    await pasang()
    expect([...document.querySelectorAll('h2')].map((h) => h.textContent?.trim())).toEqual(['Filter dan ringkasan', 'Gedung Anggrek (contoh)', 'Gedung Rawat Inap (contoh)'])
    expect([...document.querySelectorAll('h3')].map((h) => h.textContent?.trim())).toEqual(['Lantai 1 (contoh)', 'Lantai 2 (contoh)'])
    expect([...document.querySelectorAll('h4')].map((h) => h.textContent?.trim())).toEqual(['Bangsal Anggrek (contoh)', 'Bangsal Melati (contoh)'])
    expect(kartu('CTHG-2-201-1')!.textContent).toContain('Terisi')
    expect(kartu('CTHG-2-202-1')!.textContent).toContain('Rencana pulang')
    expect(kartu('CTHA-1-101-1')!.textContent).toContain('Kelas contoh · Isolasi · Khusus laki-laki')
    expect(kartu('CTHA-1-101-3')!.textContent).toContain('Ditutup')
    expect(kartu('CTHA-1-101-3')!.querySelector('button')).toBeNull()
    // v0.36.0: penutupan terjadwal tetap kosong siap, tetapi berketerangan tidak bisa dialokasikan.
    expect(kartu('CTHA-1-101-4')!.textContent).toContain('Kosong siap')
    expect(kartu('CTHA-1-101-4')!.textContent).toContain('Dijadwalkan tutup, tidak bisa dialokasikan')
    expect(kartu('CTHG-2-202-3')!.textContent).not.toContain('Dijadwalkan tutup')
    expect(document.querySelector('[aria-label="Jumlah TT per status"]')!.textContent).toMatch(/Dibersihkan\s*2/)
    const beraksi = [...document.querySelectorAll('[role="group"]')].filter((g) => tombol('Tandai kosong siap', g)).map((g) => g.getAttribute('aria-label'))
    expect(beraksi).toEqual(['TT CTHA-1-101-2', 'TT CTHG-2-201-2'])
    expect(tombol('Tandai kosong siap', kartu('CTHG-2-201-2')!)!.getAttribute('aria-label')).toBe('Tandai kosong siap CTHG-2-201-2')
  })

  it('cari kode TT, filter status dan lantai di layar; gedung dan bangsal dikirim ke API', async () => {
    await pasang()
    await ubah('#cari-kode-tt', '202-3')
    expect(kodeTampil()).toEqual(['CTHG-2-202-3'])
    await ubah('#cari-kode-tt', 'TIDAK-ADA')
    expect(document.body.textContent).toContain('Tidak ada TT yang cocok')
    tombol('Hapus filter')!.click()
    await tenang()
    expect(kodeTampil()).toHaveLength(10)
    await ubah('#filter-status-peta', 'dibersihkan')
    expect(kodeTampil()).toEqual(['CTHA-1-101-2', 'CTHG-2-201-2'])
    await ubah('#filter-status-peta', '')
    await ubah('#filter-lantai-peta', L.lantai2.id)
    expect(kodeTampil()).toHaveLength(6)
    // Ringkasan mengikuti lantai.
    expect(document.querySelector('[aria-label="Jumlah TT per status"]')!.textContent).toMatch(/Dibersihkan\s*1/)

    await ubah('#filter-gedung-peta', L.gedungAnggrek.id)
    const terakhir = () => permintaan.filter((p) => p.jalur === '/peta-tempat-tidur').slice(-1)[0]!
    expect(terakhir().kueri.get('gedung_id')).toBe(L.gedungAnggrek.id)
    // Lantai dikosongkan saat gedung berganti; pilihan gedung lain tetap ada.
    expect(document.querySelector<HTMLSelectElement>('#filter-lantai-peta')!.value).toBe('')
    expect(kodeTampil()).toEqual(['CTHA-1-101-1', 'CTHA-1-101-2', 'CTHA-1-101-3', 'CTHA-1-101-4'])
    expect([...document.querySelector<HTMLSelectElement>('#filter-gedung-peta')!.options].map((o) => o.textContent?.trim())).toEqual(['Semua gedung', 'Gedung Anggrek (contoh)', 'Gedung Rawat Inap (contoh)'])
    // Bangsal hanya milik gedung terpilih.
    const opsiBangsal = () => [...document.querySelector<HTMLSelectElement>('#filter-bangsal-peta')!.options].map((o) => o.textContent?.trim())
    expect(opsiBangsal()).toEqual(['Semua bangsal', 'Bangsal Anggrek (contoh)'])
    await ubah('#filter-bangsal-peta', L.anggrek.id)
    expect(terakhir().kueri.get('bangsal_id')).toBe(L.anggrek.id)
    // Gedung berganti: bangsal yang bukan miliknya dikosongkan dan tidak dikirim.
    const sebelum = permintaan.length
    await ubah('#filter-gedung-peta', L.gedungRawatInap.id)
    expect(document.querySelector<HTMLSelectElement>('#filter-bangsal-peta')!.value).toBe('')
    expect(opsiBangsal()).toEqual(['Semua bangsal', 'Bangsal Melati (contoh)'])
    const baru = permintaan.slice(sebelum).filter((p) => p.jalur === '/peta-tempat-tidur')
    expect(baru.map((p) => [p.kueri.get('gedung_id'), p.kueri.get('bangsal_id')])).toEqual([[L.gedungRawatInap.id, null]])
    expect(kodeTampil()).toHaveLength(6)
  })

  it('TT hilang sesudah aksi (404): pesan galat, fokus ke judul kartu filter', async () => {
    await pasang()
    tombol('Tandai kosong siap', kartu('CTHG-2-201-2')!)!.click()
    await tenang()
    const daftar = gabungan.petaTempatTidur.daftar()
    daftar.splice(daftar.findIndex((t) => t.kode === 'CTHG-2-201-2'), 1)
    tombol('Tandai kosong siap', document.querySelector<HTMLElement>('[role="dialog"]')!)!.click()
    await tenang(20)
    expect(daftarToast.value.map((t) => t.pesan)).toContain('TT CTHG-2-201-2 tidak ditemukan. Periksa peta terbaru.')
    expect(kartu('CTHG-2-201-2')).toBeNull()
    expect(document.activeElement?.id).toBe('judul-filter-peta')
  })

  it('tandai kosong siap: konfirmasi, POST, peta dimuat ulang, toast, fokus ke kode TT', async () => {
    await pasang()
    tombol('Tandai kosong siap', kartu('CTHG-2-201-2')!)!.click()
    await tenang()
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog.textContent).toContain('Tandai CTHG-2-201-2 kosong siap?')
    tombol('Tandai kosong siap', dialog)!.click()
    await tenang(20)
    const post = permintaan.find((p) => p.metode.toUpperCase() === 'POST' && p.jalur.endsWith('/status'))!
    expect(post.isi).toEqual({ status: 'kosong_siap' })
    expect(daftarToast.value.map((t) => t.pesan)).toContain('TT CTHG-2-201-2 kosong siap.')
    expect(kartu('CTHG-2-201-2')!.textContent).toContain('Kosong siap')
    expect(kartu('CTHG-2-201-2')!.querySelector('button')).toBeNull()
    expect(document.activeElement?.textContent?.trim()).toBe('CTHG-2-201-2')
  })

  it('409 transisi: pesan jelas lalu peta terbaru tampil tanpa aksi', async () => {
    await pasang()
    tombol('Tandai kosong siap', kartu('CTHG-2-201-2')!)!.click()
    await tenang()
    gabungan.petaTempatTidur.aturStatus('CTHG-2-201-2', 'terisi')
    tombol('Tandai kosong siap', document.querySelector<HTMLElement>('[role="dialog"]')!)!.click()
    await tenang(20)
    expect(daftarToast.value.map((t) => [t.pesan, t.jenis])).toContainEqual(['Status TT CTHG-2-201-2 sudah berubah dan bukan lagi dibersihkan. Periksa peta terbaru.', 'error'])
    expect(kartu('CTHG-2-201-2')!.textContent).toContain('Terisi')
    expect(kartu('CTHG-2-201-2')!.querySelector('button')).toBeNull()
  })

  it('lihat saja: tanpa aksi di TT dibersihkan', async () => {
    aturIzin(['rawat_inap.peta_tempat_tidur.lihat'])
    await pasang()
    expect(kartu('CTHG-2-201-2')!.textContent).toContain('Dibersihkan')
    expect(tombol('Tandai kosong siap')).toBeUndefined()
  })

  it('terpotong, kosong, dan error', async () => {
    let jawab: ResponsTiruan = { status: 200, header: { ETag: '"a"' }, isi: { data: [], meta: { terpotong: true } } }
    pasangTiruan(klien, { tangani: (r: PermintaanTiruan): ResponsTiruan | null => (r.jalur === '/peta-tempat-tidur' ? jawab : (gabungan.tangani(r) as ResponsTiruan | null)) })
    await pasang()
    expect(document.body.textContent).toContain('Peta hanya memuat 1.000 TT pertama.')
    expect(document.body.textContent).toContain('Belum ada TT yang berlaku di cakupan Anda.')
    document.body.innerHTML = ''
    jawab = { status: 500, isi: { error: { kode: 'galat_server', pesan: 'Server sedang bermasalah.', rincian: [] } } }
    await pasang()
    expect(document.body.textContent).toContain('Peta tempat tidur gagal dimuat')
    expect(tombol('Coba lagi')).toBeDefined()
  })

  describe('pindah ruang (2.29, v0.38.0)', () => {
    let peta: ReturnType<typeof buatServerPetaTempatTidur>
    let izinServer: Set<string>
    beforeEach(() => {
      izinServer = new Set([...IZIN, 'rawat_inap.pindah_ruang.input'])
      peta = buatServerPetaTempatTidur({ boleh: (k) => izinServer.has(k), sekarang: () => new Date('2026-11-02T03:00:00Z') })
      const kamar = buatServerPermintaanKamar({ boleh: (k) => izinServer.has(k), tempatTidur: peta.daftar, sekarang: () => new Date('2026-11-02T03:00:00Z') })
      pasangTiruan(klien, { tangani: (r: PermintaanTiruan) => (permintaan.push(r), kamar.tangani(r) ?? peta.tangani(r) ?? gabungan.tangani(r)) })
      aturIzin([...IZIN, 'rawat_inap.pindah_ruang.input'])
    })
    const dialog = () => document.querySelector<HTMLElement>('[role="dialog"]')
    const isi = async (label: RegExp, nilai: string) => {
      const kolom = [...dialog()!.querySelectorAll('textarea')].find((t) => label.test(document.querySelector(`label[for="${t.id}"]`)?.textContent ?? ''))!
      kolom.value = nilai
      kolom.dispatchEvent(new Event('input'))
      await tenang()
    }
    const pilih = async (kode: string) => {
      ;[...dialog()!.querySelectorAll<HTMLInputElement>('input[type="radio"]')].find((r) => r.closest('label')?.textContent?.includes(kode))!.click()
      await tenang()
    }

    it('server: id kunjungan RI hanya bagi izin pindah ruang; tanpa izin kunci tidak ada', async () => {
      const dengan = (await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null })) as { berubah: true; data: PetaTempatTidur }
      expect(dengan.data.data.filter((t) => 'kunjungan_rawat_inap_id' in t).map((t) => t.kode)).toEqual(['CTHG-2-201-1', 'CTHG-2-202-1'])
      // Identitas ikut dengan syarat yang sama (keputusan pemilik 11 Nov 2026).
      expect(dengan.data.data.filter((t) => 'pasien_rawat_inap' in t).map((t) => [t.kode, t.pasien_rawat_inap!.nama])).toEqual([
        ['CTHG-2-201-1', 'Pasien Contoh Rawat Inap'], ['CTHG-2-202-1', 'Pasien Contoh Rencana Pulang'],
      ])
      izinServer.delete('rawat_inap.pindah_ruang.input')
      const tanpa = (await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null })) as { berubah: true; data: PetaTempatTidur }
      expect(tanpa.data.data.some((t) => 'kunjungan_rawat_inap_id' in t || 'pasien_rawat_inap' in t)).toBe(false)
    })

    it('aksi hanya di TT berpasien yang membawa id; dialog: TT asal, tujuan kosong siap selain asal, alasan dan serah terima wajib; sukses memuat ulang peta', async () => {
      await pasang()
      const beraksi = [...document.querySelectorAll('[role="group"]')].filter((g) => tombol('Pindah ruang', g)).map((g) => g.getAttribute('aria-label'))
      expect(beraksi).toEqual(['TT CTHG-2-201-1', 'TT CTHG-2-202-1'])
      tombol('Pindah ruang', kartu('CTHG-2-201-1')!)!.click()
      await tenang()
      expect(dialog()!.textContent).toContain('Pindah ruang pasien di CTHG-2-201-1')
      expect(dialog()!.textContent).toContain('CTHG-2-201-1 · Bangsal Melati (contoh) · Kelas contoh · hari rawat ke-3')
      // Dua penanda identitas hanya di dialog, tidak di kartu peta.
      expect(dialog()!.textContent).toMatch(/Pasien Contoh Rawat Inap, No\. RM 900\.099, lahir \d/)
      expect([...document.querySelectorAll('.daftar-tt')].map((u) => u.textContent).join(' ')).not.toContain('Pasien Contoh')
      expect(kartu('CTHG-2-201-1')!.textContent).not.toContain('900.099')
      // Tujuan: dapat_dipilih kosong siap; TT dijadwalkan tutup dan TT asal tidak ditawarkan.
      expect([...dialog()!.querySelectorAll('.pilihan .kode')].map((e) => e.textContent?.trim())).toEqual(['CTHA-1-101-1', 'CTHG-2-202-3'])
      const kirim = () => [...dialog()!.querySelectorAll('button')].find((b) => /^Pindahkan/.test(b.textContent?.trim() ?? ''))!
      expect(kirim().disabled).toBe(true)
      expect(dialog()!.textContent).toContain('Pilih TT tujuan.')
      await pilih('CTHG-2-202-3')
      await isi(/Alasan pindah/, 'Butuh kamar dekat nurse station (sintetis)')
      expect(dialog()!.textContent).toContain('Isi serah terima antar-ruang, minimal 5 karakter.')
      await isi(/Serah terima antar-ruang/, 'Stabil, infus jalan (sintetis)')
      expect(kirim().disabled).toBe(false)
      expect(kirim().textContent?.trim()).toBe('Pindahkan ke CTHG-2-202-3')
      kirim().click()
      await tenang(20)
      const post = permintaan.find((p) => p.jalur.endsWith('/pindah-ruang'))!
      expect(post.jalur).toBe(`/kunjungan/${peta.daftar().find((t) => t.kode === 'CTHG-2-202-3')!.kunjungan_rawat_inap_id}/pindah-ruang`)
      expect(post.isi).toEqual({ tempat_tidur_id: peta.daftar().find((t) => t.kode === 'CTHG-2-202-3')!.id, alasan: 'Butuh kamar dekat nurse station (sintetis)', serah_terima: 'Stabil, infus jalan (sintetis)' })
      expect(dialog()).toBeNull()
      expect(daftarToast.value.map((t) => t.pesan)).toContain('Pasien Contoh Rawat Inap (No. RM 900.099) dipindah dari TT CTHG-2-201-1 ke TT CTHG-2-202-3.')
      expect(kartu('CTHG-2-201-1')!.textContent).toContain('Dibersihkan')
      expect(kartu('CTHG-2-202-3')!.textContent).toMatch(/Terisi\s*Hari ke-3/)
      expect(document.activeElement?.textContent?.trim()).toBe('CTHG-2-202-3')
    })

    it('pindah kelas diberi keterangan; 409 TT tujuan dipakai pihak lain: peta dimuat ulang, pesan di dialog', async () => {
      const lain = peta.daftar().find((t) => t.kode === 'CTHA-1-101-1')!
      lain.kelas_rawat = { id: 'kelas-lain', kode: 'K2', nama: 'Kelas dua contoh' }
      await pasang()
      tombol('Pindah ruang', kartu('CTHG-2-201-1')!)!.click()
      await tenang()
      expect(dialog()!.textContent).toMatch(/CTHA-1-101-1.*Kelas dua contoh · pindah kelas/)
      await pilih('CTHA-1-101-1')
      expect(dialog()!.textContent).toContain('Pindah kelas: Kelas contoh ke Kelas dua contoh.')
      await isi(/Alasan pindah/, 'Permintaan keluarga (sintetis)')
      await isi(/Serah terima antar-ruang/, 'Stabil (sintetis) tanpa keluhan')
      peta.aturStatus('CTHA-1-101-1', 'dipesan')
      ;[...dialog()!.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Pindahkan ke CTHA-1-101-1')!.click()
      await tenang(20)
      expect(dialog()!.textContent).toContain('TT CTHA-1-101-1 sudah tidak kosong siap')
      expect([...dialog()!.querySelectorAll('.pilihan .kode')].map((e) => e.textContent?.trim())).toEqual(['CTHG-2-202-3'])
    })

    it('tanpa izin pindah ruang: tidak ada aksi walau server mengirim id', async () => {
      aturIzin(IZIN)
      await pasang()
      expect(tombol('Pindah ruang')).toBeUndefined()
    })
  })

  it('polling tiap interval papan IGD dengan If-None-Match sebagai permintaan latar', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const wrapper = await pasang()
    expect(document.body.textContent).toContain('Diperbarui otomatis tiap 15 detik.')
    const awal = permintaan.length
    vi.advanceTimersByTime(INTERVAL_PETA)
    await tenang()
    const latar = permintaan.slice(awal).filter((p) => p.jalur === '/peta-tempat-tidur')
    expect(latar).toHaveLength(1)
    expect(latar[0]!.header).toMatchObject({ 'x-permintaan-latar': '1', 'if-none-match': expect.stringMatching(/^"/) })
    expect(INTERVAL_PETA).toBe(15_000)

    // Sesudah halaman ditutup, polling berhenti.
    wrapper.unmount()
    const sesudah = permintaan.length
    vi.advanceTimersByTime(INTERVAL_PETA * 3)
    await tenang()
    expect(permintaan.slice(sesudah).filter((p) => p.jalur === '/peta-tempat-tidur')).toHaveLength(0)
  })
})
