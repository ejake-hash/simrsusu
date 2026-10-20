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
import { buatUsulan, daftarUsulan, tindakUsulan } from '@/modules/usulan/api'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { daftarTarif, versiTarif } from './api'
import BukuTarifPage from './pages/BukuTarifPage.vue'
import { formatRupiah, jumlahKomponen, periksaHarga, rupiahValid } from './tarif'

const adapterAsli = klien.defaults.adapter
let server: ReturnType<typeof buatServerGabungan>
const HARI_INI = '2026-10-19'
const BESOK = '2026-10-20'

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan({ sekarang: () => new Date('2026-10-19T03:00:00Z') }))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', pegawai: { id: 'p', nama: 'Contoh Superadmin' }, ttd: server.ttd.status(ID_SUPERADMIN) })
  aturIzin(['master_data.tarif.lihat', 'master_data.usulan.lihat', 'master_data.usulan.input', 'master_data.usulan.verifikasi', 'master_data.usulan.setujui', 'master_data.organisasi.lihat'])
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

const kelas = () => [server.lokasi.contoh.k1.id, server.lokasi.contoh.k3.id]
const harga = (sarana: string) => [{ kelas_rawat_id: kelas()[0]!, jasa_sarana: sarana, jasa_pelayanan: '20000', bhp: '5000.50' }]
const isian = (perubahan: object[], berlaku = BESOK) => ({ jenis_data: 'tarif' as const, perubahan: perubahan as never, berlaku_dari: berlaku, alasan: 'Penyesuaian SINT', dasar: 'SK-SINT-002' })

describe('api buku tarif (server tiruan kontrak v0.18.0)', () => {
  it('daftar menampilkan harga per kelas dengan total dari tiga komponen', async () => {
    const [t] = (await daftarTarif({ cari: 'KON-001' })).data
    expect(t!.harga).toHaveLength(2)
    expect(t!.harga[0]).toMatchObject({ jasa_sarana: '100000', jasa_pelayanan: '50000', bhp: '10000.50', total: '160000.50' })
    expect((await daftarTarif({ kelas_rawat_id: kelas()[1] })).data[0]!.harga).toHaveLength(1)
  })
  it('usulan disahkan dengan tanggal besok: hari ini harga lama, besok harga baru', async () => {
    const tindakan = server.tarif.contoh.tindakan
    const lama = (await daftarTarif({ cari: 'TND-001' })).data[0]!
    const u = (await daftarUsulan({ tahap: 'diverifikasi' })).data.find((x) => x.jenis_data === 'tarif')!
    expect(u.dapat.setujui).toBe(true)
    await tindakUsulan(u.id, 'setujui', null)
    const hariIni = (await daftarTarif({ cari: 'TND-001', tanggal: HARI_INI })).data[0]!
    expect(hariIni.harga[0]!.jasa_sarana).toBe(lama.harga[0]!.jasa_sarana)
    expect(hariIni.akan_berlaku).toMatchObject({ berlaku_dari: BESOK })
    const besok = (await daftarTarif({ cari: 'TND-001', tanggal: BESOK })).data[0]!
    expect(besok.harga[0]!.jasa_sarana).toBe('80000')
    expect(besok.berlaku_dari).toBe(BESOK)
    expect((await versiTarif(tindakan.id)).data[0]).toMatchObject({ versi: 3, usulan: { nomor: u.nomor } })
  })
  it('pengusul tidak bisa memverifikasi usulannya sendiri', async () => {
    const u = await buatUsulan(isian([{ objek_jenis: 'tarif', aksi: 'tutup', objek_id: server.tarif.contoh.laboratorium.id, nilai_baru: { alasan: 'Layanan dihentikan' } }]))
    expect(u.jenis_data).toBe('tarif')
    await expect(tindakUsulan(u.id, 'verifikasi', null)).rejects.toMatchObject({ kode: 'pengusul_sendiri' })
  })
  it('tarif baru: kode ganda, kelas ganda, dan harga negatif ditolak; sah muncul sesudah disahkan', async () => {
    const baru = (n: object, h = harga('30000')) => isian([{ objek_jenis: 'tarif', aksi: 'tambah', objek_id: null, nilai_baru: { kode: 'NEW-1', nama: 'Layanan SINT', kelompok: 'Tindakan', harga: h, ...n } }])
    await expect(buatUsulan(baru({ kode: 'KON-001' }))).rejects.toMatchObject({ kode: 'perubahan_tidak_valid' })
    await expect(buatUsulan(baru({}, [...harga('30000'), ...harga('40000')]))).rejects.toMatchObject({ kode: 'perubahan_tidak_valid' })
    await expect(buatUsulan(baru({}, harga('-1')))).rejects.toMatchObject({ kode: 'perubahan_tidak_valid' })
    const u = await buatUsulan(baru({}))
    expect((await daftarTarif({ cari: 'NEW-1', tanggal: BESOK })).data).toHaveLength(0)
    expect(u.nomor).toMatch(/^UDI-/)
  })
  it('perubahan tarif dan ruang dalam satu usulan ditolak jenis_campur', async () => {
    const e = await buatUsulan(
      isian([
        { objek_jenis: 'tarif', aksi: 'tutup', objek_id: server.tarif.contoh.laboratorium.id, nilai_baru: { alasan: 'Layanan dihentikan' } },
        { objek_jenis: 'kamar', aksi: 'ubah', objek_id: server.lokasi.contoh.km101.id, nilai_baru: { kapasitas_maksimum: 2 } },
      ]),
    ).catch((x) => x)
    expect(e.rincian[0]).toMatchObject({ kode: 'jenis_campur' })
  })
})

describe('layar buku tarif', () => {
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/master-data/tarif')
    const w = mount(BukuTarifPage, { global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } }, attachTo: document.body })
    await flushPromises()
    return w
  }
  it('menampilkan tarif dengan total per kelas, tanpa tombol ubah langsung', async () => {
    const w = await pasang()
    const teks = w.get('table').text()
    expect(teks).toContain('KON-001')
    expect(teks).toMatch(/Rp\s?160\.000,5/)
    expect(w.text()).toContain('Usulkan perubahan tarif')
    expect(w.text()).not.toMatch(/Simpan|Ubah harga/)
  })
  it('rincian memuat komponen harga dan riwayat versi', async () => {
    const w = await pasang()
    await w.get('button[aria-label="Rincian tarif TND-001"]').trigger('click')
    await flushPromises()
    await new Promise((r) => setTimeout(r, 20))
    await flushPromises()
    const t = document.body.textContent ?? ''
    expect(t).toContain('Harga berlaku')
    expect(t).toContain('Jasa pelayanan')
    expect(t).toContain('Versi 2')
  })
  it('tanpa izin usulan tidak ada tombol usulkan', async () => {
    aturIzin(['master_data.tarif.lihat'])
    const w = await pasang()
    const tombol = [...w.findAll('button')].find((b) => b.text().includes('Usulkan perubahan tarif'))
    expect(tombol!.element.hidden).toBe(true)
  })
})

describe('pembantu rupiah', () => {
  it('validasi, jumlah tanpa float, dan format', () => {
    expect(rupiahValid('1000.5')).toBe(true)
    expect(['-1', '1.234', '', 'abc', '1,5'].some(rupiahValid)).toBe(false)
    expect(jumlahKomponen({ jasa_sarana: '0.10', jasa_pelayanan: '0.20', bhp: '0' })).toBe('0.30')
    expect(jumlahKomponen({ jasa_sarana: '100', jasa_pelayanan: 'x', bhp: '0' })).toBeNull()
    expect(formatRupiah('150000')).toMatch(/Rp\s?150\.000/)
    expect(periksaHarga([{ kelas_rawat_id: 'a', jasa_sarana: '1', jasa_pelayanan: '1', bhp: '1' }, { kelas_rawat_id: 'a', jasa_sarana: '1', jasa_pelayanan: '1', bhp: '1' }])).toContain('Baris 2')
  })
})
