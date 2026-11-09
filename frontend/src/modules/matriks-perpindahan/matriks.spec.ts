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
import type { PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { daftarMatriks } from './api'
import { bentukButirMatriks, keteranganModulLain, type DrafButirMatriks } from './matriks'
import MatriksPerpindahanPage from './pages/MatriksPerpindahanPage.vue'

/** Tiket 2.26: layar Master Data → Matriks perpindahan. Perubahan hanya lewat usulan data induk; data sintetis. */
const adapterAsli = klien.defaults.adapter
let gabungan: ReturnType<typeof buatServerGabungan>
let permintaan: PermintaanTiruan[] = []
const IZIN = ['master_data.matriks_perpindahan.lihat', 'master_data.usulan.lihat', 'master_data.usulan.input']

beforeEach(() => {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan({ sekarang: () => new Date('2026-11-02T03:00:00Z') })
  permintaan = []
  pasangTiruan(klien, {
    tangani: (r) => {
      permintaan.push(r)
      return gabungan.tangani(r)
    },
  })
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'data.induk', mfa_aktif: true })
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

const draf = (x: Partial<DrafButirMatriks>): DrafButirMatriks => ({ aksi: 'ubah', pos_asal: '', pos_tujuan: '', izin_perintah: '', keterangan: '', status: 'aktif', alasan_tutup: '', ...x })

describe('butir usulan matriks perpindahan', () => {
  it('ubah hanya membawa isian yang berbeda; tutup butuh alasan; tambah memeriksa kode izin', () => {
    const sel = gabungan.matriks.contoh.pulang
    expect(bentukButirMatriks(draf({ izin_perintah: sel.izin_perintah, keterangan: sel.keterangan ?? '' }), sel)).toEqual({ aksi: 'Belum ada yang diubah dari sel ini.' })
    expect(bentukButirMatriks(draf({ izin_perintah: sel.izin_perintah, keterangan: sel.keterangan ?? '', status: 'nonaktif' }), sel)).toEqual({
      objek_jenis: 'aturan_perpindahan', aksi: 'ubah', objek_id: sel.id, nilai_baru: { status: 'nonaktif' },
    })
    expect(bentukButirMatriks(draf({ aksi: 'tutup', alasan_tutup: 'abc' }), sel)).toEqual({ alasan_tutup: 'Isi alasan penutupan, minimal 5 karakter.' })
    expect(bentukButirMatriks(draf({ aksi: 'tutup', alasan_tutup: 'Tidak dipakai RS (uji)' }), sel)).toMatchObject({ aksi: 'tutup', objek_id: sel.id, nilai_baru: { alasan: 'Tidak dipakai RS (uji)' } })
    expect(bentukButirMatriks(draf({ aksi: 'tambah', pos_asal: 'rawat_inap', pos_tujuan: 'pulang', izin_perintah: 'Bukan Kode' }), null)).toHaveProperty('izin_perintah')
    expect(bentukButirMatriks(draf({ aksi: 'tambah', pos_asal: 'rawat_inap', pos_tujuan: 'pulang', izin_perintah: 'rawat_inap.pemulangan.input' }), null)).toEqual({
      objek_jenis: 'aturan_perpindahan', aksi: 'tambah', objek_id: null, nilai_baru: { pos_asal: 'rawat_inap', pos_tujuan: 'pulang', izin_perintah: 'rawat_inap.pemulangan.input', keterangan: null },
    })
  })

  it('keterangan modul lain hanya untuk sel IGD yang perintahnya bukan disposisi', () => {
    expect(keteranganModulLain({ pos_asal: 'igd', pos_tujuan: 'penunjang' })).toMatch(/order penunjang/)
    expect(keteranganModulLain({ pos_asal: 'igd', pos_tujuan: 'icu_hcu' })).toMatch(/rawat inap/)
    expect(keteranganModulLain({ pos_asal: 'igd', pos_tujuan: 'poli_lain' })).toMatch(/konsul/)
    expect(keteranganModulLain({ pos_asal: 'igd', pos_tujuan: 'pulang' })).toBeNull()
    expect(keteranganModulLain({ pos_asal: 'poliklinik', pos_tujuan: 'penunjang' })).toBeNull()
  })
})

describe('MatriksPerpindahanPage: layar', () => {
  async function tenang(ms = 10) {
    for (let i = 0; i < 6; i++) {
      await flushPromises()
      await new Promise((r) => setTimeout(r, ms))
    }
  }
  async function pasang() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/master-data/matriks-perpindahan')
    const wrapper = mount(MatriksPerpindahanPage, {
      attachTo: document.body,
      global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
    })
    await tenang()
    return { wrapper, router }
  }
  const tombol = (teks: RegExp) => [...document.querySelectorAll('button')].find((b) => teks.test(b.textContent?.trim() ?? '')) as HTMLButtonElement | undefined
  const baris = (teks: string) => [...document.querySelectorAll<HTMLElement>('tbody tr')].find((tr) => tr.textContent?.includes(teks))

  it('daftar sel dengan izin dan status berteks; sel non-disposisi IGD diberi keterangan; tanpa tombol tulis langsung', async () => {
    await pasang()
    const penunjang = [...document.querySelectorAll<HTMLElement>('tbody tr')].find((tr) => /IGD\s*→?\s*(ke)?\s*Penunjang/.test(tr.textContent ?? ''))!
    expect(penunjang.textContent).toContain('Perintah lewat order penunjang, bukan layar Disposisi.')
    const pulang = baris('PRD 7.00.3: SPRI dokter poli')!
    expect(pulang.textContent).toContain('rawat_jalan.pemeriksaan.sahkan')
    expect(pulang.textContent).toContain('Aktif')
    // Tidak ada ubah/nonaktifkan langsung: hanya usulan.
    expect(tombol(/^(Ubah|Nonaktifkan|Simpan)$/)).toBeUndefined()
    expect(tombol(/Usulkan sel baru/)).toBeDefined()
    expect(document.querySelectorAll('button[aria-label^="Usulkan perubahan sel"]').length).toBeGreaterThan(5)
  })

  it('tutup sel lewat usulan: POST /usulan-data-induk jenis matriks_perpindahan; sel tetap aktif sampai usulan berlaku', async () => {
    const doa = gabungan.matriks.contoh.doa
    const { wrapper, router } = await pasang()
    document.querySelector<HTMLButtonElement>('button[aria-label="Usulkan perubahan sel IGD → DOA"]')!.click()
    await tenang()
    const dialog = () => document.querySelector('[role="dialog"], [role="alertdialog"]')!
    expect(dialog().textContent).toContain('Usulkan perubahan sel IGD → DOA')
    const pilihJenis = dialog().querySelector('select')!
    pilihJenis.value = 'tutup'
    pilihJenis.dispatchEvent(new Event('change'))
    await tenang()
    // Menutup sel = aksi berisiko: dialog peringatan.
    expect(document.querySelector('[role="alertdialog"]')).not.toBeNull()
    const isi = async (label: string, nilai: string) => {
      const l = [...dialog().querySelectorAll('label')].find((x) => x.textContent?.replace('*', '').trim() === label)!
      const el = document.getElementById(l.htmlFor) as HTMLInputElement | HTMLTextAreaElement
      el.value = nilai
      el.dispatchEvent(new Event('input'))
      await tenang(1)
    }
    await isi('Alasan penutupan', 'DOA dicatat lewat alur meninggal (uji)')
    await isi('Dasar', 'Memo komite uji')
    await isi('Alasan usulan', 'Penyesuaian alur RS (uji sintetis)')
    tombol(/^Ajukan usulan$/)!.click()
    await tenang()

    const post = permintaan.find((r) => r.metode.toUpperCase() === 'POST' && r.jalur === '/usulan-data-induk')!
    expect(post.isi).toMatchObject({ jenis_data: 'matriks_perpindahan', perubahan: [{ objek_jenis: 'aturan_perpindahan', aksi: 'tutup', objek_id: doa.id, nilai_baru: { alasan: 'DOA dicatat lewat alur meninggal (uji)' } }] })
    expect(daftarToast.value.map((t) => t.pesan).join(' | ')).toMatch(/UDI-2026-\d+ diajukan; menunggu verifikasi\./)
    expect(router.currentRoute.value.path).toBe('/master-data/usulan')
    // Belum berlaku: matriks tidak berubah dan tidak ada endpoint tulis langsung.
    expect(permintaan.some((r) => r.jalur.startsWith('/data-induk/matriks-perpindahan') && r.metode.toUpperCase() !== 'GET')).toBe(false)
    expect((await daftarMatriks({ pos_asal: 'igd' })).data.find((a) => a.id === doa.id)!.status).toBe('aktif')
    wrapper.unmount()
  })

  it('tambah sel: galat pasangan sudah ada dari server tampil di dialog', async () => {
    await pasang()
    tombol(/Usulkan sel baru/)!.click()
    await tenang()
    const dialog = document.querySelector('[role="dialog"]')!
    const [asal, tujuan] = [...dialog.querySelectorAll('select')]
    asal!.value = 'igd'
    asal!.dispatchEvent(new Event('change'))
    tujuan!.value = 'pulang'
    tujuan!.dispatchEvent(new Event('change'))
    const ketik = (label: string, nilai: string) => {
      const l = [...dialog.querySelectorAll('label')].find((x) => x.textContent?.replace('*', '').trim() === label)!
      const el = document.getElementById(l.htmlFor) as HTMLInputElement
      el.value = nilai
      el.dispatchEvent(new Event('input'))
    }
    ketik('Izin pemberi perintah', 'igd.disposisi.input')
    ketik('Dasar', 'Memo uji')
    ketik('Alasan usulan', 'Tambah sel ganda (uji)')
    await tenang()
    tombol(/^Ajukan usulan$/)!.click()
    await tenang()
    expect(dialog.textContent).toContain('Sel matriks untuk pasangan pos ini sudah ada.')
  })

  it('tanpa izin usulan input: tanpa tombol usulan', async () => {
    aturIzin(['master_data.matriks_perpindahan.lihat'])
    await pasang()
    expect(tombol(/Usulkan/)).toBeUndefined()
    expect(document.querySelectorAll('tbody tr').length).toBeGreaterThan(5)
  })
})
