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
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { buatJadwal, buatPengecualian, daftarJadwal, ubahJadwal } from './api'
import { labelHari, nadaSisa } from './jadwal'
import JadwalPraktikPage from './pages/JadwalPraktikPage.vue'

const adapterAsli = klien.defaults.adapter
let server: ReturnType<typeof buatServerGabungan>
const SENIN = '2026-10-19'

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan({ sekarang: () => new Date('2026-10-19T03:00:00Z') }))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  aturIzin(['lihat', 'input', 'perbaiki'].flatMap((a) => [`master_data.jadwal_praktik.${a}`]).concat(['master_data.organisasi.lihat', 'sdm.pegawai.lihat']))
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

const dasar = 'SK-SINT-001'
const isianBaru = () => ({
  pegawai_id: server.sdm.contoh.dokter, poli_id: server.lokasi.contoh.poliAnak.id, ruang_id: null, hari: 2, jam_mulai: '08:00', jam_selesai: '10:00',
  kuota_jkn: 10, kuota_non_jkn: 5, berlaku_dari: '2026-10-19', berlaku_sampai: null, dasar_perubahan: dasar,
})

describe('api jadwal praktik (server tiruan kontrak v0.17.0)', () => {
  it('dengan tanggal: hanya slot hari itu, lengkap dengan sisa kuota', async () => {
    const senin = (await daftarJadwal({ tanggal: SENIN })).data
    expect(senin).toHaveLength(1)
    expect(senin[0].pada_tanggal).toMatchObject({ tanggal: SENIN, tersedia: true, alasan_tidak_tersedia: null })
    expect(senin[0].pada_tanggal!.sisa_jkn).toBe(senin[0].kuota_jkn - senin[0].pada_tanggal!.terisi_jkn)
    expect((await daftarJadwal({})).data[0].pada_tanggal).toBeNull()
  })
  it('cuti membuat slot tanggal itu tidak tersedia dan sisanya nol', async () => {
    await buatPengecualian({ jenis: 'cuti', pegawai_id: server.sdm.contoh.dokter, dari_tanggal: SENIN, sampai_tanggal: SENIN, alasan: 'Cuti SINT' })
    const [j] = (await daftarJadwal({ tanggal: SENIN })).data
    expect(j.pada_tanggal).toMatchObject({ tersedia: false, alasan_tidak_tersedia: 'cuti', sisa_jkn: 0, sisa_non_jkn: 0 })
    expect(j.pengecualian).toHaveLength(1)
  })
  it('dokter tanpa SIP aktif ditolak 422 sip_tidak_aktif', async () => {
    await expect(buatJadwal({ ...isianBaru(), pegawai_id: server.sdm.contoh.bidan })).rejects.toMatchObject({ kode: 'sip_tidak_aktif' })
  })
  it('jadwal tumpang tindih untuk dokter yang sama ditolak; di hari lain diterima', async () => {
    await buatJadwal(isianBaru())
    await expect(buatJadwal({ ...isianBaru(), jam_mulai: '09:00', jam_selesai: '11:00' })).rejects.toMatchObject({ kode: 'jadwal_bertumpuk' })
    await expect(buatJadwal({ ...isianBaru(), hari: 4 })).resolves.toBeTruthy()
  })
  it('ubah wajib versi terbaru (412) dan nonaktif tidak menghapus', async () => {
    const [j] = (await daftarJadwal({})).data
    await expect(ubahJadwal(j.id, '"99"', { kuota_jkn: 30, dasar_perubahan: dasar })).rejects.toMatchObject({ status: 412 })
    const hasil = await ubahJadwal(j.id, `"${j.versi}"`, { status: 'nonaktif', dasar_perubahan: dasar })
    expect(hasil.data.status).toBe('nonaktif')
    expect((await daftarJadwal({ status: 'nonaktif' })).data).toHaveLength(1)
  })
  it('kuota di bawah jumlah terisi ditolak 422', async () => {
    const [j] = (await daftarJadwal({ tanggal: SENIN })).data
    const t = j.pada_tanggal!.terisi_jkn
    expect(t).toBeGreaterThan(0)
    await expect(ubahJadwal(j.id, `"${j.versi}"`, { kuota_jkn: t - 1, dasar_perubahan: dasar })).rejects.toMatchObject({ kode: 'kuota_di_bawah_terisi' })
  })
})

describe('layar jadwal praktik', () => {
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/master-data/jadwal-praktik')
    const klienKueri = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const w = mount(JadwalPraktikPage, { global: { plugins: [router, [VueQueryPlugin, { queryClient: klienKueri }]], directives: { can: vCan } }, attachTo: document.body })
    await flushPromises()
    return w
  }
  it('menampilkan slot hari ini dengan sisa kuota dan tombol kelola', async () => {
    const w = await pasang()
    await w.get('#tanggal-jadwal').setValue(SENIN)
    await flushPromises()
    const tabel = w.get('table').text()
    expect(tabel).toContain('Senin')
    expect(tabel).toContain('08:00–12:00')
    expect(tabel).toContain('Contoh Dokter')
    expect(tabel).toMatch(/\d+ \/ 20/)
    expect(w.text()).toContain('Tambah jadwal')
  })
  it('tanpa tanggal menampilkan semua definisi; tanpa izin ubah tidak ada kolom aksi', async () => {
    aturIzin(['master_data.jadwal_praktik.lihat'])
    const w = await pasang()
    await w.get('#tanggal-jadwal').setValue('')
    await flushPromises()
    expect(w.findAll('tbody tr').length).toBe(3)
    expect(w.text()).not.toContain('Nonaktifkan')
  })
})

describe('pembantu', () => {
  it('label hari dan nada sisa kuota', () => {
    expect(labelHari(1)).toBe('Senin')
    expect(labelHari(7)).toBe('Minggu')
    expect(nadaSisa(0, 10)).toBe('bahaya')
    expect(nadaSisa(2, 10)).toBe('peringatan')
    expect(nadaSisa(8, 10)).toBe('ok')
  })
})
