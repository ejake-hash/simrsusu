import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { buatJadwal, daftarJadwal } from '@/modules/jadwal-praktik/api'
import { daftarPenjamin } from '@/modules/penjamin/api'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { batalkanKunjungan, buatKunjungan, daftarKunjungan } from './apiKunjungan'
import KunjunganPage from './pages/KunjunganPage.vue'

const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
/** Senin 2 Nov 2026 (hari praktik contoh: Senin 08:00–12:00, Rabu 13:00–16:00). */
const SENIN = '2026-11-02'
const RABU = '2026-11-04'
const SEKARANG = () => new Date('2026-11-02T03:00:00Z')
const IZIN = ['pendaftaran.pasien.lihat', 'pendaftaran.pasien.input', 'pendaftaran.pasien.batal']

let POLI: string
let DOKTER: string
let PASIEN1: string
let PASIEN2: string

function pasangServer() {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: SEKARANG })
  pasangTiruan(klien, gabungan)
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.pendaftaran', mfa_aktif: true })
  POLI = gabungan.lokasi.contoh.poliAnak.id
  DOKTER = gabungan.sdm.contoh.dokter
  PASIEN1 = gabungan.pasien.contoh.satu.id
  PASIEN2 = gabungan.pasien.contoh.dua.id
}
beforeEach(() => {
  pasangServer()
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

/** Jadwal poli pada tanggal (dari API, seperti layar). */
const jadwalPada = async (tanggal: string) => (await daftarJadwal({ tanggal, poli_id: POLI, status: 'aktif' }, null, 200)).data
const jadwalSenin = async () => (await jadwalPada(SENIN))[0]!
const penjaminBpjs = async () => (await daftarPenjamin({ status: 'aktif', jenis: 'bpjs' }, null, 200)).data[0]!
async function tambahJadwalSenin(kuotaJkn: number, kuotaNon: number, jamMulai: string, jamSelesai: string) {
  const { data } = await buatJadwal({
    pegawai_id: DOKTER, poli_id: POLI, ruang_id: null, hari: 1, jam_mulai: jamMulai, jam_selesai: jamSelesai,
    kuota_jkn: kuotaJkn, kuota_non_jkn: kuotaNon, berlaku_dari: '2026-01-01', berlaku_sampai: null, dasar_perubahan: 'uji kunjungan',
  })
  return data.id
}

describe('kunjungan poli — server tiruan kontrak v0.20.0', () => {
  it('mendaftarkan kunjungan poliklinik dan menampilkannya di papan', async () => {
    const j = await jadwalSenin()
    const { data } = await buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: SENIN, cara_bayar: 'non_jkn' })
    expect(data.status).toBe('terdaftar')
    expect(data.nomor).toMatch(/^RJ-20261102-\d{4}$/)
    expect(data.poliklinik.id).toBe(POLI)
    expect(data.dokter.id).toBe(DOKTER)
    expect(data.episode.jenis).toBe('rawat_jalan_harian')
    expect(data.penjamin).toBeNull()

    const papan = await daftarKunjungan({ tanggal: SENIN, poli_id: POLI }, null, 200)
    expect(papan.data.map((k) => k.id)).toContain(data.id)
  })

  it('menolak jenis selain poliklinik (422 jenis_belum_didukung)', async () => {
    const j = await jadwalSenin()
    await expect(buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: SENIN, jenis_kunjungan: 'igd', cara_bayar: 'non_jkn' })).rejects.toMatchObject({ kode: 'jenis_belum_didukung' })
  })

  it('JKN wajib penjamin BPJS; penjamin bukan BPJS ditolak', async () => {
    const j = await jadwalSenin()
    await expect(buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: SENIN, cara_bayar: 'jkn' })).rejects.toMatchObject({ kode: 'penjamin_wajib' })

    // Penjamin umum (bukan BPJS) → penjamin_tidak_sesuai.
    const umum = (await daftarPenjamin({ status: 'aktif', jenis: 'umum' }, null, 200)).data[0]!
    await expect(buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: SENIN, cara_bayar: 'jkn', penjamin_id: umum.id })).rejects.toMatchObject({ kode: 'penjamin_tidak_sesuai' })

    const bpjs = await penjaminBpjs()
    const { data } = await buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: SENIN, cara_bayar: 'jkn', penjamin_id: bpjs.id })
    expect(data.cara_bayar).toBe('jkn')
    expect(data.penjamin?.jenis).toBe('bpjs')
  })

  it('menolak tanggal yang bukan hari praktik jadwal (422 tanggal_di_luar_jadwal)', async () => {
    const j = await jadwalSenin()
    await expect(buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: RABU, cara_bayar: 'non_jkn' })).rejects.toMatchObject({ kode: 'tanggal_di_luar_jadwal' })
  })

  it('menolak kunjungan ganda pada jadwal + tanggal yang sama (409)', async () => {
    const j = await jadwalSenin()
    await buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: SENIN, cara_bayar: 'non_jkn' })
    await expect(buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: j.id, tanggal: SENIN, cara_bayar: 'non_jkn' })).rejects.toMatchObject({ kode: 'kunjungan_ganda' })
  })

  it('kuota habis → 409 kuota_habis dengan slot dokter lain yang masih tersedia', async () => {
    const penuh = await tambahJadwalSenin(0, 0, '16:00', '17:00')
    try {
      await buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: penuh, tanggal: SENIN, cara_bayar: 'non_jkn' })
      throw new Error('seharusnya ditolak')
    } catch (e) {
      expect(e).toBeInstanceOf(ApiError)
      const err = e as ApiError
      expect(err.kode).toBe('kuota_habis')
      // Slot terdekat memuat jadwal Senin 08:00 yang masih punya sisa.
      expect(err.rincian.length).toBeGreaterThan(0)
      expect(err.rincian.some((r) => (r as unknown as { jam_mulai: string }).jam_mulai === '08:00')).toBe(true)
    }
  })

  it('episode sama untuk tanggal sama, episode baru untuk tanggal berbeda (7.00.2)', async () => {
    const pagi = await jadwalSenin()
    const siang = await tambahJadwalSenin(5, 5, '13:00', '16:00')
    const k1 = (await buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: pagi.id, tanggal: SENIN, cara_bayar: 'non_jkn' })).data
    const k2 = (await buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: siang, tanggal: SENIN, cara_bayar: 'non_jkn' })).data
    expect(k2.episode.id).toBe(k1.episode.id)

    const rabu = (await jadwalPada(RABU))[0]!
    const k3 = (await buatKunjungan({ pasien_id: PASIEN1, jadwal_praktik_id: rabu.id, tanggal: RABU, cara_bayar: 'non_jkn' })).data
    expect(k3.episode.id).not.toBe(k1.episode.id)
  })

  it('pembatalan menandai status batal, mengembalikan kuota, dan menjaga penelusuran', async () => {
    const j = await jadwalSenin()
    const sisaAwal = (await jadwalPada(SENIN)).find((x) => x.id === j.id)!.pada_tanggal!.sisa_non_jkn
    const { data } = await buatKunjungan({ pasien_id: PASIEN2, jadwal_praktik_id: j.id, tanggal: SENIN, cara_bayar: 'non_jkn' })
    expect((await jadwalPada(SENIN)).find((x) => x.id === j.id)!.pada_tanggal!.sisa_non_jkn).toBe(sisaAwal - 1)

    await expect(batalkanKunjungan(data.id, 'abc')).rejects.toMatchObject({ kode: 'validasi_gagal' })
    const batal = (await batalkanKunjungan(data.id, 'Pasien membatalkan')).data
    expect(batal.status).toBe('batal')
    expect(batal.alasan_batal).toBe('Pasien membatalkan')
    // Kuota kembali.
    expect((await jadwalPada(SENIN)).find((x) => x.id === j.id)!.pada_tanggal!.sisa_non_jkn).toBe(sisaAwal)
    // Tidak muncul di papan aktif, tetap terbaca dengan status=batal.
    expect((await daftarKunjungan({ tanggal: SENIN, poli_id: POLI }, null, 200)).data.map((k) => k.id)).not.toContain(data.id)
    expect((await daftarKunjungan({ tanggal: SENIN, poli_id: POLI, status: 'batal' }, null, 200)).data.map((k) => k.id)).toContain(data.id)
    // Batal dua kali → 409 sudah_batal.
    await expect(batalkanKunjungan(data.id, 'Ulang pembatalan')).rejects.toMatchObject({ kode: 'sudah_batal' })
  })
})

describe('KunjunganPage — layar', () => {
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/pendaftaran/kunjungan')
    const wrapper = mount(KunjunganPage, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return wrapper
  }
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }

  it('menampilkan dokter dari jadwal beserta sisa kuota setelah poli dipilih', async () => {
    const wrapper = await pasang()
    expect(document.body.textContent).toContain('Pendaftaran kunjungan poli')
    // Setel tanggal ke hari praktik tetap (bawaan layar memakai tanggal perangkat sungguhan) dan pilih poli contoh.
    await wrapper.find('input[type="date"]').setValue(SENIN)
    await wrapper.find('select').setValue(POLI)
    await tenang()
    // Jadwal dokter contoh dan kolom sisa kuota muncul.
    expect(document.body.textContent).toContain('Contoh Dokter')
    expect(document.body.textContent).toContain('Sisa JKN')
    // Tombol daftarkan ada tetapi nonaktif sebelum pasien + jadwal dipilih.
    const tombol = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Daftarkan kunjungan') as HTMLButtonElement | undefined
    expect(tombol).toBeTruthy()
    expect(tombol!.disabled).toBe(true)
  })
})
