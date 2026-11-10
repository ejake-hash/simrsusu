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
import { bacaPapanIgd } from '@/modules/igd/apiPapan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { ambilAturanPapanIgd, buatAreaIgd, daftarAreaIgd, etagArea, ubahAreaIgd, ubahAturanPapanIgd } from './api'
import AreaPapanIgdPage from './pages/AreaPapanIgdPage.vue'

/** Tiket 2.25: data induk area dan batas papan IGD (server tiruan v0.34.0) dan layar Master Data → Area & batas papan IGD. Data sintetis. */
const adapterAsli = klien.defaults.adapter
const IZIN = ['master_data.papan_igd.lihat', 'master_data.papan_igd.input', 'master_data.papan_igd.perbaiki']
const DASAR = 'SK komite contoh'

let gabungan: ReturnType<typeof buatServerGabungan>

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => new Date('2026-11-02T03:00:00Z') })
  pasangTiruan(klien, gabungan)
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.data.induk', mfa_aktif: true })
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

const metaPapan = async () => {
  const r = await bacaPapanIgd(null)
  if (!r.berubah) throw new Error('papan tidak berubah')
  return r.data.meta
}

describe('data induk papan IGD: server tiruan kontrak v0.34.0', () => {
  it('tambah area, ubah urutan dengan If-Match (412 bila basi), nonaktif tidak ditawarkan di papan', async () => {
    const { data } = await buatAreaIgd({ kode: 'CONTOH-TRIASE-AREA', nama: 'Area triase (contoh)', urutan: 0, dasar_perubahan: DASAR })
    expect(data).toMatchObject({ status: 'aktif', versi: '0', urutan: 0 })
    expect((await metaPapan()).area.map((a) => a.kode)[0]).toBe('CONTOH-TRIASE-AREA')

    const ubah = await ubahAreaIgd(data.id, etagArea(data), { urutan: 9, dasar_perubahan: DASAR })
    expect(ubah.data.urutan).toBe(9)
    await expect(ubahAreaIgd(data.id, etagArea(data), { nama: 'Basi', dasar_perubahan: DASAR })).rejects.toMatchObject({ status: 412 })
    await expect(ubahAreaIgd(data.id, '', { nama: 'Tanpa versi', dasar_perubahan: DASAR })).rejects.toMatchObject({ status: 428 })

    await ubahAreaIgd(data.id, etagArea(ubah.data), { status: 'nonaktif', dasar_perubahan: DASAR })
    expect((await metaPapan()).area.map((a) => a.kode)).not.toContain('CONTOH-TRIASE-AREA')
    expect((await daftarAreaIgd('nonaktif')).data.map((a) => a.kode)).toEqual(['CONTOH-TRIASE-AREA'])
  })

  it('aturan: ambang boleh dikosongkan (null), batas tanggap 1 sampai 1.440, dasar wajib, kode area ganda ditolak', async () => {
    const awal = await ambilAturanPapanIgd()
    expect(awal.data).toMatchObject({ batas_tanggap_menit: 5, peringatan_lama_igd_menit: null })
    const isi = await ubahAturanPapanIgd(awal.etag, { batas_tanggap_menit: 10, peringatan_lama_igd_menit: 45, dasar_perubahan: DASAR })
    expect((await metaPapan()).aturan).toMatchObject({ batas_tanggap_menit: 10, peringatan_lama_igd_menit: 45 })
    const kosong = await ubahAturanPapanIgd(isi.etag, { peringatan_lama_igd_menit: null, dasar_perubahan: DASAR })
    expect(kosong.data.peringatan_lama_igd_menit).toBeNull()
    await expect(ubahAturanPapanIgd(kosong.etag, { batas_tanggap_menit: 0, dasar_perubahan: DASAR })).rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'batas_tanggap_menit' }] })
    await expect(ubahAturanPapanIgd(kosong.etag, { batas_tanggap_menit: 7, dasar_perubahan: 'x' })).rejects.toMatchObject({ rincian: [{ kolom: 'dasar_perubahan' }] })
    await expect(buatAreaIgd({ kode: 'CONTOH-HIJAU', nama: 'Ganda', dasar_perubahan: DASAR })).rejects.toMatchObject({ rincian: [{ kolom: 'kode' }] })
    pasangTiruan(klien, buatServerGabungan({ tanpaDataInduk: true }))
    await expect(daftarAreaIgd(null)).rejects.toMatchObject({ status: 403 })
  })
})

describe('AreaPapanIgdPage: layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/master-data/papan-igd')
    const wrapper = mount(AreaPapanIgdPage, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return wrapper
  }
  const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
  const isi = async (el: Element | null, nilai: string) => {
    const input = el as HTMLInputElement
    input.value = nilai
    input.dispatchEvent(new Event('input'))
    await tenang()
  }
  const kolom = (label: string) => {
    const lbl = [...document.querySelectorAll('[role="dialog"] label, [role="alertdialog"] label')].find((l) => l.textContent?.replace('*', '').trim() === label)
    return document.getElementById(lbl!.getAttribute('for')!)
  }
  const dialog = () => document.querySelector('[role="dialog"], [role="alertdialog"]')!

  it('batas waktu: ambang kosong tertulis Tidak dipakai; diisi lalu dikosongkan lagi dengan dasar', async () => {
    await pasang()
    const batas = document.querySelector('dl.batas')!
    expect(batas.textContent).toContain('5 menit')
    expect(batas.textContent).toContain('Tidak dipakai')
    tombol('Ubah batas waktu')!.click()
    await tenang()
    await isi(kolom('Tanda mendekati 6 jam (menit sebelum 6 jam)'), '400')
    await isi(kolom('Dasar perubahan'), DASAR)
    tombol('Simpan', dialog())!.click()
    await tenang()
    expect(dialog().textContent).toContain('Isi bilangan bulat 1 sampai 359 menit, atau kosongkan.')
    await isi(kolom('Tanda mendekati 6 jam (menit sebelum 6 jam)'), '60')
    tombol('Simpan', dialog())!.click()
    await tenang()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.querySelector('dl.batas')!.textContent).toContain('1 jam sebelum 6 jam')

    tombol('Ubah batas waktu')!.click()
    await tenang()
    await isi(kolom('Tanda mendekati 6 jam (menit sebelum 6 jam)'), '')
    await isi(kolom('Dasar perubahan'), DASAR)
    tombol('Simpan', dialog())!.click()
    await tenang()
    expect(document.querySelector('dl.batas')!.textContent).toContain('Tidak dipakai')
  })

  it('tambah area lalu nonaktifkan dengan dasar; 412 menawarkan muat ulang', async () => {
    await pasang()
    expect(document.querySelector('table')!.textContent).toContain('CONTOH-RESUSITASI')
    tombol('Tambah area')!.click()
    await tenang()
    await isi(kolom('Kode'), 'contoh-zona')
    await isi(kolom('Nama area'), 'Zona contoh')
    await isi(kolom('Urutan tampil'), '4')
    await isi(kolom('Dasar perubahan'), DASAR)
    tombol('Tambah area', dialog())!.click()
    await tenang()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    const baris = () => [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent?.includes('CONTOH-ZONA'))
    expect(baris()!.textContent).toContain('Zona contoh')
    expect(baris()!.textContent).toContain('Aktif')

    // Versi berubah di tempat lain sejak daftar dimuat → 412.
    const area = (await daftarAreaIgd('aktif')).data.find((a) => a.kode === 'CONTOH-ZONA')!
    await ubahAreaIgd(area.id, etagArea(area), { nama: 'Zona contoh ubah', dasar_perubahan: DASAR })
    baris()!.querySelector<HTMLButtonElement>('button[aria-label="Nonaktifkan Zona contoh"]')!.click()
    await tenang()
    await isi(kolom('Dasar perubahan'), 'Zona ditutup (uji)')
    tombol('Nonaktifkan', dialog())!.click()
    await tenang()
    expect(dialog().textContent).toContain('Area ini diubah orang lain sejak daftar dimuat.')
    tombol('Muat ulang', dialog())!.click()
    await tenang()

    baris()!.querySelector<HTMLButtonElement>('button[aria-label="Nonaktifkan Zona contoh ubah"]')!.click()
    await tenang()
    expect(dialog().textContent).toContain('tidak bisa dipilih lagi untuk penempatan baru')
    await isi(kolom('Dasar perubahan'), 'Zona ditutup (uji)')
    tombol('Nonaktifkan', dialog())!.click()
    await tenang()
    expect(baris()).toBeUndefined()
  })

  it('lihat saja: tanpa tombol tambah, ubah batas, dan kolom aksi', async () => {
    aturIzin(['master_data.papan_igd.lihat'])
    await pasang()
    expect(tombol('Tambah area')?.hidden ?? true).toBe(true)
    expect(tombol('Ubah batas waktu')).toBeUndefined()
    expect([...document.querySelectorAll('thead th')].map((th) => th.textContent?.trim())).not.toContain('Aksi')
  })
})
