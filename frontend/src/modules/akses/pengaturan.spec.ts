import { focusManager, QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import EditorPeran from './components/pengaturan/EditorPeran.vue'
import MatriksIzin from './components/pengaturan/MatriksIzin.vue'
import RincianAkun from './components/pengaturan/RincianAkun.vue'
import { indeksPohon } from './matriks'
import { uid } from './tiruan/dataPengaturan'
import { pasangTiruan } from './tiruan/pasangTiruan'
import { buatServerTiruan, ID_SUPERADMIN, type ServerTiruan } from './tiruan/serverTiruan'

const adapterAsli = klien.defaults.adapter
const KASIR = uid(105)
const AKUN_KASIR = uid(8)
const IZIN_SUPERADMIN = ['pengaturan.peran.lihat', 'pengaturan.peran.input', 'pengaturan.peran.perbaiki', 'pengaturan.akun.lihat', 'pengaturan.akun.input', 'pengaturan.akun.perbaiki']

let server: ServerTiruan

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerTiruan())
  aturIzin(IZIN_SUPERADMIN)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

// Komponen uji beragam (SFC dan defineComponent); props diperiksa saat runtime.
async function pasang(komponen: any, props: Record<string, unknown> = {}, jalur = '/') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push(jalur)
  const w = mount(komponen, {
    props,
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await flushPromises()
  return w
}

const tombol = (teks: string) => [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
/** Validasi vee-validate dan adapter tiruan berjalan asinkron beberapa putaran; tunggu sampai tenang. */
async function tenang() {
  for (let i = 0; i < 3; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, 10))
  }
}
const klik = async (el: HTMLElement | undefined) => {
  el!.click()
  await tenang()
}
const kotak = (label: string) => [...document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')].find((c) => c.closest('label')?.textContent?.includes(label))!
async function centang(label: string, nyala: boolean) {
  const c = kotak(label)
  c.checked = nyala
  c.dispatchEvent(new Event('change'))
  await flushPromises()
}
async function isi(label: string, nilai: string) {
  const lbl = [...document.querySelectorAll('label')].find((l) => l.textContent?.trim().startsWith(label))!
  const el = document.getElementById(lbl.getAttribute('for')!) as HTMLInputElement
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang()
}
const izinServer = () => (server.tangani({ metode: 'GET', jalur: `/peran/${KASIR}`, kueri: new URLSearchParams(), header: {}, isi: null })!.isi as { data: { izin: string[] } }).data.izin

describe('Editor peran (MD-2, 412, 409, 422)', () => {
  const indeks = () => indeksPohon(server.pohon)

  it('simpan matriks: ringkasan perubahan dan dasar wajib; tanpa dasar tidak ada permintaan', async () => {
    await pasang(EditorPeran, { peranId: KASIR, indeks: indeks() })
    await centang('Cetak', false)
    expect(document.body.textContent).toContain('0 ditambah, 1 dicabut')

    await klik(tombol('Simpan perubahan'))
    const dialog = document.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('Dicabut (1)')
    expect(dialog.textContent).toContain('Kasir → Tagihan pasien → Cetak')
    expect(dialog.textContent).toContain('1 akun memegang peran ini')

    await klik([...dialog.querySelectorAll('button')].find((b) => b.textContent?.includes('Simpan perubahan')))
    expect(dialog.textContent).toContain('Isi dasar perubahan')
    expect(izinServer()).toContain('kasir.tagihan.cetak')

    await isi('Dasar perubahan', 'SK Direktur 12/2026')
    await klik([...dialog.querySelectorAll('button')].find((b) => b.textContent?.includes('Simpan perubahan')))
    expect(izinServer()).not.toContain('kasir.tagihan.cetak')
    expect(document.body.textContent).toContain('tersimpan: 0 ditambah, 1 dicabut, 1 akun terdampak')
  })

  it('409 butuh_persetujuan: dialog ditutup, pesan menyebut izin sensitifnya, baris disorot, tidak ada yang tersimpan', async () => {
    await pasang(EditorPeran, { peranId: KASIR, indeks: indeks() })
    await centang('Batal', true)
    await centang('Cetak', false)
    await klik(tombol('Simpan perubahan'))
    expect(document.querySelector('[role="alertdialog"]')!.textContent).toContain('1 izin sensitif butuh persetujuan')
    await isi('Dasar perubahan', 'SK 1/2026')
    await klik([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.includes('Simpan perubahan')) as HTMLElement)

    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
    const alert = document.querySelector('[role="alert"]')!
    expect(alert.textContent).toContain('Tidak ada yang disimpan')
    expect(alert.textContent).toContain('Kasir → Tagihan pasien → Batal')
    expect(document.querySelector('tr.salah')?.textContent).toContain('Tagihan pasien')
    expect(izinServer()).toContain('kasir.tagihan.cetak')
  })

  it('412: suntingan tidak hilang; perbedaan ditampilkan, lalu diterapkan di atas versi terbaru', async () => {
    await pasang(EditorPeran, { peranId: KASIR, indeks: indeks() })
    await centang('Cetak', false)

    // Admin lain mengubah peran yang sama lebih dulu.
    const lain = server.tangani({ metode: 'GET', jalur: `/peran/${KASIR}`, kueri: new URLSearchParams(), header: {}, isi: null })!
    server.tangani({
      metode: 'PUT',
      jalur: `/peran/${KASIR}/izin`,
      kueri: new URLSearchParams(),
      header: { 'if-match': lain.header!.ETag! },
      isi: { izin: [...izinServer(), 'kasir.tutup.lihat'].filter((v, i, a) => a.indexOf(v) === i), dasar_perubahan: 'Memo lain' },
    })

    await klik(tombol('Simpan perubahan'))
    await isi('Dasar perubahan', 'SK 1/2026')
    await klik([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.includes('Simpan perubahan')) as HTMLElement)
    await flushPromises()

    const teks = document.body.textContent!
    expect(teks).toContain('diubah orang lain sejak Anda membukanya')
    expect(teks).toContain('Perubahan orang lain')
    expect(teks).toContain('− Kasir → Tagihan pasien → Cetak')
    expect(tombol('Simpan perubahan')).toBeUndefined()

    await klik(tombol('Terapkan suntingan saya di atas versi terbaru'))
    await flushPromises()
    expect(kotak('Cetak').checked).toBe(false)
    expect(document.body.textContent).toContain('0 ditambah, 1 dicabut')
  })

  it('muat ulang latar (tab kembali fokus) tidak membuang suntingan: versi baru tampil sebagai bentrok', async () => {
    await pasang(EditorPeran, { peranId: KASIR, indeks: indeks() })
    await centang('Cetak', false)
    expect(tombol('Ubah info')!.disabled).toBe(true)

    const lain = server.tangani({ metode: 'GET', jalur: `/peran/${KASIR}`, kueri: new URLSearchParams(), header: {}, isi: null })!
    server.tangani({
      metode: 'PUT',
      jalur: `/peran/${KASIR}/izin`,
      kueri: new URLSearchParams(),
      header: { 'if-match': lain.header!.ETag! },
      isi: { izin: [...izinServer(), 'kasir.tutup.lihat'].filter((v, i, a) => a.indexOf(v) === i), dasar_perubahan: 'Memo lain' },
    })
    focusManager.setFocused(false)
    focusManager.setFocused(true)
    await tenang()
    focusManager.setFocused(undefined)

    expect(document.body.textContent).toContain('diubah orang lain sejak Anda membukanya')
    expect(kotak('Cetak').checked).toBe(false)
  })

  it('muat ulang latar tanpa suntingan: matriks langsung ikut versi terbaru', async () => {
    await pasang(EditorPeran, { peranId: KASIR, indeks: indeks() })
    const lain = server.tangani({ metode: 'GET', jalur: `/peran/${KASIR}`, kueri: new URLSearchParams(), header: {}, isi: null })!
    server.tangani({
      metode: 'PUT',
      jalur: `/peran/${KASIR}/izin`,
      kueri: new URLSearchParams(),
      header: { 'if-match': lain.header!.ETag! },
      isi: { izin: izinServer().filter((k) => k !== 'kasir.tagihan.cetak'), dasar_perubahan: 'Memo lain' },
    })
    focusManager.setFocused(false)
    focusManager.setFocused(true)
    await tenang()
    focusManager.setFocused(undefined)

    expect(document.body.textContent).not.toContain('diubah orang lain')
    expect(kotak('Cetak').checked).toBe(false)
    expect(document.body.textContent).toContain('Tidak ada perubahan')
  })

  it('422 dari server: dialog ditutup, pesan ditampilkan, baris yang disebut rincian disorot', async () => {
    await pasang(EditorPeran, { peranId: KASIR, indeks: indeks() })
    await centang('Cetak', false)
    const put = vi.spyOn(klien, 'put').mockRejectedValueOnce(
      new ApiError(422, 'induk_belum_diberikan', 'Bagian mensyaratkan sub-menu induknya diberi lihat.', [
        { kolom: 'izin', kode: 'kasir.tagihan.panel_diskon.lihat', pesan: 'Sub-menu induk belum diberi lihat.' },
      ]),
    )
    await klik(tombol('Simpan perubahan'))
    await isi('Dasar perubahan', 'SK 1/2026')
    await klik([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.includes('Simpan perubahan')) as HTMLElement)

    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
    expect(document.querySelector('[role="alert"]')!.textContent).toContain('Baris yang ditolak disorot')
    expect(document.querySelector('tr.salah')?.textContent).toContain('Panel diskon')
    put.mockRestore()
  })

  it('izin sensitif pada aksi dasar bergembok di sel dan pilihan tingkat', async () => {
    await pasang(EditorPeran, { peranId: KASIR, indeks: indeks() })
    await klik(tombol('Buka semua'))
    const baris = [...document.querySelectorAll('tr')].find((r) => r.querySelector('th')?.textContent?.includes('Peran & izin'))!
    expect(baris.querySelectorAll('.gembok')).toHaveLength(2)
    expect(baris.textContent).toContain('Input Pengaturan Sistem → Peran & izin (sensitif)')
    expect([...baris.querySelectorAll('option')].map((o) => o.textContent)).toEqual(['Tidak ada', 'Lihat', 'Input (sensitif)', 'Perbaiki (sensitif)'])
  })

  it('peran terkunci (superadmin) = mode baca dengan keterangan, tanpa tombol simpan', async () => {
    await pasang(EditorPeran, { peranId: uid(101), indeks: indeks() })
    expect(document.body.textContent).toContain('Peran bawaan sistem: isinya dikunci')
    expect(tombol('Simpan perubahan')).toBeUndefined()
    expect([...document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')].every((c) => c.disabled)).toBe(true)
  })
})

describe('MatriksIzin', () => {
  it('sel bagian nonaktif dengan keterangan selama sub-menu induk belum diberi lihat (MD-3)', async () => {
    const indeks = indeksPohon(server.pohon)
    const w = await pasang(MatriksIzin, { indeks, izin: new Set<string>(), tersimpan: [], bacaSaja: false, sorot: new Set() })
    await klik(tombol('Buka semua'))
    const diskon = [...w.findAll('tr')].find((r) => r.text().includes('Panel diskon'))!
    expect(diskon.findAll('input[type="checkbox"]').every((c) => (c.element as HTMLInputElement).disabled)).toBe(true)
    expect(diskon.text()).toContain('Beri lihat pada Tagihan pasien dulu')

    await w.setProps({ izin: new Set(['kasir.tagihan.lihat']) })
    const sesudah = [...w.findAll('tr')].find((r) => r.text().includes('Panel diskon'))!
    expect(sesudah.findAll('input[type="checkbox"]').some((c) => !(c.element as HTMLInputElement).disabled)).toBe(true)
    expect(sesudah.text()).not.toContain('Beri lihat pada')
  })
})

describe('Rincian akun', () => {
  it('Akhiri pengecualian per akun butuh alasan; baris tetap ada sebagai riwayat (MD-1)', async () => {
    await pasang(RincianAkun, { akunId: AKUN_KASIR, indeks: indeksPohon(server.pohon) })
    const tabPerAkun = [...document.querySelectorAll('[role="tab"]')].find((t) => t.textContent === 'Per akun') as HTMLElement
    tabPerAkun.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await flushPromises()
    expect(document.body.textContent).toContain('Kasir → Tutup kasir')

    await klik(tombol('Akhiri'))
    const dialog = document.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('Akses Kasir → Tutup kasir untuk Contoh Kasir kembali sesuai perannya.')
    await klik([...dialog.querySelectorAll('button')].find((b) => b.textContent === 'Akhiri'))
    expect(dialog.textContent).toContain('Tulis alasan')

    await isi('Alasan mengakhiri', 'Sudah lulus pelatihan')
    await klik([...dialog.querySelectorAll('button')].find((b) => b.textContent === 'Akhiri'))
    await flushPromises()
    expect(document.body.textContent).toContain('Pengecualian Kasir → Tutup kasir diakhiri.')
    expect(document.body.textContent).toContain('Riwayat pengecualian (2)')
  })

  it('akun sendiri: tidak ada tombol ubah di tab mana pun, ada keterangan', async () => {
    await pasang(RincianAkun, { akunId: ID_SUPERADMIN, indeks: indeksPohon(server.pohon) })
    expect(document.body.textContent).toContain('Perubahan akun sendiri dilakukan superadmin lain.')
    for (const nama of ['Penugasan', 'Per akun', 'MFA']) {
      const tab = [...document.querySelectorAll('[role="tab"]')].find((t) => t.textContent === nama) as HTMLElement
      tab.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
      await flushPromises()
      for (const teks of ['Tugaskan peran', 'Akhiri', 'Tambah pengecualian', 'Reset MFA']) expect(tombol(teks)).toBeUndefined()
      expect(document.querySelector('[role="switch"]')).toBeNull()
    }
  })

  it('tab MFA: sumber yang tidak bisa dimatikan diberi alasan, teks luar jaringan RS tetap', async () => {
    await pasang(RincianAkun, { akunId: AKUN_KASIR, indeks: null })
    const tab = [...document.querySelectorAll('[role="tab"]')].find((t) => t.textContent === 'MFA') as HTMLElement
    tab.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await flushPromises()
    const teks = document.body.textContent!
    expect(teks).toContain('Dari luar jaringan RS, MFA selalu wajib.')
    expect(teks).toContain('Memegang izin sensitif')
    expect(teks).toContain('Tidak bisa dimatikan. Pemegang izin sensitif selalu wajib MFA.')
  })
})

describe('HalamanModul (gerbang halaman sub-menu)', () => {
  function halaman() {
    return defineComponent({ render: () => h(HalamanModul, { izin: 'pengaturan.peran.lihat', judul: 'Peran & izin', kelasTerbatas: true }, { default: () => h('p', 'isi rahasia') }) })
  }
  beforeEach(() => {
    useSesiStore().pengguna = { id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', email: null, login_terakhir_pada: null, mfa_aktif: true, pegawai: null }
  })

  it('dengan izin lihat: judul, navigasi sub-menu saudara, dan isi', async () => {
    await pasang(halaman(), {}, '/pengaturan/peran')
    expect(document.querySelector('h1')?.textContent).toBe('Peran & izin')
    expect(document.querySelector('nav[aria-label="Sub-menu Pengaturan Sistem"]')).not.toBeNull()
    expect(document.body.textContent).toContain('isi rahasia')
  })

  it('tanpa izin lihat → Halaman tidak ditemukan dan isi tidak dipasang', async () => {
    useSesiStore().pengguna!.id = uid(4)
    server = pasangTiruan(klien, buatServerTiruan({ penggunaId: uid(4) }))
    await pasang(halaman(), {}, '/pengaturan/peran')
    expect(document.querySelector('h1')?.textContent).toBe('Halaman tidak ditemukan')
    expect(document.body.textContent).not.toContain('isi rahasia')
  })

  it('ponsel (< 768 px): pesan gunakan tablet/desktop, isi tidak dipasang', async () => {
    vi.stubGlobal('matchMedia', (q: string) => ({ matches: false, media: q, addEventListener: () => {}, removeEventListener: () => {} }))
    await pasang(halaman(), {}, '/pengaturan/peran')
    expect(document.body.textContent).toContain('Halaman ini dibuka dari tablet atau komputer')
    expect(document.body.textContent).not.toContain('isi rahasia')
    vi.unstubAllGlobals()
  })
})
