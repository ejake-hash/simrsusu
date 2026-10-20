import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import type { PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { konfirmasiLangkah, muatPaket } from './api'
import PitaPenyiapan from './components/PitaPenyiapan.vue'
import DaftarLangkah from './components/DaftarLangkah.vue'

const adapterAsli = klien.defaults.adapter
let server: ReturnType<typeof buatServerGabungan>
let permintaan: PermintaanTiruan[]
const IZIN = ['pengaturan.penyiapan.lihat', 'pengaturan.penyiapan.input', 'pengaturan.penyiapan.perbaiki', 'master_data.katalog.lihat', 'master_data.katalog.input']

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan())
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  aturIzin(IZIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  mount(komponen, {
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
}
async function tenang(ms = 10) {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, ms))
  }
}
const langkah = (kode: string) => document.querySelector(`li[aria-labelledby="judul-langkah-${kode}"]`) as HTMLElement
const tombol = (teks: string, akar: ParentNode = document) => [...akar.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
const klik = async (el: HTMLElement | null | undefined) => {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
const dialog = () => document.querySelector('[role="dialog"], [role="alertdialog"]') as HTMLElement | null
function isian(label: string, akar: ParentNode = document): HTMLInputElement {
  const lbl = [...akar.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
async function isi(label: string, nilai: string, akar: ParentNode = document) {
  const el = isian(label, akar)
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang()
}
async function pilihBerkas(teks: string, akar: ParentNode) {
  const el = isian('Berkas CSV', akar)
  Object.defineProperty(el, 'files', { value: [new File([teks], 'paket.csv', { type: 'text/csv' })], configurable: true })
  el.dispatchEvent(new Event('change'))
  await tenang()
}
const csv = (n: number) => ['kode,nama', ...Array.from({ length: n }, (_, i) => `${11 + i},Provinsi ${i + 1}`)].join('\n')
async function muatSemuaPaketWajib() {
  for (const s of ['wilayah', 'referensi', 'loinc', 'ucum'] as const) {
    await muatPaket(s, { berkas: new File(['kode,nama\n1,a'], 'p.csv'), mode: 'terapkan', versi: '2025.1', sumber: 'Sumber uji', catatan_lisensi: s === 'loinc' ? 'Regenstrief' : null })
  }
}

describe('Halaman penyiapan', () => {
  it('sembilan langkah berurutan; konfirmasi mengikuti dapat_dikonfirmasi; langkah 7 menunggu fase 2', async () => {
    await pasang(DaftarLangkah)
    expect(document.querySelectorAll('ol[aria-label="Langkah penyiapan"] > li')).toHaveLength(9)
    expect(langkah('penjamin_tarif').textContent).not.toContain('Menunggu fase 2')
    expect(langkah('formularium').textContent).toContain('Menunggu fase 2')
    expect([...langkah('penjamin_tarif').querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual(['/master-data/penjamin', '/master-data/tarif'])
    expect(document.body.textContent).toContain('Instalasi belum bisa melayani pasien')
    // Urutan: langkah 2 belum bisa dikonfirmasi sebelum langkah 1.
    expect(tombol('Konfirmasi selesai', langkah('profil_rs'))).toBeUndefined()
    // Tautan ke layar pengisi, bukan formulir duplikat.
    expect(langkah('profil_rs').querySelector('a')?.getAttribute('href')).toBe('/pengaturan/profil-rs')

    await klik(tombol('Konfirmasi selesai', langkah('superadmin')))
    expect(permintaan.some((p) => p.metode.toUpperCase() === 'POST' && p.jalur === '/penyiapan/superadmin/selesai')).toBe(true)
    expect(langkah('superadmin').textContent).toContain('Selesai')
    expect(langkah('superadmin').textContent).toContain('Dikonfirmasi oleh Contoh Superadmin')
    expect(tombol('Konfirmasi ulang', langkah('superadmin'))).toBeTruthy()
    expect(tombol('Konfirmasi selesai', langkah('profil_rs'))).toBeTruthy()
    expect(daftarToast.value[daftarToast.value.length - 1]?.pesan).toContain('Langkah 1 dikonfirmasi selesai.')
  })

  it('langkah 4: muat paket lewat pratinjau lalu terapkan; versi sama dengan isi lain ditolak di kolom versi', async () => {
    await pasang(DaftarLangkah)
    expect(langkah('paket_referensi').textContent).toContain('Menunggu berkas RS')
    await klik(document.querySelector<HTMLButtonElement>('button[aria-label="Muat paket Wilayah (Kemendagri)"]'))
    const d = dialog()!
    await pilihBerkas(csv(3), d)
    await isi('Versi', '2025.1', d)
    await isi('Sumber', 'Kemendagri uji', d)
    await klik(tombol('Periksa berkas', d))
    expect(d.textContent).toContain('Semua 3 baris benar dan siap diterapkan.')
    expect(d.querySelector('[aria-label="Ringkasan perubahan"]')?.textContent).toContain('3 baru')
    await klik(tombol('Terapkan', d))
    expect(dialog()).toBeNull()
    expect(langkah('paket_referensi').textContent).toContain('2025.1')
    expect(langkah('paket_referensi').textContent).toContain('Kemendagri uji')

    await klik(document.querySelector<HTMLButtonElement>('button[aria-label="Muat paket Wilayah (Kemendagri)"]'))
    const d2 = dialog()!
    await pilihBerkas(csv(4), d2)
    await isi('Versi', '2025.1', d2)
    await isi('Sumber', 'Kemendagri uji', d2)
    await klik(tombol('Periksa berkas', d2))
    expect(d2.textContent).toContain('Versi ini sudah pernah dimuat. Pakai nomor versi lain.')
    expect(isian('Versi', d2).getAttribute('aria-invalid')).toBe('true')
  })

  it('LOINC wajib catatan lisensi; baris salah ditampilkan dengan nomor barisnya', async () => {
    await pasang(DaftarLangkah)
    await klik(document.querySelector<HTMLButtonElement>('button[aria-label="Muat paket LOINC"]'))
    const d = dialog()!
    await pilihBerkas('kode,tampilan,kode_induk\n,Glukosa,\n2345-7,Glukosa darah,', d)
    await isi('Versi', '2.80', d)
    await isi('Sumber', 'Regenstrief uji', d)
    await klik(tombol('Periksa berkas', d))
    expect(d.textContent).toContain('Atribusi lisensi LOINC wajib diisi.')
    await isi('Catatan lisensi', 'LOINC © Regenstrief Institute', d)
    await klik(tombol('Periksa berkas', d))
    expect(d.textContent).toContain('1 dari 2 baris salah')
    expect(d.querySelector('[aria-label="Baris yang salah"]')?.textContent).toContain('2')
    expect(tombol('Terapkan', d)?.disabled).toBe(true)
  })

  it('langkah 8 dilewati dengan alasan wajib sesudah langkah 1–6 selesai', async () => {
    await muatSemuaPaketWajib()
    for (const k of ['superadmin', 'profil_rs', 'organisasi', 'paket_referensi', 'pegawai', 'penjamin_tarif'] as const) await konfirmasiLangkah(k)
    await pasang(DaftarLangkah)
    await klik(tombol('Lewati', langkah('kredensial_integrasi')))
    const d = dialog()!
    await klik(tombol('Lewati langkah', d))
    expect(d.textContent).toContain('Tulis alasan, minimal 5 karakter.')
    await isi('Alasan', 'Belum ada kredensial dari RS', d)
    await klik(tombol('Lewati langkah', d))
    expect(dialog()).toBeNull()
    expect(langkah('kredensial_integrasi').textContent).toContain('Dilewati')
    expect(langkah('kredensial_integrasi').textContent).toContain('“Belum ada kredensial dari RS”')
    expect(tombol('Konfirmasi selesai', langkah('modul_aktif'))).toBeTruthy()
    expect(document.body.textContent).toContain('Instalasi siap melayani pasien sejak')
  })

  it('langkah 6: tanpa tombol konfirmasi selama ada kelas rawat tanpa tarif', async () => {
    await muatSemuaPaketWajib()
    for (const k of ['superadmin', 'profil_rs', 'organisasi', 'paket_referensi', 'pegawai'] as const) await konfirmasiLangkah(k)
    server.penyiapan.keadaan.kelasTanpaTarif = 2
    await pasang(DaftarLangkah)
    expect(langkah('penjamin_tarif').textContent).toContain('2 kelas belum bertarif')
    expect(tombol('Konfirmasi selesai', langkah('penjamin_tarif'))).toBeUndefined()
  })

  it('langkah 6: konfirmasi membuat instalasi siap melayani', async () => {
    await muatSemuaPaketWajib()
    for (const k of ['superadmin', 'profil_rs', 'organisasi', 'paket_referensi', 'pegawai'] as const) await konfirmasiLangkah(k)
    await pasang(DaftarLangkah)
    await klik(tombol('Konfirmasi selesai', langkah('penjamin_tarif')))
    expect(permintaan.some((p) => p.metode.toUpperCase() === 'POST' && p.jalur === '/penyiapan/penjamin_tarif/selesai')).toBe(true)
    expect(langkah('penjamin_tarif').textContent).toContain('Selesai')
    expect(document.body.textContent).toContain('Instalasi siap melayani pasien sejak')
  })

  it('tanpa izin katalog tidak ada tombol muat (tombol konfirmasi mengikuti dapat_* dari server)', async () => {
    aturIzin(['pengaturan.penyiapan.lihat', 'pengaturan.penyiapan.perbaiki'])
    await pasang(DaftarLangkah)
    expect(document.querySelector('button[aria-label^="Muat paket"]')).toBeNull()
    expect(tombol('Konfirmasi selesai', langkah('superadmin'))).toBeTruthy()
  })
})

describe('Pita penyiapan di Beranda', () => {
  it('tampil bila belum siap melayani; tanpa izin tidak memanggil server', async () => {
    await pasang(PitaPenyiapan)
    expect(document.body.textContent).toContain('Instalasi belum bisa melayani pasien')
    expect(document.querySelector('a[href="/pengaturan/penyiapan"]')).toBeTruthy()

    document.body.innerHTML = ''
    permintaan.length = 0
    aturIzin([])
    await pasang(PitaPenyiapan)
    expect(document.body.textContent).not.toContain('Instalasi belum bisa melayani pasien')
    expect(permintaan.some((p) => p.jalur === '/penyiapan')).toBe(false)
  })
})
