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
import { semuaMatriks } from '@/modules/matriks-perpindahan/api'
import { buatKunjunganIgd, catatDisposisi, daftarDisposisi, daftarKunjunganIgd } from '@/modules/pendaftaran/apiKunjungan'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { butuhSerahTerima, pesanGalatDisposisi, tujuanDariMatriks } from './disposisi'
import DisposisiPage from './pages/DisposisiPage.vue'
import PendaftaranIgdPage from './pages/PendaftaranIgdPage.vue'

/** Tiket 2.26: server tiruan kontrak v0.33.0 (disposisi menurut matriks perpindahan) dan layar IGD → Disposisi. Data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let jam = new Date('2026-11-02T03:00:00Z')
const IZIN = ['igd.pendaftaran.lihat', 'igd.disposisi.lihat', 'igd.disposisi.input']
let PASIEN2: string

beforeEach(() => {
  jam = new Date('2026-11-02T03:00:00Z')
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => jam })
  pasangTiruan(klien, gabungan)
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'dokter.poli', mfa_aktif: true })
  PASIEN2 = gabungan.pasien.contoh.dua.id
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

const igdBaru = async () => (await buatKunjunganIgd({ pasien_id: PASIEN2, cara_bayar: 'non_jkn' })).data

describe('disposisi IGD: server tiruan kontrak v0.33.0', () => {
  it('pulang menutup kunjungan (keluar dari daftar aktif); rawat inap menunggu kamar dan tidak bisa diulang', async () => {
    const k = await igdBaru()
    const pulang = await catatDisposisi(k.id, { tujuan: 'pulang', alasan: 'Keluhan membaik (uji sintetis)' })
    expect(pulang).toMatchObject({ tujuan: 'pulang', status: 'selesai', pos_asal: 'igd', serah_terima: null, diputuskan_pada: jam.toISOString() })
    expect((await daftarKunjunganIgd()).data.map((x) => x.id)).not.toContain(k.id)
    await expect(catatDisposisi(k.id, { tujuan: 'pulang', alasan: 'Ulang (uji sintetis)' })).rejects.toMatchObject({ status: 409, kode: 'status_tidak_sesuai' })

    const k2 = await igdBaru()
    await expect(catatDisposisi(k2.id, { tujuan: 'rawat_inap', alasan: 'Perlu rawat inap (uji)' })).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'serah_terima' }] })
    const ri = await catatDisposisi(k2.id, { tujuan: 'rawat_inap', alasan: 'Perlu rawat inap (uji)', serah_terima: 'Infus terpasang (uji sintetis)' })
    expect(ri.status).toBe('menunggu_kamar')
    expect((await daftarKunjunganIgd()).data.map((x) => x.id)).toContain(k2.id)
    await expect(catatDisposisi(k2.id, { tujuan: 'rawat_inap', alasan: 'Ulang perintah', serah_terima: 'Sama (uji sintetis)' })).rejects.toMatchObject({ status: 409, kode: 'perintah_sudah_menunggu' })
    expect((await daftarDisposisi(k2.id)).data.map((d) => d.status)).toEqual(['menunggu_kamar'])
  })

  it('tujuan di luar matriks, bukan disposisi, dan alasan terlalu pendek', async () => {
    const k = await igdBaru()
    await expect(catatDisposisi(k.id, { tujuan: 'penunjang' as 'pulang', alasan: 'Order lab (uji)' })).rejects.toMatchObject({ status: 422, kode: 'tujuan_bukan_disposisi' })
    gabungan.matriks.contoh.aps.status = 'nonaktif'
    await expect(catatDisposisi(k.id, { tujuan: 'aps', alasan: 'Pasien menolak (uji)' })).rejects.toMatchObject({ status: 422, kode: 'perpindahan_di_luar_matriks' })
    await expect(catatDisposisi(k.id, { tujuan: 'pulang', alasan: 'abc' })).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'alasan' }] })
  })

  it('tujuan dari matriks: hanya sel aktif asal IGD dan hanya kode disposisi FR-03-09', async () => {
    gabungan.matriks.contoh.doa.status = 'nonaktif'
    const sel = await semuaMatriks({ pos_asal: 'igd', status: 'aktif' })
    expect(tujuanDariMatriks(sel)).toEqual(['pulang', 'rawat_inap', 'kamar_operasi', 'rujuk_keluar', 'aps', 'meninggal'])
    expect(butuhSerahTerima('rawat_inap')).toBe(true)
    expect(butuhSerahTerima('kamar_operasi')).toBe(true)
    expect(butuhSerahTerima('pulang')).toBe(false)
  })

  it('pesan galat per kode', () => {
    expect(pesanGalatDisposisi(new ApiError(403, 'izin_praktik_tidak_berlaku', 'x'))).toMatch(/SIP\/SPK Anda tidak berlaku hari ini\. Perintah klinis hanya dari dokter dengan SIP berlaku\./)
    expect(pesanGalatDisposisi(new ApiError(403, 'akses_ditolak', 'x'))).toMatch(/tidak memegang izin pemberi perintah/)
    // 403 lain memakai pesan server, bukan pesan izin sel matriks.
    expect(pesanGalatDisposisi(new ApiError(403, 'kewenangan_klinis_tidak_mencakup', 'Kewenangan klinis Anda tidak mencakup tindakan ini.'))).toBe('Kewenangan klinis Anda tidak mencakup tindakan ini.')
    expect(pesanGalatDisposisi(new ApiError(403, 'mfa_belum_terdaftar', 'Daftarkan MFA dulu.'))).toBe('Daftarkan MFA dulu.')
    expect(pesanGalatDisposisi(new ApiError(422, 'perpindahan_di_luar_matriks', 'x', [{ kolom: 'tujuan' }]))).toMatch(/tidak lagi aktif di matriks perpindahan/)
    expect(pesanGalatDisposisi(new ApiError(422, 'tujuan_bukan_disposisi', 'x', [{ kolom: 'tujuan' }]))).toMatch(/bukan disposisi IGD/)
    expect(pesanGalatDisposisi(new ApiError(409, 'status_tidak_sesuai', 'x'))).toMatch(/tidak lagi aktif di IGD/)
    expect(pesanGalatDisposisi(new ApiError(409, 'perintah_sudah_menunggu', 'x'))).toMatch(/masih menunggu/)
    expect(pesanGalatDisposisi(new ApiError(422, 'validasi_gagal', 'x', [{ kolom: 'alasan', pesan: 'Isi alasan.' }]))).toBeNull()
  })
})

describe('DisposisiPage: layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang(komponen: typeof DisposisiPage | typeof PendaftaranIgdPage = DisposisiPage, jalur = '/igd/disposisi') {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push(jalur)
    const wrapper = mount(komponen, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return { wrapper, router }
  }
  const tombol = (teks: RegExp) => [...document.querySelectorAll('button')].find((b) => teks.test(b.textContent?.trim() ?? '')) as HTMLButtonElement | undefined
  async function pilihPasien(nama: string) {
    const baris = [...document.querySelectorAll<HTMLElement>('tbody tr')].find((tr) => tr.textContent?.includes(nama))
    expect(baris).toBeDefined()
    baris!.click()
    await tenang()
  }
  const tujuanTampil = () => [...document.querySelectorAll('.pilihan-tujuan label')].map((l) => l.textContent?.trim())

  it('pulang: tujuan dari matriks, alasan wajib, konfirmasi menyebut pasien, lalu pasien keluar dari daftar aktif', async () => {
    gabungan.matriks.contoh.doa.status = 'nonaktif'
    await igdBaru()
    const { wrapper } = await pasang()
    expect(document.body.textContent).toContain('Belum ada pasien dipilih')
    await pilihPasien('Contoh Pasien Dua')
    // Dua penanda identitas di kepala panel.
    expect(document.querySelector('#judul-disposisi-pasien')!.textContent).toContain('Contoh Pasien Dua')
    expect(document.querySelector('#judul-disposisi-pasien + p')!.textContent).toMatch(/No\. RM [\d.]+ · lahir 2 Feb 1975/)
    // Hanya sel aktif; DOA nonaktif di matriks, konsul/order tidak ditawarkan.
    expect(tujuanTampil()).toEqual(['Pulang', 'Rawat inap', 'Kamar operasi', 'Rujuk keluar', 'Pulang atas permintaan sendiri (APS)', 'Meninggal'])
    expect(tombol(/^Simpan disposisi$/)!.disabled).toBe(true)

    await wrapper.find('input[name="tujuan-disposisi"][value="pulang"]').setValue(true)
    await tenang()
    expect(document.body.textContent).toContain('Isi alasan, minimal 5 karakter.')
    expect(tombol(/^Simpan disposisi$/)!.disabled).toBe(true)
    await wrapper.findAll('textarea')[0]!.setValue('Keluhan membaik, stabil (uji sintetis)')
    await tenang()
    expect(tombol(/^Simpan disposisi$/)!.disabled).toBe(false)
    tombol(/^Simpan disposisi$/)!.click()
    await tenang()

    const dialog = document.querySelector('[role="alertdialog"]')
    expect(dialog).not.toBeNull()
    expect(dialog!.textContent).toContain('Simpan disposisi Pulang untuk Contoh Pasien Dua?')
    expect(dialog!.textContent).toMatch(/No\. RM [\d.]+, lahir 2 Feb 1975/)
    expect(dialog!.textContent).toContain('Kunjungan IGD ditutup')
    tombol(/^Ya, simpan disposisi$/)!.click()
    await tenang()

    expect(daftarToast.value.map((t) => t.pesan).join(' | ')).toMatch(/Disposisi Pulang tersimpan untuk Contoh Pasien Dua, pukul 10\.00 WIB\. Status: Selesai\./)
    expect([...document.querySelectorAll('tbody tr')].some((tr) => tr.textContent?.includes('Contoh Pasien Dua'))).toBe(false)
    expect(document.body.textContent).toContain('Kunjungan IGD pasien ini sudah ditutup')
    // Tombol pemicu hilang: fokus pindah ke judul panel pasien, bukan body.
    expect(document.activeElement?.id).toBe('judul-disposisi-pasien')
    const riwayat = document.querySelector('.daftar-riwayat')!
    expect(riwayat.textContent).toContain('Pulang')
    expect(riwayat.textContent).toContain('Selesai')
    // Status disposisi tampil sebagai teks dan ikon, tidak hanya warna.
    expect(riwayat.querySelector('.pil svg')).not.toBeNull()
  })

  it('rawat inap: serah terima wajib; status Menunggu kamar dan pasien tetap di daftar', async () => {
    await igdBaru()
    const { wrapper } = await pasang()
    await pilihPasien('Contoh Pasien Dua')
    await wrapper.find('input[name="tujuan-disposisi"][value="rawat_inap"]').setValue(true)
    const isian = wrapper.findAll('textarea')
    await isian[0]!.setValue('Perlu observasi rawat inap (uji sintetis)')
    await tenang()
    expect(document.body.textContent).toContain('Isi catatan serah terima, minimal 5 karakter.')
    expect(tombol(/^Simpan disposisi$/)!.disabled).toBe(true)
    await isian[1]!.setValue('Infus terpasang, pantau tanda vital (uji sintetis)')
    await tenang()
    tombol(/^Simpan disposisi$/)!.click()
    await tenang()
    // Tidak menutup kunjungan: dialog biasa, bukan alertdialog.
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
    expect(document.querySelector('[role="dialog"]')!.textContent).toContain('Permintaan kamar rawat inap dibuat')
    tombol(/^Ya, simpan disposisi$/)!.click()
    await tenang()
    expect(document.querySelector('.daftar-riwayat')!.textContent).toContain('Menunggu kamar')
    expect(document.querySelector('.daftar-riwayat')!.textContent).toContain('Serah terima: Infus terpasang')
    expect([...document.querySelectorAll('tbody tr')].some((tr) => tr.textContent?.includes('Contoh Pasien Dua'))).toBe(true)
  })

  it('403 SIP tidak berlaku: pesan perintah klinis hanya dokter ber-SIP; tanpa izin input formulir tidak tampil', async () => {
    await igdBaru()
    // Lapis 5 menolak: SIP/SPK akun tidak berlaku hari ini.
    klien.defaults.adapter = adapterAsli
    pasangTiruan(klien, {
      tangani: (r) =>
        r.metode.toUpperCase() === 'POST' && r.jalur.endsWith('/disposisi')
          ? { status: 403, isi: { error: { kode: 'izin_praktik_tidak_berlaku', pesan: 'SIP tidak berlaku.', rincian: [] } } }
          : gabungan.tangani(r),
    })
    const { wrapper } = await pasang()
    await pilihPasien('Contoh Pasien Dua')
    await wrapper.find('input[name="tujuan-disposisi"][value="pulang"]').setValue(true)
    await wrapper.findAll('textarea')[0]!.setValue('Keluhan membaik (uji sintetis)')
    await tenang()
    tombol(/^Simpan disposisi$/)!.click()
    await tenang()
    tombol(/^Ya, simpan disposisi$/)!.click()
    await tenang()
    expect(document.body.textContent).toContain('Perintah klinis hanya dari dokter dengan SIP berlaku.')

    document.body.innerHTML = ''
    aturIzin(['igd.pendaftaran.lihat', 'igd.disposisi.lihat'])
    await pasang()
    await pilihPasien('Contoh Pasien Dua')
    expect(document.querySelector('input[name="tujuan-disposisi"]')).toBeNull()
    expect(document.body.textContent).toContain('Belum ada disposisi untuk kunjungan ini.')
  })

  it('tanpa igd.pendaftaran.lihat: pesan izin, daftar IGD tidak diminta', async () => {
    await igdBaru()
    aturIzin(['igd.disposisi.lihat', 'igd.disposisi.input'])
    await pasang()
    expect(document.body.textContent).toContain('Butuh izin melihat Pendaftaran IGD; minta lewat Pengaturan peran.')
    expect(document.querySelector('table')).toBeNull()
  })

  it('Pendaftaran IGD: tautan Disposisi bagi pemegang igd.disposisi.lihat membuka pasien terpilih', async () => {
    const k = await igdBaru()
    await pasang(PendaftaranIgdPage, '/igd/pendaftaran')
    const tautan = document.querySelector<HTMLAnchorElement>('tbody a[aria-label="Disposisi Contoh Pasien Dua"]')
    expect(tautan).not.toBeNull()
    expect(tautan!.getAttribute('href')).toBe(`/igd/disposisi?kunjungan=${k.id}`)

    document.body.innerHTML = ''
    await pasang(DisposisiPage, `/igd/disposisi?kunjungan=${k.id}`)
    expect(document.querySelector('#judul-disposisi-pasien')!.textContent).toBe('Disposisi Contoh Pasien Dua')

    document.body.innerHTML = ''
    aturIzin(['igd.pendaftaran.lihat'])
    await pasang(PendaftaranIgdPage, '/igd/pendaftaran')
    expect(document.querySelector('tbody a[aria-label^="Disposisi"]')).toBeNull()
  })
})
