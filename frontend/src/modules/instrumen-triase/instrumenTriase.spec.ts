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
import { buatKunjunganIgd, catatTriase, daftarInstrumenTriase, daftarTriase } from '@/modules/pendaftaran/apiKunjungan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { ambilInstrumenTriase, buatInstrumenTriase, daftarInstrumenTriaseDataInduk, ubahInstrumenTriase } from './api'
import InstrumenTriasePage from './pages/InstrumenTriasePage.vue'

/** Tiket 2.24: data induk instrumen triase (server tiruan v0.32.0) dan layar Master Data → Instrumen triase. Data sintetis. */
const adapterAsli = klien.defaults.adapter
const IZIN = ['master_data.instrumen_triase.lihat', 'master_data.instrumen_triase.input', 'master_data.instrumen_triase.perbaiki']
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

describe('instrumen triase: server tiruan kontrak v0.32.0', () => {
  it('tambah, ubah skala dengan If-Match, nonaktif tidak ditawarkan ke triase', async () => {
    const { data, etag } = await buatInstrumenTriase({ kode: 'CONTOH-B', nama: 'Instrumen contoh B', skala: [{ kode: 'B1', label: 'Tingkat B1' }], dasar_perubahan: DASAR })
    expect(data).toMatchObject({ status: 'aktif', versi: '0', skala: [{ kode: 'B1', label: 'Tingkat B1' }] })
    expect((await daftarInstrumenTriase()).map((i) => i.kode)).toEqual(['CONTOH-B', 'CONTOH-TRIASE'])

    const ubah = await ubahInstrumenTriase(data.id, etag, { skala: [{ kode: 'B2', label: 'Tingkat B2' }, { kode: 'B1', label: 'Tingkat B1' }], dasar_perubahan: DASAR })
    expect(ubah.data.skala.map((s) => s.kode)).toEqual(['B2', 'B1'])
    await expect(ubahInstrumenTriase(data.id, etag, { nama: 'Basi', dasar_perubahan: DASAR })).rejects.toMatchObject({ status: 412 })

    await ubahInstrumenTriase(data.id, (await ambilInstrumenTriase(data.id)).etag, { status: 'nonaktif', dasar_perubahan: DASAR })
    expect((await daftarInstrumenTriase()).map((i) => i.kode)).toEqual(['CONTOH-TRIASE'])
    expect((await daftarInstrumenTriaseDataInduk({ status: 'nonaktif' })).data.map((i) => i.kode)).toEqual(['CONTOH-B'])
  })

  it('ubah label skala hanya berlaku untuk triase berikutnya; riwayat memakai salinan', async () => {
    const { data, etag } = await buatInstrumenTriase({ kode: 'CONTOH-L', nama: 'Instrumen contoh L', skala: [{ kode: 'C1', label: 'Label X' }], dasar_perubahan: DASAR })
    const k = (await buatKunjunganIgd({ pasien_id: gabungan.pasien.contoh.dua.id, cara_bayar: 'non_jkn' })).data
    await catatTriase(k.id, { kategori: 'kuning', instrumen_triase_id: data.id, skala: 'C1' })
    await ubahInstrumenTriase(data.id, etag, { nama: 'Instrumen contoh L revisi', skala: [{ kode: 'C1', label: 'Label Y' }], dasar_perubahan: DASAR })
    await catatTriase(k.id, { kategori: 'merah', instrumen_triase_id: data.id, skala: 'C1' })
    const riwayat = (await daftarTriase(k.id)).data
    expect(riwayat.map((t) => [t.skala?.label, t.instrumen?.nama])).toEqual([['Label Y', 'Instrumen contoh L revisi'], ['Label X', 'Instrumen contoh L']])
  })

  it('validasi: kode skala unik, kode instrumen ganda, tanpa izin 403', async () => {
    await expect(buatInstrumenTriase({ kode: 'CONTOH-C', nama: 'Instrumen contoh C', skala: [{ kode: 'C1', label: 'A' }, { kode: 'C1', label: 'B' }], dasar_perubahan: DASAR }))
      .rejects.toMatchObject({ status: 422, rincian: [{ kolom: 'skala.1.kode' }] })
    await expect(buatInstrumenTriase({ kode: 'CONTOH-TRIASE', nama: 'Ganda', skala: [], dasar_perubahan: DASAR })).rejects.toMatchObject({ rincian: [{ kolom: 'kode' }] })
    pasangTiruan(klien, buatServerGabungan({ tanpaDataInduk: true }))
    await expect(daftarInstrumenTriaseDataInduk({})).rejects.toMatchObject({ status: 403 })
  })
})

describe('InstrumenTriasePage: layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/master-data/instrumen-triase')
    const wrapper = mount(InstrumenTriasePage, {
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
    const lbl = [...document.querySelectorAll('[role="dialog"] label')].find((l) => l.textContent?.replace('*', '').trim() === label)
    return document.getElementById(lbl!.getAttribute('for')!)
  }

  it('tambah instrumen dengan dua tingkat skala, lalu tampil di daftar', async () => {
    await pasang()
    expect(document.querySelector('table')!.textContent).toContain('CONTOH-TRIASE')
    tombol('Tambah instrumen')!.click()
    await tenang()
    await isi(kolom('Kode'), 'CONTOH-D')
    await isi(kolom('Nama instrumen'), 'Instrumen contoh D')
    tombol('Tambah tingkat')!.click()
    await tenang()
    tombol('Tambah tingkat')!.click()
    await tenang()
    await isi(kolom('Kode tingkat 1'), 'D1')
    await isi(kolom('Label tingkat 1'), 'Tingkat D1')
    await isi(kolom('Kode tingkat 2'), 'D1')
    await isi(kolom('Label tingkat 2'), 'Tingkat D2')
    await isi(kolom('Dasar perubahan'), DASAR)
    const dialog = document.querySelector('[role="dialog"]')!
    tombol('Tambah instrumen', dialog)!.click()
    await tenang()
    // Kode skala ganda ditandai di kolomnya sebelum dikirim.
    expect(dialog.textContent).toContain('Kode skala sudah dipakai di instrumen ini.')
    await isi(kolom('Kode tingkat 2'), 'D2')
    tombol('Tambah instrumen', dialog)!.click()
    await tenang()

    expect(document.querySelector('[role="dialog"]')).toBeNull()
    const baris = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent?.includes('CONTOH-D'))!
    expect(baris.textContent).toContain('Tingkat D1, Tingkat D2')
    expect(baris.textContent).toContain('Aktif')
  })

  it('lihat saja: tanpa tombol tambah dan kolom aksi', async () => {
    aturIzin(['master_data.instrumen_triase.lihat'])
    await pasang()
    // v-can menyembunyikan tombol (atribut hidden).
    expect(tombol('Tambah instrumen')?.hidden ?? true).toBe(true)
    expect([...document.querySelectorAll('thead th')].map((th) => th.textContent?.trim())).not.toContain('Aksi')
  })
})
