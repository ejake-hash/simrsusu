import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import type { PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { PIN_TIRUAN } from '@/modules/ttd/tiruan/serverTtd'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { bacaPetaTempatTidur, bacaRencanaPulang, bacaResumeMedis, daftarDaftarPeriksaPulang, pulangkanPasien, sahkanResumeMedis, simpanRencanaPulang, susunResumeMedis, ubahDaftarPeriksaPulang } from './api'
import DaftarPeriksaPulangPage from './pages/DaftarPeriksaPulangPage.vue'
import PemulanganPage from './pages/PemulanganPage.vue'
import PetaTempatTidurPage from './pages/PetaTempatTidurPage.vue'
import {
  bolehBukaPemulangan,
  butirPerluAlasan,
  gabungButirAlasan,
  galatButirPulang,
  isianPutRencana,
  isianResumeBerubah,
  kurangPulang,
  kurangRencana,
  pesanGalatPulang,
  pesanGalatRencana,
  pesanGalatResume,
  pulangBasi,
  rencanaBerubah,
  tandaButir,
  teksIdentitas,
} from './pemulangan'
import type { ButirDaftarPeriksaPulang, RencanaPulang, ResumeMedis } from './tipe'

/** Tiket 2.40-fe: server tiruan kontrak v0.39.0, aturan layar pemulangan, dan layar Pemulangan/Daftar periksa pulang. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let permintaan: PermintaanTiruan[] = []
const IZIN_SEMUA = [
  'rawat_inap.peta_tempat_tidur.lihat',
  'rawat_inap.rencana_pulang.lihat', 'rawat_inap.rencana_pulang.input', 'rawat_inap.rencana_pulang.perbaiki',
  'rawat_inap.pemulangan.lihat', 'rawat_inap.pemulangan.input', 'rawat_inap.pemulangan.sahkan',
  'master_data.daftar_periksa_pulang.lihat', 'master_data.daftar_periksa_pulang.perbaiki',
]

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => new Date('2026-11-02T03:00:00Z') })
  permintaan = []
  pasangTiruan(klien, { tangani: (r: PermintaanTiruan) => (permintaan.push(r), gabungan.tangani(r)) })
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'dokter.poli', mfa_aktif: true, pegawai: { id: gabungan.dpjpRawatInap.id, nama: gabungan.dpjpRawatInap.nama } })
  aturIzin(IZIN_SEMUA)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

/** Id kunjungan RI pasien di TT contoh (peta tiruan membawanya bagi izin lihat pemulangan). */
async function kunjunganDi(kode: string) {
  const r = await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null })
  if (!r.berubah) throw new Error('peta tidak berubah')
  return r.data.data.find((t) => t.kode === kode)!
}
const butir = (x: Partial<ButirDaftarPeriksaPulang>): ButirDaftarPeriksaPulang => ({ kode: 'A', nama: 'Butir A', sumber: 'manual', wajib: true, status: 'belum', selesai_pada: null, selesai_oleh: null, alasan_tidak_selesai: null, ...x })

describe('pemulangan: aturan layar', () => {
  it('status butir berteks + ikon; butir wajib manual belum selesai butuh alasan, butir kasir tidak', () => {
    expect(tandaButir({ status: 'selesai', wajib: true })).toEqual({ label: 'Selesai', jenis: 'ok', ikon: 'centang' })
    expect(tandaButir({ status: 'menunggu_kasir', wajib: true })).toEqual({ label: 'Menunggu kasir (Tahap D)', jenis: 'info', ikon: 'jam' })
    expect(tandaButir({ status: 'belum', wajib: true }).label).toBe('Belum, wajib')
    expect(tandaButir({ status: 'belum', wajib: false }).jenis).toBe('netral')
    const daftar = [butir({ kode: 'A' }), butir({ kode: 'B', status: 'selesai' }), butir({ kode: 'C', sumber: 'kejadian', status: 'menunggu_kasir' }), butir({ kode: 'D', wajib: false })]
    expect(butirPerluAlasan(daftar).map((b) => b.kode)).toEqual(['A'])
  })

  it('422 daftar_periksa_belum_lengkap: pesan per kode, butir dari server ditambahkan ke kolom alasan', () => {
    const e = new ApiError(422, 'daftar_periksa_belum_lengkap', 'x', [
      { kolom: 'tanggapan', kode: 'A', nama: 'Butir A', pesan: 'Selesaikan butir ini atau tulis alasan belum selesai.' },
      { kolom: 'tanggapan', kode: 'Z', nama: 'Butir Z', pesan: 'Selesaikan butir ini atau tulis alasan belum selesai.' },
    ])
    expect(galatButirPulang(e)).toEqual({ A: 'Selesaikan butir ini atau tulis alasan belum selesai.', Z: 'Selesaikan butir ini atau tulis alasan belum selesai.' })
    expect(gabungButirAlasan([{ kode: 'A', nama: 'Butir A' }], e)).toEqual([{ kode: 'A', nama: 'Butir A' }, { kode: 'Z', nama: 'Butir Z' }])
    expect(galatButirPulang(new ApiError(422, 'validasi_gagal', 'x'))).toEqual({})
    expect(pesanGalatPulang(e)).toMatch(/Lengkapi alasan/)
  })

  it('syarat kirim pulang: identitas dulu, lalu cara keluar, alasan, dan alasan tiap butir', () => {
    const dasar = { identitasAda: true, tujuan: 'pulang' as const, alasan: 'Perbaikan klinis', butir: [{ kode: 'A', nama: 'Butir A' }], tanggapan: { A: 'Pasien menolak' } }
    expect(kurangPulang(dasar)).toBeNull()
    expect(kurangPulang({ ...dasar, identitasAda: false })).toMatch(/Identitas pasien/)
    expect(kurangPulang({ ...dasar, tujuan: '' })).toBe('Pilih cara keluar.')
    expect(kurangPulang({ ...dasar, alasan: 'ok' })).toMatch(/minimal 5/)
    expect(kurangPulang({ ...dasar, tanggapan: { A: 'x' } })).toMatch(/1 butir wajib/)
  })

  it('pesan galat pulang, rencana, dan resume per kode kontrak', () => {
    expect(pesanGalatPulang(new ApiError(409, 'status_tidak_sesuai', 'x'))).toMatch(/sudah tidak dirawat/)
    expect(pesanGalatPulang(new ApiError(422, 'perpindahan_di_luar_matriks', 'x'))).toMatch(/matriks/)
    expect(pesanGalatPulang(new ApiError(403, 'izin_praktik_tidak_berlaku', 'x'))).toMatch(/SIP\/SPK/)
    expect(pesanGalatPulang(new ApiError(403, 'akses_ditolak', 'x'))).toMatch(/cakupan bangsal/)
    expect(pesanGalatPulang(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'alasan', pesan: 'y' }]))).toBeNull()
    expect(pulangBasi(new ApiError(404, 'tidak_ditemukan', 'x'))).toBe(true)
    expect(pulangBasi(new ApiError(409, 'status_tidak_sesuai', 'x'))).toBe(true)
    expect(pulangBasi(new ApiError(422, 'daftar_periksa_belum_lengkap', 'x'))).toBe(false)
    expect(pesanGalatRencana(new ApiError(412, 'versi_berubah', 'x'))).toMatch(/diubah petugas lain/)
    expect(pesanGalatRencana(new ApiError(428, 'if_match_wajib', 'x'))).toMatch(/sudah dibuat petugas lain/)
    expect(pesanGalatRencana(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'rencana_pulang_pada', pesan: 'y' }]))).toBeNull()
    expect(pesanGalatResume(new ApiError(403, 'bukan_pemutus', 'x'), 'sahkan')).toMatch(/hanya bisa disahkan DPJP/)
    expect(pesanGalatResume(new ApiError(409, 'sudah_disahkan', 'x'), 'susun')).toMatch(/sudah disahkan/)
    expect(pesanGalatResume(new ApiError(412, 'versi_berubah', 'x'), 'sahkan')).toMatch(/disusun ulang/)
    expect(pesanGalatResume(new ApiError(403, 'izin_praktik_tidak_berlaku', 'x'), 'susun')).toMatch(/SIP\/SPK/)
  })

  it('isian PUT hanya mengirim centang yang berubah; lama rawat kosong = null; validasi 1..365', () => {
    const r = { id: 'r1', rencana_pulang_pada: '2026-11-03', rencana_lama_rawat: 4, daftar_periksa: [butir({ kode: 'A' }), butir({ kode: 'B', status: 'selesai' }), butir({ kode: 'C', sumber: 'kejadian', status: 'menunggu_kasir' })] } as RencanaPulang
    const isi = { tanggal: '2026-11-03', lamaRawat: '', selesai: { A: true, B: true } }
    expect(isianPutRencana(r, isi)).toEqual({ rencana_pulang_pada: '2026-11-03', rencana_lama_rawat: null, daftar_periksa: [{ kode: 'A', selesai: true }] })
    expect(rencanaBerubah(r, { tanggal: '2026-11-03', lamaRawat: '4', selesai: { A: false, B: true } })).toBe(false)
    expect(kurangRencana({ tanggal: '', lamaRawat: '0', selesai: {} })).toEqual({ rencana_pulang_pada: 'Isi tanggal rencana pulang.', rencana_lama_rawat: 'Isi bilangan bulat 1 sampai 365 hari, atau kosongkan.' })
    expect(isianResumeBerubah({ isian_dpjp: { ringkasan_perjalanan: 'a', kondisi_pulang: null, instruksi_pulang: null, obat_pulang: null, kontrol: null } } as ResumeMedis, { ringkasan_perjalanan: 'a ', kondisi_pulang: 'Baik', instruksi_pulang: '', obat_pulang: '', kontrol: '' })).toEqual({ kondisi_pulang: 'Baik' })
  })

  it('tautan Pemulangan peta hanya untuk TT berpasien ber-id kunjungan dan izin lihat; identitas dua penanda', () => {
    expect(bolehBukaPemulangan({ ditutup: false, kunjungan_rawat_inap_id: 'k1' }, true)).toBe(true)
    expect(bolehBukaPemulangan({ ditutup: false, kunjungan_rawat_inap_id: 'k1' }, false)).toBe(false)
    expect(bolehBukaPemulangan({ ditutup: false }, true)).toBe(false)
    expect(teksIdentitas({ nama: 'Pasien Contoh', no_rm: '900099', tanggal_lahir: '1965-05-05' })).toMatch(/^Pasien Contoh, No\. RM .+, lahir 5 Mei 1965$/)
  })
})

describe('pemulangan: server tiruan kontrak v0.39.0', () => {
  it('rencana: pratinjau data induk, buat 201, ubah wajib If-Match (428/412), butir kasir 422', async () => {
    const tt = await kunjunganDi('CTHG-2-201-1')
    const k = tt.kunjungan_rawat_inap_id!
    const awal = await bacaRencanaPulang(k)
    expect(awal).toMatchObject({ id: null, versi: null, pasien: { nama: tt.pasien_rawat_inap!.nama } })
    expect(awal.daftar_periksa.map((b) => [b.kode, b.status])).toEqual([['OBAT_EDUKASI', 'belum'], ['SURAT_KONTROL', 'belum'], ['RUJUK_BALIK', 'belum'], ['TAGIHAN_JAMINAN', 'menunggu_kasir'], ['LEPAS_INFUS_GELANG', 'belum']])
    const dibuat = await simpanRencanaPulang(k, null, { rencana_pulang_pada: '2026-11-02', daftar_periksa: [{ kode: 'OBAT_EDUKASI', selesai: true }] })
    expect(dibuat).toMatchObject({ status: 'direncanakan', rencana_pulang_pada: '2026-11-02', versi: '1' })
    expect((await kunjunganDi('CTHG-2-201-1')).status).toBe('rencana_pulang')
    await expect(simpanRencanaPulang(k, null, { rencana_pulang_pada: '2026-11-03' })).rejects.toMatchObject({ status: 428 })
    await expect(simpanRencanaPulang(k, '0', { rencana_pulang_pada: '2026-11-03' })).rejects.toMatchObject({ status: 412, kode: 'versi_berubah' })
    await expect(simpanRencanaPulang(k, '1', { rencana_pulang_pada: '2026-11-03', daftar_periksa: [{ kode: 'TAGIHAN_JAMINAN', selesai: true }] })).rejects.toMatchObject({ status: 422 })
  })

  it('pulang: 422 per butir wajib, lalu sukses dengan alasan; TT dibersihkan dan id kunjungan hilang dari peta; ulang 409', async () => {
    const k = (await kunjunganDi('CTHG-2-201-1')).kunjungan_rawat_inap_id!
    await simpanRencanaPulang(k, null, { rencana_pulang_pada: '2026-11-02', daftar_periksa: [{ kode: 'OBAT_EDUKASI', selesai: true }, { kode: 'SURAT_KONTROL', selesai: true }] })
    const gagal = await pulangkanPasien(k, { tujuan: 'pulang', alasan: 'Perbaikan klinis', tanggapan: [] }).catch((e: ApiError) => e)
    expect(gagal).toMatchObject({ status: 422, kode: 'daftar_periksa_belum_lengkap' })
    expect(Object.keys(galatButirPulang(gagal))).toEqual(['RUJUK_BALIK', 'LEPAS_INFUS_GELANG'])
    const hasil = await pulangkanPasien(k, { tujuan: 'aps', alasan: 'Permintaan keluarga', tanggapan: [{ kode: 'RUJUK_BALIK', alasan: 'Tidak perlu rujuk balik' }, { kode: 'LEPAS_INFUS_GELANG', alasan: 'Dilepas di IGD lain' }] })
    expect(hasil).toMatchObject({ kunjungan: { status: 'selesai' }, penempatan: null })
    const tt = await kunjunganDi('CTHG-2-201-1')
    expect(tt.status).toBe('dibersihkan')
    expect(tt.kunjungan_rawat_inap_id).toBeUndefined()
    expect((await bacaRencanaPulang(k)).daftar_periksa.find((b) => b.kode === 'RUJUK_BALIK')!.alasan_tidak_selesai).toBe('Tidak perlu rujuk balik')
    await expect(pulangkanPasien(k, { tujuan: 'pulang', alasan: 'Ulang uji', tanggapan: [] })).rejects.toMatchObject({ status: 409, kode: 'status_tidak_sesuai' })
  })

  it('resume: null → susun 201 → susun ulang 200 dengan isian tetap; sahkan PIN DPJP, bukan DPJP 403, versi basi 412, sah 409', async () => {
    const k = (await kunjunganDi('CTHG-2-201-1')).kunjungan_rawat_inap_id!
    expect(await bacaResumeMedis(k)).toBeNull()
    const draf = await susunResumeMedis(k, { kondisi_pulang: 'Stabil' })
    expect(draf).toMatchObject({ status: 'draf', versi: '1', isian_dpjp: { kondisi_pulang: 'Stabil' }, sumber: { tindakan: { tersedia: false } } })
    const ulang = await susunResumeMedis(k, { kontrol: 'Poli dalam 7 hari' })
    expect(ulang).toMatchObject({ versi: '2', isian_dpjp: { kondisi_pulang: 'Stabil', kontrol: 'Poli dalam 7 hari' } })
    await expect(sahkanResumeMedis(draf, { pin: PIN_TIRUAN, pernyataan_disetujui: true })).rejects.toMatchObject({ status: 412 })
    gabungan.aturPegawaiPemulangan(() => 'pegawai-lain')
    await expect(sahkanResumeMedis(ulang, { pin: PIN_TIRUAN, pernyataan_disetujui: true })).rejects.toMatchObject({ status: 403, kode: 'bukan_pemutus' })
    gabungan.aturPegawaiPemulangan(() => gabungan.dpjpRawatInap.id)
    await expect(sahkanResumeMedis(ulang, { pin: '000000', pernyataan_disetujui: true })).rejects.toMatchObject({ status: 422, kode: 'pin_salah' })
    expect(await sahkanResumeMedis(ulang, { pin: PIN_TIRUAN, pernyataan_disetujui: true })).toMatchObject({ status: 'sah' })
    await expect(susunResumeMedis(k)).rejects.toMatchObject({ status: 409, kode: 'sudah_disahkan' })
  })

  it('data induk: daftar urut kode, ubah butuh If-Match dan dasar; 412 bila versi basi', async () => {
    const { data } = await daftarDaftarPeriksaPulang(null)
    expect(data.map((d) => d.kode)).toEqual(['LEPAS_INFUS_GELANG', 'OBAT_EDUKASI', 'RUJUK_BALIK', 'SURAT_KONTROL', 'TAGIHAN_JAMINAN'])
    const rujuk = data.find((d) => d.kode === 'RUJUK_BALIK')!
    expect(await ubahDaftarPeriksaPulang(rujuk, { wajib: false, dasar_perubahan: 'SK komite' })).toMatchObject({ wajib: false, versi: '2' })
    await expect(ubahDaftarPeriksaPulang(rujuk, { wajib: true, dasar_perubahan: 'SK komite' })).rejects.toMatchObject({ status: 412 })
  })
})

describe('pemulangan: layar', () => {
  const terpasang: VueWrapper[] = []
  afterEach(() => terpasang.splice(0).forEach((w) => w.unmount()))
  async function tenang() {
    for (let i = 0; i < 8; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, 10))
    }
  }
  async function pasang(komponen: object, jalur: string, props: Record<string, unknown> = {}) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push(jalur)
    const wrapper = mount(komponen, {
      attachTo: document.body,
      props,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    terpasang.push(wrapper)
    await tenang()
    return wrapper
  }
  const halaman = async (k: string) => pasang(PemulanganPage, `/rawat-inap/pemulangan?kunjungan=${k}`, { izin: 'rawat_inap.pemulangan.lihat', judul: 'Pemulangan' })
  const tombol = (nama: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((x) => x.textContent?.trim() === nama) as HTMLButtonElement | undefined
  const isi = (label: string, nilai: string, akar: ParentNode = document) => {
    const id = [...akar.querySelectorAll('label')].find((l) => l.textContent?.trim().startsWith(label))!.getAttribute('for')!
    const el = document.getElementById(id) as HTMLInputElement | HTMLTextAreaElement
    el.value = nilai
    el.dispatchEvent(new Event('input'))
  }
  const dialog = () => document.querySelector('[role="dialog"], [role="alertdialog"]') as HTMLElement | null

  it('tanpa kunjungan: daftar pasien dirawat dari peta dengan tautan ke layar pemulangan', async () => {
    await pasang(PemulanganPage, '/rawat-inap/pemulangan', { izin: 'rawat_inap.pemulangan.lihat', judul: 'Pemulangan' })
    const tautan = [...document.querySelectorAll('a')].filter((a) => a.getAttribute('href')?.startsWith('/rawat-inap/pemulangan?kunjungan='))
    expect(tautan).toHaveLength(2)
    expect(tautan[0]!.textContent).toContain('Pasien Contoh Rawat Inap')
  })

  it('kepala pasien dua penanda; bagian tampil menurut izin: tanpa input pemulangan tidak ada Pulangkan, tanpa sahkan tidak ada Sahkan', async () => {
    const tt = await kunjunganDi('CTHG-2-201-1')
    const k = tt.kunjungan_rawat_inap_id!
    await susunResumeMedis(k)
    aturIzin(['rawat_inap.peta_tempat_tidur.lihat', 'rawat_inap.pemulangan.lihat', 'rawat_inap.rencana_pulang.lihat'])
    await halaman(k)
    expect(document.querySelector('h2#judul-pasien')!.textContent).toBe(tt.pasien_rawat_inap!.nama)
    expect(document.body.textContent).toContain('No. RM')
    expect(document.body.textContent).toContain('5 Mei 1965')
    expect(document.body.textContent).toContain('Rencana pulang dan daftar periksa')
    expect(document.body.textContent).toContain('Menunggu kasir (Tahap D)')
    expect(tombol('Pulangkan pasien')).toBeUndefined()
    expect(tombol('Sahkan dengan PIN')).toBeUndefined()
    expect(tombol('Buat rencana pulang')).toBeUndefined()
    // Resume hanya dibaca: isian DPJP tidak bisa diketik.
    expect(document.querySelectorAll('textarea')).toHaveLength(0)
    expect(document.body.textContent).toContain('Belum tersedia: modul tindakan menyusul.')
  })

  it('tanpa izin lihat rencana pulang: panel rencana tidak dipasang dan GET rencana tidak dikirim', async () => {
    const k = (await kunjunganDi('CTHG-2-201-1')).kunjungan_rawat_inap_id!
    aturIzin(['rawat_inap.peta_tempat_tidur.lihat', 'rawat_inap.pemulangan.lihat', 'rawat_inap.pemulangan.input'])
    permintaan = []
    await halaman(k)
    expect(document.body.textContent).not.toContain('Rencana pulang dan daftar periksa')
    expect(permintaan.some((p) => p.jalur.endsWith('/rencana-pulang'))).toBe(false)
    // Identitas dari peta TT.
    expect(document.querySelector('h2#judul-pasien')!.textContent).toBe('Pasien Contoh Rawat Inap')
    expect(tombol('Pulangkan pasien')).toBeDefined()
  })

  it('rencana: buat rencana dengan centang; 412 memuat ulang isian lalu menampilkan pesan', async () => {
    const k = (await kunjunganDi('CTHG-2-201-1')).kunjungan_rawat_inap_id!
    await halaman(k)
    const centang = [...document.querySelectorAll('label')].find((l) => l.textContent?.includes('Obat pulang dan edukasi'))!.querySelector('input')!
    centang.click()
    tombol('Buat rencana pulang')!.click()
    await tenang()
    expect(daftarToast.value.map((t) => t.pesan).join(' ')).toMatch(/Rencana pulang .+ tersimpan/)
    expect(document.body.textContent).toContain('Selesai')
    // Petugas lain mengubah rencana: versi di layar basi.
    await simpanRencanaPulang(k, '1', { rencana_pulang_pada: '2026-11-04' })
    isi('Rencana lama rawat', '5')
    await tenang()
    tombol('Simpan rencana pulang')!.click()
    await tenang()
    expect(document.querySelector('[role="alert"]')!.textContent).toMatch(/diubah petugas lain/)
    expect((document.querySelector('input[type="date"]') as HTMLInputElement).value).toBe('2026-11-04')
  })

  it('dialog pulang: identitas dua penanda tampil sebelum tombol konfirmasi; alasan per butir wajib; sukses → TT dibersihkan, pengingat resume', async () => {
    const tt = await kunjunganDi('CTHG-2-201-1')
    const k = tt.kunjungan_rawat_inap_id!
    await simpanRencanaPulang(k, null, { rencana_pulang_pada: '2026-11-02', daftar_periksa: [{ kode: 'OBAT_EDUKASI', selesai: true }, { kode: 'SURAT_KONTROL', selesai: true }, { kode: 'RUJUK_BALIK', selesai: true }] })
    await halaman(k)
    tombol('Pulangkan pasien')!.click()
    await tenang()
    const d = dialog()!
    const teks = d.textContent!
    const posIdentitas = teks.indexOf(teksIdentitas(tt.pasien_rawat_inap!))
    expect(posIdentitas).toBeGreaterThanOrEqual(0)
    expect(teks).toContain('CTHG-2-201-1')
    // Satu butir wajib manual belum selesai (lepas infus); butir kasir tidak diminta alasan.
    expect(teks).toContain('Alasan belum selesai: Lepas infus dan gelang')
    expect(teks).not.toContain('Alasan belum selesai: Tagihan')
    const konfirmasi = [...document.querySelectorAll('button[type="submit"][form="form-pulangkan"]')][0] as HTMLButtonElement
    expect(konfirmasi.disabled).toBe(true)
    // Identitas tampil di atas tombol konfirmasi (urutan dokumen).
    expect(d.contains(konfirmasi)).toBe(true)
    expect(d.querySelector('dl')!.compareDocumentPosition(konfirmasi) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    ;(d.querySelector('input[value="pulang"]') as HTMLInputElement).click()
    isi('Alasan atau catatan keluar', 'Perbaikan klinis', d)
    await tenang()
    expect(konfirmasi.disabled).toBe(true)
    isi('Alasan belum selesai: Lepas infus', 'Dilepas sebelum pindah', d)
    await tenang()
    expect(konfirmasi.disabled).toBe(false)
    expect(konfirmasi.textContent).toContain(`Pulang: ${tt.pasien_rawat_inap!.nama}`)
    konfirmasi.click()
    await tenang()
    expect(dialog()).toBeNull()
    expect(daftarToast.value.map((t) => t.pesan).join(' ')).toMatch(/TT CTHG-2-201-1 kini Dibersihkan\. Resume medis belum sah\./)
    expect(document.body.textContent).toContain('Sudah pulang')
    expect(document.body.textContent).toMatch(/resume medisnya belum sah/)
    expect(tombol('Pulangkan pasien')).toBeUndefined()
    expect((await kunjunganDi('CTHG-2-201-1')).status).toBe('dibersihkan')
  })

  it('dialog pulang: 422 dari server menambah kolom alasan butir yang belum terlihat (akun tanpa lihat rencana)', async () => {
    const k = (await kunjunganDi('CTHG-2-201-1')).kunjungan_rawat_inap_id!
    aturIzin(['rawat_inap.peta_tempat_tidur.lihat', 'rawat_inap.pemulangan.lihat', 'rawat_inap.pemulangan.input'])
    await halaman(k)
    tombol('Pulangkan pasien')!.click()
    await tenang()
    const d = dialog()!
    expect(d.textContent).toContain('Daftar periksa tidak tampil untuk akun Anda')
    ;(d.querySelector('input[value="rujuk_keluar"]') as HTMLInputElement).click()
    isi('Alasan atau catatan keluar', 'Rujuk ke RS rujukan', d)
    await tenang()
    ;(document.querySelector('button[form="form-pulangkan"]') as HTMLButtonElement).click()
    await tenang()
    expect(d.textContent).toContain('Alasan belum selesai: Obat pulang dan edukasi')
    expect(d.textContent).toContain('Alasan belum selesai: Lepas infus dan gelang')
    expect(d.querySelector('[role="alert"]')!.textContent).toMatch(/Lengkapi alasan/)
    expect(d.textContent).toContain('Selesaikan butir ini atau tulis alasan belum selesai.')
    // Kiriman kedua membawa alasan butir dari rincian 422 dan berhasil (tinjauan #205).
    for (const nama of ['Obat pulang dan edukasi', 'Surat kontrol', 'Rujuk balik', 'Lepas infus dan gelang']) isi(`Alasan belum selesai: ${nama}`, 'Diselesaikan di RS rujukan', d)
    await tenang()
    const konfirmasi = document.querySelector('button[form="form-pulangkan"]') as HTMLButtonElement
    expect(konfirmasi.disabled).toBe(false)
    permintaan = []
    konfirmasi.click()
    await tenang()
    const kirim = permintaan.find((p) => p.jalur.endsWith('/pulang'))!
    expect((kirim.isi as { tanggapan: { kode: string }[] }).tanggapan.map((t) => t.kode)).toEqual(['OBAT_EDUKASI', 'SURAT_KONTROL', 'RUJUK_BALIK', 'LEPAS_INFUS_GELANG'])
    expect(dialog()).toBeNull()
    expect(daftarToast.value.map((t) => t.pesan).join(' ')).toMatch(/keluar rawat inap \(Rujuk keluar\)/)
    expect((await kunjunganDi('CTHG-2-201-1')).status).toBe('dibersihkan')
  })

  it('resume: susun, isi kondisi pulang, sahkan lewat TtdModal (PIN) → sah dan hanya dibaca', async () => {
    const tt = await kunjunganDi('CTHG-2-201-1')
    const k = tt.kunjungan_rawat_inap_id!
    await halaman(k)
    tombol('Susun resume')!.click()
    await tenang()
    expect(document.body.textContent).toContain('Observasi febris (contoh)')
    expect(document.body.textContent).toContain('1 CPPT berlabel sensitivitas tidak ikut dipotret.')
    isi('Kondisi saat pulang', 'Stabil, makan baik')
    await tenang()
    expect((tombol('Sahkan dengan PIN') as HTMLButtonElement).disabled).toBe(true)
    tombol('Simpan isian dan susun ulang')!.click()
    await tenang()
    tombol('Sahkan dengan PIN')!.click()
    await tenang()
    const d = dialog()!
    expect(d.textContent).toContain(`Resume medis · ${teksIdentitas(tt.pasien_rawat_inap!)}`)
    expect(d.textContent).toContain('Kondisi saat pulang: Stabil, makan baik')
    ;(d.querySelector('input[type="checkbox"]') as HTMLInputElement).click()
    isi('PIN tanda tangan', PIN_TIRUAN, d)
    tombol('Tanda tangani', d)!.click()
    await tenang()
    expect(daftarToast.value.map((t) => t.pesan).join(' ')).toMatch(/Resume medis .+ sah/)
    expect(document.body.textContent).toContain('Resume sudah sah dan final.')
    expect(tombol('Sahkan dengan PIN')).toBeUndefined()
    expect(document.querySelectorAll('textarea')).toHaveLength(0)
  })

  it('resume: pemegang izin sahkan yang bukan DPJP tidak mendapat tombol, hanya keterangan pengesah (TTE hanya pemilik akun)', async () => {
    const k = (await kunjunganDi('CTHG-2-201-1')).kunjungan_rawat_inap_id!
    await susunResumeMedis(k)
    useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, mfa_aktif: true, pegawai: { id: 'pegawai-lain', nama: 'Dokter Lain' } })
    await halaman(k)
    expect(tombol('Sahkan dengan PIN')).toBeUndefined()
    expect(document.body.textContent).toContain(`Disahkan oleh DPJP, ${gabungan.dpjpRawatInap.nama}, dengan akunnya sendiri.`)
    expect(dialog()).toBeNull()
  })

  it('akun hanya izin rencana pulang: tautan peta dan pilihan pasien menuju /rawat-inap/rencana-pulang', async () => {
    aturIzin(['rawat_inap.peta_tempat_tidur.lihat', 'rawat_inap.rencana_pulang.lihat', 'rawat_inap.rencana_pulang.input'])
    await pasang(PetaTempatTidurPage, '/rawat-inap/peta-tempat-tidur')
    const tautanPeta = [...document.querySelectorAll('a')].find((a) => a.getAttribute('aria-label') === 'Pemulangan pasien di CTHG-2-201-1')!
    expect(tautanPeta.getAttribute('href')).toMatch(/^\/rawat-inap\/rencana-pulang\?kunjungan=[0-9a-f-]{36}$/)
    terpasang.splice(0).forEach((w) => w.unmount())
    await pasang(PemulanganPage, '/rawat-inap/rencana-pulang', { izin: 'rawat_inap.rencana_pulang.lihat', judul: 'Rencana pulang' })
    const pilihan = [...document.querySelectorAll('a')].filter((a) => a.getAttribute('href')?.includes('?kunjungan='))
    expect(pilihan).toHaveLength(2)
    expect(pilihan.every((a) => a.getAttribute('href')!.startsWith('/rawat-inap/rencana-pulang?kunjungan='))).toBe(true)
  })

  it('peta TT: tautan Pemulangan hanya bagi izin lihat pemulangan/rencana pulang', async () => {
    aturIzin(['rawat_inap.peta_tempat_tidur.lihat', 'rawat_inap.rencana_pulang.lihat', 'rawat_inap.pemulangan.lihat'])
    await pasang(PetaTempatTidurPage, '/rawat-inap/peta-tempat-tidur')
    const tautan = [...document.querySelectorAll('a')].filter((a) => a.getAttribute('aria-label')?.startsWith('Pemulangan pasien di'))
    expect(tautan).toHaveLength(2)
    expect(tautan[0]!.getAttribute('aria-label')).toBe('Pemulangan pasien di CTHG-2-201-1')
    expect(tautan[0]!.getAttribute('href')).toMatch(/^\/rawat-inap\/pemulangan\?kunjungan=/)
    terpasang.splice(0).forEach((w) => w.unmount())
    aturIzin(['rawat_inap.peta_tempat_tidur.lihat'])
    await pasang(PetaTempatTidurPage, '/rawat-inap/peta-tempat-tidur')
    expect([...document.querySelectorAll('a')].filter((a) => a.getAttribute('aria-label')?.startsWith('Pemulangan pasien di'))).toHaveLength(0)
  })

  it('data induk: ubah butir dengan dasar perubahan; tanpa izin perbaiki tidak ada kolom aksi', async () => {
    await pasang(DaftarPeriksaPulangPage, '/master-data/daftar-periksa-pulang')
    expect(document.body.textContent).toContain('Kasir (Tahap D)')
    ;[...document.querySelectorAll('button')].find((b) => b.getAttribute('aria-label') === 'Ubah Rujuk balik')!.click()
    await tenang()
    const d = dialog()!
    isi('Urutan tampil', '30', d)
    tombol('Simpan', d)!.click()
    await tenang()
    expect(d.textContent).toContain('Isi dasar perubahan, minimal 3 karakter.')
    isi('Dasar perubahan', 'Notulen komite 12/2026', d)
    tombol('Simpan', d)!.click()
    await tenang()
    expect(daftarToast.value.map((t) => t.pesan).join(' ')).toMatch(/Butir RUJUK_BALIK tersimpan/)
    expect(gabungan.pemulangan.dataInduk().find((x) => x.kode === 'RUJUK_BALIK')!.urutan).toBe(30)
    terpasang.splice(0).forEach((w) => w.unmount())
    aturIzin(['master_data.daftar_periksa_pulang.lihat'])
    await pasang(DaftarPeriksaPulangPage, '/master-data/daftar-periksa-pulang')
    expect([...document.querySelectorAll('button')].filter((b) => b.textContent?.trim() === 'Ubah')).toHaveLength(0)
  })
})
