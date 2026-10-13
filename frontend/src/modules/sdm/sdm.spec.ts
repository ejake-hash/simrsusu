import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import RincianAkun from '@/modules/akses/components/pengaturan/RincianAkun.vue'
import { ambilKsm, daftarKsm } from '@/modules/organisasi/api'
import DialogKsm from '@/modules/organisasi/components/DialogKsm.vue'
import GantiSandiPage from '@/modules/akses/pages/GantiSandiPage.vue'
import { uid } from '@/modules/akses/tiruan/dataPengaturan'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, SANDI_TIRUAN, type PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { aturPenanganWajibGantiSandi, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import DaftarPegawai from './components/DaftarPegawai.vue'
import RincianPegawai from './components/RincianPegawai.vue'
import { hariIniLokal } from './sdm'

const adapterAsli = klien.defaults.adapter
let server: ReturnType<typeof buatServerGabungan>
let router: Router
let permintaan: PermintaanTiruan[]
const IZIN_SDM = ['lihat', 'input', 'perbaiki'].flatMap((a) => [`sdm.pegawai.${a}`, `sdm.kredensial.${a}`])

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan())
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  aturIzin([...IZIN_SDM, 'master_data.organisasi.lihat', 'pengaturan.akun.lihat', 'pengaturan.akun.input', 'pengaturan.akun.perbaiki'])
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any, props: Record<string, unknown> = {}, jalur = '/') {
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', name: 'semua', component: { render: () => null } }, { path: '/ganti-sandi', name: 'ganti-sandi', component: { render: () => null } }] })
  await router.push(jalur)
  const w = mount(komponen, {
    props,
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
  return w
}
async function tenang(ms = 10) {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, ms))
  }
}
const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
const klik = async (el: HTMLElement | null | undefined) => {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
const dialog = () => document.querySelector('[role="dialog"], [role="alertdialog"]') as HTMLElement | null
function isian(label: string): HTMLInputElement {
  const lbl = [...document.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
async function isi(label: string, nilai: string) {
  const el = isian(label)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await tenang()
}
const kirim = (form: string) => klik(document.querySelector<HTMLButtonElement>(`button[form="${form}"]`))
const rincian = () => document.querySelector('section[aria-labelledby="judul-rincian-pegawai"]') as HTMLElement

describe('Daftar pegawai', () => {
  it('daftar dari server (terbaru dulu), NIK tidak tampil; filter status keluar; memilih baris', async () => {
    const w = await pasang(DaftarPegawai, { modelValue: null })
    const baris = [...document.querySelectorAll('tbody tr')]
    expect(baris.length).toBeGreaterThanOrEqual(8)
    expect(baris[0]!.textContent).toContain('Contoh Pegawai Keluar')
    expect(document.body.textContent).not.toMatch(/1271\s?0000/)
    const status = document.querySelector('select') as HTMLSelectElement
    status.value = 'keluar'
    status.dispatchEvent(new Event('change'))
    await tenang()
    expect([...document.querySelectorAll('tbody tr')].map((t) => t.querySelector('b')?.textContent)).toEqual(['Contoh Pegawai Keluar'])
    expect(permintaan.some((p) => p.jalur === '/pegawai' && p.kueri.get('status') === 'keluar')).toBe(true)
    ;(document.querySelector('tbody tr') as HTMLElement).click()
    await tenang()
    expect(w.emitted('update:modelValue')?.[0]).toEqual([server.sdm.contoh.keluar])
  })
})

describe('Rincian pegawai', () => {
  it('NIK tersamar; tanpa izin identitas tidak ada tombol Tampilkan NIK; izin praktik terbaru per jenis', async () => {
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.perawat })
    expect(rincian().textContent).toContain('1271 •••• •••• 0043')
    expect(tombol('Tampilkan NIK', rincian())).toBeUndefined()
    // SIP lama habis tetapi SIP baru berlaku: riwayat tetap tampil.
    expect(rincian().textContent).toContain('SIP-SINT-0102-LAMA')
    expect(rincian().querySelector('[aria-labelledby="judul-izin-praktik"]')?.textContent).toContain('Habis')
  })

  it('dengan izin identitas: Tampilkan NIK meminta tampilkan_nik=true, NIK utuh tidak masuk cache dan disembunyikan lagi', async () => {
    server.akses.tambahPenugasanDev('contoh_sdm_identitas')
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.dokter })
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      await klik(tombol('Tampilkan NIK', rincian()))
      expect(permintaan.some((p) => p.kueri.get('tampilkan_nik') === 'true')).toBe(true)
      expect(rincian().textContent).toContain('1271000000000042')
      vi.advanceTimersByTime(31_000)
      await tenang()
      expect(rincian().textContent).not.toContain('1271000000000042')
      expect(rincian().textContent).toContain('1271 •••• •••• 0042')
    } finally {
      vi.useRealTimers()
    }
  })

  it('catat izin praktik: SIP tanpa tanggal berakhir ditolak di klien; STR seumur hidup tercatat; dokumen > 5 MB ditolak', async () => {
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.bidan })
    await klik(tombol('Catat izin', rincian()))
    await isi('Jenis izin', 'sip')
    await isi('Nomor', 'SIP-SINT-BIDAN')
    await isi('Tanggal terbit', '2026-01-02')
    const berkas = isian('Dokumen (opsional)')
    Object.defineProperty(berkas, 'files', { value: [new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'besar.pdf', { type: 'application/pdf' })], configurable: true })
    berkas.dispatchEvent(new Event('change'))
    await tenang()
    await kirim('form-izin-praktik')
    expect(dialog()?.textContent).toContain('Isi tanggal berlaku sampai.')
    expect(dialog()?.textContent).toContain('paling besar 5 MB')
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur.endsWith('/izin-praktik'))).toBe(false)

    Object.defineProperty(berkas, 'files', { value: [], configurable: true })
    berkas.dispatchEvent(new Event('change'))
    await isi('Jenis izin', 'str')
    ;(document.querySelector('input[type="checkbox"]') as HTMLInputElement).click()
    await tenang()
    await kirim('form-izin-praktik')
    const post = permintaan.find((p) => p.metode === 'post' && p.jalur.endsWith('/izin-praktik'))!
    expect(post.isi).toBeInstanceOf(FormData)
    expect((post.isi as FormData).get('berlaku_sampai')).toBe('')
    expect(dialog()).toBeNull()
    expect(rincian().textContent).toContain('SIP-SINT-BIDAN')
    expect(rincian().textContent).toContain('seumur hidup')
  })

  it('batalkan izin praktik dengan alasan; catatan tetap sebagai riwayat', async () => {
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.apoteker })
    await klik(rincian().querySelector<HTMLButtonElement>('button[aria-label="Batalkan SIP SIP-SINT-0201"]'))
    await kirim('form-batal-sdm')
    expect(dialog()?.textContent).toContain('minimal 5 karakter')
    await isi('Alasan pembatalan', 'Nomor salah ketik')
    await kirim('form-batal-sdm')
    expect(dialog()).toBeNull()
    const item = [...rincian().querySelectorAll('li')].find((l) => l.textContent?.includes('SIP-SINT-0201'))!
    expect(item.textContent).toContain('Dibatalkan')
    expect(item.textContent).toContain('Nomor salah ketik')
  })

  it('RKK: kode tindakan berbentuk salah ditolak di klien; kode dipisah koma/baris tercatat tanpa duplikat', async () => {
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.perawat })
    await klik(tombol('Catat RKK', rincian()))
    await isi('Kategori', 'Keperawatan dasar')
    await isi('Kode tindakan', '93.57, 93.57\n96.6 kode#salah')
    await isi('Berlaku dari', '2026-10-01')
    await isi('Berlaku sampai', '2027-09-30')
    await isi('Komite', 'Komite Keperawatan')
    await kirim('form-kewenangan')
    expect(dialog()?.textContent).toContain('kode#salah')
    await isi('Kode tindakan', '93.57, 93.57\n96.6')
    await kirim('form-kewenangan')
    const post = permintaan.find((p) => p.metode === 'post' && p.jalur.endsWith('/kewenangan-klinis'))!
    expect((post.isi as { kode_tindakan: string[] }).kode_tindakan).toEqual(['93.57', '96.6'])
    expect(rincian().textContent).toContain('Keperawatan dasar')
  })

  it('catat keluar hari ini → status keluar dan akun nonaktif; superadmin → 409 superadmin_aktif tampil di dialog', async () => {
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.dokter })
    await klik(tombol('Catat keluar', rincian()))
    await isi('Tanggal keluar', hariIniLokal())
    expect(dialog()?.textContent).toContain('akun langsung dinonaktifkan')
    await isi('Alasan keluar', 'Pindah tugas (sintetis)')
    await isi('Dasar perubahan', 'SK Direktur 30/2026')
    await kirim('form-keluar')
    const patch = permintaan.find((p) => p.metode === 'patch')!
    expect(patch.header['if-match']).toBeTruthy()
    expect(patch.isi).toEqual({ tanggal_keluar: hariIniLokal(), alasan_keluar: 'Pindah tugas (sintetis)', dasar_perubahan: 'SK Direktur 30/2026' })
    expect(rincian().querySelector('.kartu-kepala .pil')?.textContent).toBe('Keluar')
    expect(rincian().textContent).toContain('Akunnya nonaktif.')
    expect(rincian().querySelector('[aria-labelledby="judul-akun-pegawai"]')?.textContent).toContain('Nonaktif')

    document.body.innerHTML = ''
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.superadmin })
    await klik(tombol('Catat keluar', rincian()))
    await isi('Tanggal keluar', hariIniLokal())
    await isi('Alasan keluar', 'Uji superadmin (sintetis)')
    await isi('Dasar perubahan', 'SK Direktur 31/2026')
    await kirim('form-keluar')
    expect(dialog()?.querySelector('[role="alert"]')?.textContent).toContain('Superadmin')
  })

  it('buat akun: sandi awal tampil sekali (tersembunyi bawaan), hilang saat dialog ditutup; tombol Buat akun lalu hilang', async () => {
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.bidan })
    await klik(tombol('Buat akun', rincian()))
    expect(isian('Nama pengguna').value).toBe('contoh.bidan.baru')
    await isi('Dasar pemberian', 'Surat permintaan akun 01/2026')
    await kirim('form-buat-akun')
    expect(dialog()?.querySelector('h2')?.textContent).toBe('Akun dibuat')
    expect(dialog()!.querySelector('code.sandi')).toBeNull()
    expect(dialog()!.querySelector('.sandi')?.textContent).toMatch(/^•+Disembunyikan$/)
    await klik(tombol('Tampilkan', dialog()!))
    const sandi = dialog()!.querySelector('code.sandi')!.textContent!
    expect(sandi).toMatch(/^[A-Za-z0-9-]{16,}$/)
    await klik(tombol('Selesai', dialog()!))
    expect(document.body.textContent).not.toContain(sandi)
    expect(tombol('Buat akun', rincian())).toBeUndefined()
    expect(rincian().textContent).toContain('contoh.bidan.baru')
  })
})

describe('Buat akun saat muat ulang gagal', () => {
  it('rincian gagal dimuat ulang sesudah akun dibuat: dialog sandi awal tetap ada, data lama tetap tampil dengan peringatan', async () => {
    let gagal = false
    const tangani = server.tangani
    server.tangani = (r: PermintaanTiruan) => {
      if (gagal && r.metode === 'get' && r.jalur === `/pegawai/${server.sdm.contoh.bidan}`) return { status: 500, isi: { error: { kode: 'kesalahan_server', pesan: 'Galat server.', rincian: [] } } }
      const h = tangani(r)
      if (r.metode === 'post' && r.jalur.endsWith('/akun')) gagal = true
      return h
    }
    await pasang(RincianPegawai, { pegawaiId: server.sdm.contoh.bidan })
    await klik(tombol('Buat akun', rincian()))
    await isi('Dasar pemberian', 'Surat permintaan akun 02/2026')
    await kirim('form-buat-akun')
    await tenang(50)
    expect(dialog()?.querySelector('h2')?.textContent).toBe('Akun dibuat')
    await klik(tombol('Tampilkan', dialog()!))
    expect(dialog()!.querySelector('code.sandi')?.textContent).toMatch(/^[A-Za-z0-9-]{16,}$/)
    expect(rincian().querySelector('h2')?.textContent).toBe('Contoh Bidan Baru')
    expect(rincian().textContent).toContain('Gagal memuat ulang')
  })
})

describe('Ganti sandi', () => {
  it('dipaksa: endpoint lain 403 wajib_ganti_sandi; sandi lama salah dan sandi memuat nama pengguna ditolak; berhasil → kembali ke rute asal', async () => {
    server.akses.wajibkanGantiSandi()
    useSesiStore().pengguna = { ...useSesiStore().pengguna!, wajib_ganti_sandi: true }
    const penangan = vi.fn()
    aturPenanganWajibGantiSandi(penangan)
    await expect(klien.get('/pegawai')).rejects.toMatchObject({ status: 403, kode: 'wajib_ganti_sandi' })
    expect(penangan).toHaveBeenCalledOnce()

    await pasang(GantiSandiPage, {}, '/ganti-sandi?kembali=/sdm/pegawai')
    expect(document.querySelector('h1')?.textContent).toBe('Buat sandi Anda sendiri')
    await isi('Sandi dari admin', 'salah-sekali')
    await isi('Sandi baru', 'contoh.superadmin-panjang')
    await isi('Ulangi sandi baru', 'contoh.superadmin-panjang')
    await klik(document.querySelector<HTMLButtonElement>('button[type="submit"]'))
    expect(document.body.textContent).toContain('Sandi awal salah.')

    await isi('Sandi dari admin', SANDI_TIRUAN)
    await klik(document.querySelector<HTMLButtonElement>('button[type="submit"]'))
    expect(document.body.textContent).toContain('tidak boleh memuat nama pengguna')

    await isi('Sandi baru', 'kuda-biru-melompat-pagar')
    await isi('Ulangi sandi baru', 'kuda-biru-melompat-pagi')
    await klik(document.querySelector<HTMLButtonElement>('button[type="submit"]'))
    expect(document.body.textContent).toContain('Pengulangan tidak sama')

    await isi('Ulangi sandi baru', 'kuda-biru-melompat-pagar')
    await klik(document.querySelector<HTMLButtonElement>('button[type="submit"]'))
    expect(useSesiStore().pengguna?.wajib_ganti_sandi).toBe(false)
    expect(router.currentRoute.value.fullPath).toBe('/sdm/pegawai')
    await expect(klien.get('/pegawai')).resolves.toBeTruthy()
    aturPenanganWajibGantiSandi(() => {})
  })
})

describe('Nonaktif/aktif akun (Pengaturan → Akun & akses)', () => {
  it('nonaktifkan dengan alasan; mengaktifkan akun pegawai yang sudah keluar → 409 pegawai_keluar tampil di dialog', async () => {
    await pasang(RincianAkun, { akunId: uid(3), indeks: null })
    await klik(tombol('Nonaktifkan akun'))
    await kirim('form-status-akun')
    expect(dialog()?.textContent).toContain('minimal 5 karakter')
    await isi('Alasan', 'Cuti di luar tanggungan (sintetis)')
    await kirim('form-status-akun')
    expect(permintaan.find((p) => p.jalur.endsWith('/nonaktifkan'))?.isi).toEqual({ alasan: 'Cuti di luar tanggungan (sintetis)' })
    expect(document.body.textContent).toContain('dinonaktifkan; sesinya diakhiri')
    expect(tombol('Aktifkan akun')).toBeTruthy()

    // Pegawai contoh.dokter dicatat keluar lewat API, lalu akunnya coba diaktifkan.
    document.body.innerHTML = ''
    const { ambilPegawai, ubahPegawai } = await import('./api')
    const p = await ambilPegawai(server.sdm.contoh.dokter)
    await ubahPegawai(p.data.id, p.etag, { tanggal_keluar: hariIniLokal(), alasan_keluar: 'Pindah tugas (sintetis)', dasar_perubahan: 'SK Direktur 32/2026' })
    await pasang(RincianAkun, { akunId: uid(4), indeks: null })
    await klik(tombol('Aktifkan akun'))
    await isi('Alasan', 'Salah nonaktif (sintetis)')
    await kirim('form-status-akun')
    expect(dialog()?.querySelector('[role="alert"]')?.textContent).toContain('sudah keluar')
  })

  it('akun sendiri tidak punya tombol nonaktif/aktif', async () => {
    await pasang(RincianAkun, { akunId: ID_SUPERADMIN, indeks: null })
    expect(tombol('Nonaktifkan akun')).toBeUndefined()
  })
})

describe('Ketua KSM dari pegawai (Organisasi)', () => {
  it('pemilih menawarkan pegawai yang belum keluar; PATCH mengirim ketua_pegawai_id; tanpa sdm.pegawai.lihat kolomnya tidak dirender', async () => {
    const ksm = (await daftarKsm({ cari: 'bedah' })).data[0]!
    const w = await pasang(DialogKsm, { ksm: await ambilKsm(ksm.id), open: false })
    await w.setProps({ open: true })
    await tenang()
    const pemilih = isian('Ketua KSM')
    pemilih.focus()
    pemilih.value = 'contoh'
    pemilih.dispatchEvent(new Event('input'))
    await tenang(300)
    const opsi = [...document.querySelectorAll('[role="option"]')].map((o) => o.textContent ?? '')
    expect(opsi.some((o) => o.includes('Contoh Dokter'))).toBe(true)
    expect(opsi.some((o) => o.includes('Contoh Pegawai Keluar'))).toBe(false)
    const dokter = [...document.querySelectorAll<HTMLElement>('[role="option"]')].find((o) => o.textContent?.includes('Contoh Dokter'))!
    dokter.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await tenang()
    await isi('Dasar perubahan', 'SK Komite Medik 05/2026')
    await kirim('form-ksm')
    const patch = permintaan.find((p) => p.metode === 'patch' && p.jalur.startsWith('/ksm/'))!
    expect(patch.isi).toEqual({ ketua_pegawai_id: server.sdm.contoh.dokter, dasar_perubahan: 'SK Komite Medik 05/2026' })
    expect((w.emitted('selesai')?.[0]?.[0] as { data: { ketua: unknown } }).data.ketua).toEqual({ id: server.sdm.contoh.dokter, nama: 'Contoh Dokter', status: 'aktif' })

    document.body.innerHTML = ''
    aturIzin(['master_data.organisasi.lihat', 'master_data.organisasi.perbaiki'])
    const w2 = await pasang(DialogKsm, { ksm: await ambilKsm(ksm.id), open: false })
    await w2.setProps({ open: true })
    await tenang()
    expect([...document.querySelectorAll('label')].some((l) => l.textContent?.includes('Ketua KSM'))).toBe(false)
  })
})
