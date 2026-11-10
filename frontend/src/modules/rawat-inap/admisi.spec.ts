import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, watch } from 'vue'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import type { PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { tandaPermintaanKamar } from '@/modules/igd/papan'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import {
  bolehAlokasi,
  formatLamaMenunggu,
  hariIniRs,
  perluMuatUlang,
  pesanGalatAlokasi,
  pesanGalatSpri,
  pilihanTtAlokasi,
  tandaPermintaan,
  teksTtDipesan,
} from './admisi'
import { alokasikanTempatTidur, bacaPetaTempatTidur, buatSpri, daftarPermintaanTempatTidur } from './api'
import PanelSpri from './components/PanelSpri.vue'
import AdmisiRawatInapPage from './pages/AdmisiRawatInapPage.vue'
import { dijadwalkanTutup } from './peta'
import { buatServerPermintaanKamar, KELAS_LAIN_CONTOH } from './tiruan/serverPermintaanKamar'
import { LOKASI_PETA_CONTOH as L } from './tiruan/serverPetaTempatTidur'
import type { PermintaanTempatTidur, TempatTidurPeta } from './tipe'

/** Tiket 2.28 FE: server tiruan kontrak v0.36.0 (antrean, alokasi, SPRI), layar Admisi rawat inap, dan panel SPRI. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let permintaan: PermintaanTiruan[] = []
let kamar: ReturnType<typeof buatServerPermintaanKamar>
let izinServer = new Set<string>()
const IZIN = ['pendaftaran.admisi.lihat', 'pendaftaran.admisi.input', 'rawat_inap.peta_tempat_tidur.lihat', 'rawat_jalan.pemeriksaan.sahkan', 'master_data.organisasi.lihat']
const KUNJUNGAN_POLI = '00000000-0000-7000-b228-00000000aaaa'

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => new Date('2026-11-02T03:00:00Z') })
  permintaan = []
  // Izin server tiruan antrean/SPRI mengikuti daftar uji (akun tiruan superadmin tidak memegang izin klinis).
  izinServer = new Set(IZIN)
  kamar = buatServerPermintaanKamar({ boleh: (k) => izinServer.has(k), tempatTidur: gabungan.petaTempatTidur.daftar, sekarang: () => new Date('2026-11-02T03:00:00Z') })
  pasangTiruan(klien, { tangani: (r: PermintaanTiruan) => (permintaan.push(r), kamar.tangani(r) ?? gabungan.tangani(r)) })
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'admisi', mfa_aktif: true })
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

const peta = async () => {
  const r = await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null })
  if (!r.berubah) throw new Error('peta tidak berubah')
  return r.data.data
}
const tt = (data: TempatTidurPeta[], kode: string) => data.find((t) => t.kode === kode)!
const antrean = async () => (await daftarPermintaanTempatTidur(null)).data
const cari = (d: PermintaanTempatTidur[], nama: string) => d.find((p) => p.pasien.nama === nama)!

describe('admisi: server tiruan kontrak v0.36.0', () => {
  it('antrean aktif terlama dulu, lama menunggu dari server, saring status, kursor', async () => {
    const d = await antrean()
    expect(d.map((p) => [p.pasien.nama, p.asal, p.lama_menunggu_menit])).toEqual([
      ['Pasien Contoh IGD', 'igd', 100], ['Pasien Contoh Poli', 'poliklinik', 30], ['Pasien Contoh Kelas Lain', 'poliklinik', 0],
    ])
    expect(d[0]!.kelas_diminta).toBeNull()
    const h1 = await daftarPermintaanTempatTidur(null, null, 2)
    expect(h1.data).toHaveLength(2)
    const h2 = await daftarPermintaanTempatTidur(null, h1.meta.kursor_berikutnya, 2)
    expect(h2.data.map((p) => p.pasien.nama)).toEqual(['Pasien Contoh Kelas Lain'])
    expect(h2.meta.kursor_berikutnya).toBeNull()
    expect((await daftarPermintaanTempatTidur('dialokasikan')).data).toEqual([])
  })

  it('alokasi: TT jadi dipesan di peta, permintaan dialokasikan; serentak ke TT sama → 409; ditutup/terjadwal tutup 409; kelas beda 422', async () => {
    const d = await antrean()
    const igd = cari(d, 'Pasien Contoh IGD')
    const poli = cari(d, 'Pasien Contoh Poli')
    const lain = cari(d, 'Pasien Contoh Kelas Lain')
    const sebelum = await peta()
    const hasil = await alokasikanTempatTidur(igd.id, tt(sebelum, 'CTHG-2-202-3').id)
    expect(hasil).toMatchObject({ status: 'dialokasikan', tempat_tidur: { kode: 'CTHG-2-202-3', bangsal: { nama: L.melati.nama } }, dialokasikan_pada: '2026-11-02T03:00:00.000Z' })
    expect(tt(await peta(), 'CTHG-2-202-3')).toMatchObject({ status: 'dipesan', dapat_dipilih: false })
    await expect(alokasikanTempatTidur(poli.id, tt(sebelum, 'CTHG-2-202-3').id)).rejects.toMatchObject({ status: 409, kode: 'tempat_tidur_tidak_tersedia', rincian: [{ status_sekarang: 'dipesan' }] })
    await expect(alokasikanTempatTidur(igd.id, tt(sebelum, 'CTHA-1-101-1').id)).rejects.toMatchObject({ status: 409, kode: 'permintaan_kamar_tidak_menunggu' })
    await expect(alokasikanTempatTidur(poli.id, tt(sebelum, 'CTHA-1-101-3').id)).rejects.toMatchObject({ status: 409, kode: 'tempat_tidur_ditutup' })
    await expect(alokasikanTempatTidur(poli.id, tt(sebelum, 'CTHA-1-101-4').id)).rejects.toMatchObject({ status: 409, kode: 'tempat_tidur_ditutup' })
    await expect(alokasikanTempatTidur(lain.id, tt(sebelum, 'CTHA-1-101-1').id)).rejects.toMatchObject({ status: 422, kode: 'kelas_tidak_sesuai' })
    await expect(alokasikanTempatTidur('bukan-uuid', tt(sebelum, 'CTHA-1-101-1').id)).rejects.toMatchObject({ status: 404 })
    await expect(alokasikanTempatTidur(poli.id, '00000000-0000-7000-b200-0000000000ff')).rejects.toMatchObject({ status: 422, kode: 'validasi_gagal' })
    expect((await daftarPermintaanTempatTidur('dialokasikan')).data.map((p) => p.pasien.nama)).toEqual(['Pasien Contoh IGD'])
  })

  it('SPRI: 201 permintaan menunggu dengan kelas dan rencana; kedua kali 409 sudah ada; isian kurang 422', async () => {
    const p = await buatSpri(KUNJUNGAN_POLI, { alasan: 'Observasi sintetis', kelas_rawat_id: L.kelas.id, bangsal_id: L.melati.id, tanggal_rencana: '2026-11-02' })
    expect(p).toMatchObject({ status: 'menunggu', asal: 'poliklinik', kelas_diminta: { id: L.kelas.id }, bangsal_pilihan: { id: L.melati.id }, spri: { tanggal_rencana: '2026-11-02', no_surat_bpjs: null } })
    await expect(buatSpri(KUNJUNGAN_POLI, { alasan: 'Observasi sintetis', kelas_rawat_id: L.kelas.id, bangsal_id: null, tanggal_rencana: null })).rejects.toMatchObject({ status: 409, kode: 'permintaan_kamar_sudah_ada' })
    await expect(buatSpri('00000000-0000-7000-b228-00000000bbbb', { alasan: 'x', kelas_rawat_id: '', bangsal_id: null, tanggal_rencana: '2026-11-01' })).rejects.toMatchObject({
      status: 422, rincian: [{ kolom: 'alasan' }, { kolom: 'kelas_rawat_id' }, { kolom: 'tanggal_rencana' }],
    })
  })

  it('tanpa izin: antrean, alokasi, SPRI 403', async () => {
    izinServer = new Set()
    await expect(daftarPermintaanTempatTidur(null)).rejects.toMatchObject({ status: 403 })
    await expect(alokasikanTempatTidur(kamar.daftar()[0]!.id, tt(await peta(), 'CTHA-1-101-1').id)).rejects.toMatchObject({ status: 403 })
    await expect(buatSpri(KUNJUNGAN_POLI, { alasan: 'Observasi sintetis', kelas_rawat_id: L.kelas.id, bangsal_id: null, tanggal_rencana: null })).rejects.toMatchObject({ status: 403 })
  })
})

describe('admisi: aturan tampil', () => {
  it('lama menunggu, status berteks dan berikon, TT dipesan', () => {
    expect(formatLamaMenunggu(0)).toBe('Belum mulai (rencana nanti)')
    expect(formatLamaMenunggu(45)).toBe('45 menit')
    expect(formatLamaMenunggu(120)).toBe('2 jam')
    expect(formatLamaMenunggu(185)).toBe('3 jam 5 menit')
    expect(formatLamaMenunggu(1440)).toBe('1 hari')
    expect(formatLamaMenunggu(1440 * 2 + 250)).toBe('2 hari 4 jam')
    expect(tandaPermintaan({ status: 'menunggu' })).toEqual({ label: 'Menunggu TT', jenis: 'peringatan', ikon: 'jam' })
    expect(tandaPermintaan({ status: 'dialokasikan' })).toEqual({ label: 'TT dipesan', jenis: 'merek', ikon: 'penanda' })
    expect(tandaPermintaan({ status: 'aneh' as 'batal' }).label).toBe('Status tidak dikenal')
    expect(teksTtDipesan({ id: 'x', kode: 'A-1', bangsal: L.melati })).toBe('A-1 · Bangsal Melati (contoh)')
    expect(bolehAlokasi({ status: 'menunggu' }, true)).toBe(true)
    expect(bolehAlokasi({ status: 'menunggu' }, false)).toBe(false)
    expect(bolehAlokasi({ status: 'dialokasikan' }, true)).toBe(false)
    expect(hariIniRs(new Date('2026-11-02T18:00:00Z'))).toBe('2026-11-03')
  })

  it('pilihan TT: hanya dapat_dipilih kosong siap; kelas diminta menyaring; tanpa kelas semua kelas', async () => {
    const data = await peta()
    expect(pilihanTtAlokasi(data, { kelas_diminta: null }).map((t) => t.kode)).toEqual(['CTHA-1-101-1', 'CTHG-2-202-3'])
    expect(pilihanTtAlokasi(data, { kelas_diminta: L.kelas }).map((t) => t.kode)).toEqual(['CTHA-1-101-1', 'CTHG-2-202-3'])
    expect(pilihanTtAlokasi(data, { kelas_diminta: KELAS_LAIN_CONTOH })).toEqual([])
    // Penutupan terjadwal: tetap kosong siap tetapi tidak dapat dipilih.
    expect(dijadwalkanTutup(tt(data, 'CTHA-1-101-4'))).toBe(true)
    expect(dijadwalkanTutup(tt(data, 'CTHA-1-101-1'))).toBe(false)
    expect(dijadwalkanTutup(tt(data, 'CTHA-1-101-3'))).toBe(false)
  })

  it('pesan galat alokasi dan SPRI per kode kontrak', () => {
    expect(pesanGalatAlokasi(new ApiError(409, 'tempat_tidur_tidak_tersedia', 'x'), 'A-1')).toMatch(/^TT A-1 sudah tidak kosong siap/)
    expect(pesanGalatAlokasi(new ApiError(409, 'tempat_tidur_ditutup', 'x'), 'A-1')).toMatch(/ditutup atau dijadwalkan tutup/)
    expect(pesanGalatAlokasi(new ApiError(422, 'kelas_tidak_sesuai', 'x'), 'A-1')).toMatch(/^Kelas TT A-1 berbeda dengan kelas yang diminta/)
    expect(pesanGalatAlokasi(new ApiError(409, 'permintaan_kamar_tidak_menunggu', 'x'), 'A-1')).toMatch(/sudah tidak menunggu/)
    expect(pesanGalatAlokasi(new ApiError(403, 'akses_ditolak', 'x'), 'A-1')).toBe('Anda tidak punya akses mengalokasikan TT A-1 (izin atau cakupan bangsal).')
    expect(pesanGalatAlokasi(new Error('jaringan'), 'A-1')).toBe('TT A-1 gagal dialokasikan. Coba lagi.')
    expect(perluMuatUlang(new ApiError(409, 'x', 'x'))).toBe(true)
    expect(perluMuatUlang(new ApiError(422, 'kelas_tidak_sesuai', 'x'))).toBe(false)
    expect(pesanGalatSpri(new ApiError(409, 'permintaan_kamar_sudah_ada', 'x'))).toMatch(/sudah punya permintaan kamar/)
    expect(pesanGalatSpri(new ApiError(403, 'izin_praktik_tidak_berlaku', 'x'))).toMatch(/SIP\/SPK/)
    expect(pesanGalatSpri(new ApiError(403, 'akses_ditolak', 'x'))).toMatch(/izin memberi SPRI/)
    expect(pesanGalatSpri(new ApiError(422, 'perpindahan_di_luar_matriks', 'x'))).toMatch(/matriks perpindahan/)
    expect(pesanGalatSpri(new ApiError(409, 'status_tidak_sesuai', 'x'))).toMatch(/sedang diperiksa/)
    expect(pesanGalatSpri(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'alasan', pesan: 'y' }]))).toBeNull()
  })

  it('papan IGD: permintaan kamar berteks dan berikon, TT dipesan menyebut kode dan bangsal', () => {
    expect(tandaPermintaanKamar({ permintaan_kamar: null })).toBeNull()
    expect(tandaPermintaanKamar({})).toBeNull()
    expect(tandaPermintaanKamar({ permintaan_kamar: { id: 'p', status: 'menunggu', tempat_tidur: null } })).toEqual({ label: 'Menunggu alokasi TT', jenis: 'info', ikon: 'jam', rincian: null })
    expect(tandaPermintaanKamar({ permintaan_kamar: { id: 'p', status: 'dialokasikan', tempat_tidur: { id: 't', kode: 'A-1', bangsal: L.melati } } })).toEqual({
      label: 'TT dipesan', jenis: 'ok', ikon: 'penanda', rincian: 'A-1 · Bangsal Melati (contoh)',
    })
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
const baris = (nama: string) => [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent?.includes(nama)) as HTMLElement | undefined
const dialog = () => document.querySelector<HTMLElement>('[role="dialog"]')

describe('AdmisiRawatInapPage: layar', () => {
  async function pasang() {
    const { router, global } = plugin()
    await router.push('/pendaftaran/admisi')
    const w = mount(AdmisiRawatInapPage, { attachTo: document.body, global })
    await tenang()
    return w
  }
  const pilihTt = async (kode: string) => {
    const radio = [...dialog()!.querySelectorAll<HTMLInputElement>('input[type="radio"]')].find((r) => r.closest('label')?.textContent?.includes(kode))!
    radio.click()
    await tenang()
  }
  const kodePilihan = () => [...dialog()!.querySelectorAll('.pilihan .kode')].map((e) => e.textContent?.trim())

  it('antrean berteks: asal, kelas, lama menunggu, status; aksi hanya untuk menunggu', async () => {
    await pasang()
    expect(baris('Pasien Contoh IGD')!.textContent).toMatch(/IGD.*Belum ditentukan.*1 jam 40 menit.*Menunggu TT/)
    expect(baris('Pasien Contoh Poli')!.textContent).toContain('Bangsal pilihan: Bangsal Melati (contoh)')
    expect(baris('Pasien Contoh Kelas Lain')!.textContent).toContain('Belum mulai (rencana nanti)')
    expect(tombol('Alokasikan TT', baris('Pasien Contoh IGD'))!.getAttribute('aria-label')).toBe('Alokasikan TT untuk Pasien Contoh IGD IGD-001')
  })

  it('alokasi: pilih TT kosong siap, simpan, antrean dan peta dimuat ulang, toast, TT dipesan tampil', async () => {
    await pasang()
    tombol('Alokasikan TT', baris('Pasien Contoh Poli'))!.click()
    await tenang()
    expect(dialog()!.textContent).toContain('Alokasikan TT untuk Pasien Contoh Poli')
    expect(dialog()!.textContent).toContain('Kelas diminta Kelas contoh')
    // TT dijadwalkan tutup (CTHA-1-101-4) dan TT ditutup tidak ditawarkan.
    expect(kodePilihan()).toEqual(['CTHA-1-101-1', 'CTHG-2-202-3'])
    await pilihTt('CTHG-2-202-3')
    tombol('Alokasikan CTHG-2-202-3', dialog()!)!.click()
    await tenang(20)
    const post = permintaan.find((p) => p.metode.toUpperCase() === 'POST' && p.jalur.endsWith('/alokasi'))!
    expect(post.isi).toEqual({ tempat_tidur_id: tt(gabungan.petaTempatTidur.daftar(), 'CTHG-2-202-3').id })
    expect(daftarToast.value.map((t) => t.pesan)).toContain('TT CTHG-2-202-3 dipesan untuk Pasien Contoh Poli.')
    expect(dialog()).toBeNull()
    expect(baris('Pasien Contoh Poli')!.textContent).toMatch(/TT dipesan\s*CTHG-2-202-3 · Bangsal Melati \(contoh\)/)
    expect(tombol('Alokasikan TT', baris('Pasien Contoh Poli'))).toBeUndefined()
    expect(document.activeElement?.textContent?.trim()).toBe('Pasien Contoh Poli')
  })

  it('409 TT sudah dipesan pihak lain: pilihan dimuat ulang dulu, lalu pesan jelas di dialog', async () => {
    await pasang()
    tombol('Alokasikan TT', baris('Pasien Contoh IGD'))!.click()
    await tenang()
    await pilihTt('CTHG-2-202-3')
    gabungan.petaTempatTidur.aturStatus('CTHG-2-202-3', 'dipesan')
    const sebelum = permintaan.length
    tombol('Alokasikan CTHG-2-202-3', dialog()!)!.click()
    await tenang(20)
    const sesudah = permintaan.slice(sebelum).map((p) => p.jalur)
    // Urutan: POST alokasi (409), lalu antrean dan peta dimuat ulang; pesan baru tampil sesudahnya.
    const iPost = sesudah.findIndex((j) => j.endsWith('/alokasi'))
    expect(iPost).toBe(0)
    expect(sesudah.indexOf('/peta-tempat-tidur')).toBeGreaterThan(iPost)
    expect(sesudah.indexOf('/permintaan-tempat-tidur')).toBeGreaterThan(iPost)
    expect(dialog()!.textContent).toContain('TT CTHG-2-202-3 sudah tidak kosong siap (mungkin baru dipesan petugas lain).')
    expect(kodePilihan()).toEqual(['CTHA-1-101-1'])
    expect(tombol('Alokasikan TT', dialog()!)!.disabled).toBe(true)
  })

  for (const [kasus, ubahServer, harap] of [
    ['409 permintaan sudah tidak menunggu', () => (kamar.daftar().find((p) => p.pasien.nama === 'Pasien Contoh IGD')!.status = 'dialokasikan'), 'Permintaan ini sudah tidak menunggu'],
    ['404 permintaan tidak ditemukan', () => kamar.daftar().splice(kamar.daftar().findIndex((p) => p.pasien.nama === 'Pasien Contoh IGD'), 1), 'Permintaan kamar tidak ditemukan.'],
  ] as const) {
    it(`${kasus}: antrean dimuat ulang dulu, dialog tutup, lalu pesan`, async () => {
      await pasang()
      tombol('Alokasikan TT', baris('Pasien Contoh IGD'))!.click()
      await tenang()
      await pilihTt('CTHG-2-202-3')
      ubahServer()
      // Potret permintaan saat pesan muncul: muat ulang antrean harus sudah terjadi.
      let saatPesan: string[] = []
      const henti = watch(() => daftarToast.value.length, () => (saatPesan = permintaan.map((p) => p.jalur)), { flush: 'sync' })
      const sebelum = permintaan.length
      tombol('Alokasikan CTHG-2-202-3', dialog()!)!.click()
      await tenang(20)
      henti()
      expect(daftarToast.value.map((t) => [t.jenis, t.pesan.startsWith(harap)])).toContainEqual(['error', true])
      expect(saatPesan.slice(sebelum)).toContain('/permintaan-tempat-tidur')
      expect(dialog()).toBeNull()
      // TT tidak berubah: tetap kosong siap di peta.
      expect(tt(gabungan.petaTempatTidur.daftar(), 'CTHG-2-202-3').status).toBe('kosong_siap')
    })
  }

  it('tanpa izin lihat: tidak ada permintaan ke /permintaan-tempat-tidur', async () => {
    aturIzin([])
    await pasang()
    expect(permintaan.filter((p) => p.jalur === '/permintaan-tempat-tidur')).toEqual([])
  })

  it('peta terpotong: dialog memperingatkan daftar TT bisa tidak lengkap', async () => {
    pasangTiruan(klien, {
      tangani: (r: PermintaanTiruan) => {
        permintaan.push(r)
        const jawab = kamar.tangani(r) ?? gabungan.tangani(r)
        if (r.jalur === '/peta-tempat-tidur' && jawab && !(jawab instanceof Promise) && jawab.status === 200) {
          return { ...jawab, isi: { ...(jawab.isi as object), meta: { terpotong: true } } }
        }
        return jawab
      },
    })
    await pasang()
    tombol('Alokasikan TT', baris('Pasien Contoh IGD'))!.click()
    await tenang()
    expect(dialog()!.textContent).toContain('Peta memuat 1.000 TT pertama saja')
    expect(kodePilihan()).toEqual(['CTHA-1-101-1', 'CTHG-2-202-3'])
  })

  it('kelas tanpa TT kosong siap: pesan, tanpa pilihan', async () => {
    await pasang()
    tombol('Alokasikan TT', baris('Pasien Contoh Kelas Lain'))!.click()
    await tenang()
    expect(dialog()!.textContent).toContain('Belum ada TT kosong siap untuk Kelas lain contoh di cakupan Anda.')
  })

  it('lihat saja: tanpa kolom aksi; tanpa izin peta: pesan izin di dialog', async () => {
    aturIzin(['pendaftaran.admisi.lihat'])
    await pasang()
    expect(tombol('Alokasikan TT')).toBeUndefined()
    expect([...document.querySelectorAll('th')].map((t) => t.textContent?.trim())).not.toContain('Aksi')
    document.body.innerHTML = ''
    aturIzin(['pendaftaran.admisi.lihat', 'pendaftaran.admisi.input'])
    await pasang()
    tombol('Alokasikan TT', baris('Pasien Contoh IGD'))!.click()
    await tenang()
    expect(dialog()!.textContent).toContain('Butuh izin melihat Peta tempat tidur')
  })
})

describe('PanelSpri: SPRI dari pemeriksaan poli', () => {
  async function pasang() {
    const { global } = plugin()
    const w = mount(PanelSpri, { attachTo: document.body, global, props: { kunjunganId: KUNJUNGAN_POLI, namaPasien: 'Pasien Contoh SPRI' } })
    await tenang()
    return w
  }
  const ubah = async (el: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement, nilai: string) => {
    el.value = nilai
    el.dispatchEvent(new Event(el instanceof HTMLSelectElement ? 'change' : 'input'))
    await tenang()
  }

  it('buat SPRI: fokus pindah ke pilihan kelas walau kelas masih dimuat', async () => {
    await pasang()
    tombol('Buat SPRI')!.click()
    await nextTick()
    await nextTick()
    expect(document.activeElement?.tagName).toBe('SELECT')
    expect(document.activeElement?.textContent).toContain('Memuat kelas')
    await tenang()
    expect(document.activeElement?.tagName).toBe('SELECT')
  })

  it('buat SPRI: isian kelas, tanggal, alasan; simpan; ringkasan SPRI tampil', async () => {
    await pasang()
    tombol('Buat SPRI')!.click()
    await tenang()
    const simpanBtn = () => tombol('Simpan SPRI')!
    expect(simpanBtn().disabled).toBe(true)
    const [kelas] = document.querySelectorAll<HTMLSelectElement>('select')
    const opsiKelas = [...kelas!.options].map((o) => o.textContent?.trim())
    expect(opsiKelas.length).toBeGreaterThan(1)
    await ubah(kelas!, kelas!.options[1]!.value)
    await ubah(document.querySelector('textarea')!, 'Observasi sintetis rawat inap')
    expect(document.querySelector<HTMLInputElement>('input[type="date"]')!.value).toBe(hariIniRs())
    expect(simpanBtn().disabled).toBe(false)
    simpanBtn().click()
    await tenang(20)
    const post = permintaan.find((p) => p.jalur.endsWith('/spri'))!
    expect(post.isi).toMatchObject({ alasan: 'Observasi sintetis rawat inap', kelas_rawat_id: kelas!.options[1]!.value, bangsal_id: null, tanggal_rencana: hariIniRs() })
    const ringkas = document.querySelector('[role="status"]')!
    expect(ringkas.textContent).toMatch(/SPRI tersimpan pukul 10\.00 WIB/)
    expect(ringkas.textContent).toContain('Menunggu TT')
    expect(ringkas.textContent).toContain('Belum ada (bridging BPJS menyusul)')
    expect(daftarToast.value.map((t) => t.pesan)).toContain('SPRI Pasien Contoh SPRI tersimpan. Permintaan kamar masuk antrean admisi.')
  })

  it('409 sudah ada permintaan: pesan jelas, formulir tetap', async () => {
    await buatSpri(KUNJUNGAN_POLI, { alasan: 'Observasi sintetis', kelas_rawat_id: L.kelas.id, bangsal_id: null, tanggal_rencana: null })
    await pasang()
    tombol('Buat SPRI')!.click()
    await tenang()
    const kelas = document.querySelector<HTMLSelectElement>('select')!
    await ubah(kelas, kelas.options[1]!.value)
    await ubah(document.querySelector('textarea')!, 'Observasi sintetis rawat inap')
    tombol('Simpan SPRI')!.click()
    await tenang(20)
    expect(document.body.textContent).toContain('Kunjungan ini sudah punya permintaan kamar yang masih berjalan.')
    expect(tombol('Simpan SPRI')).toBeDefined()
  })
})
