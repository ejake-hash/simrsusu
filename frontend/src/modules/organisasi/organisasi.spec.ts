import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { useProfilRs } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import PohonData from '@/design-system/components/PohonData.vue'
import DialogPenugasan from '@/modules/akses/components/pengaturan/DialogPenugasan.vue'
import { uid } from '@/modules/akses/tiruan/dataPengaturan'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN } from '@/modules/akses/tiruan/serverTiruan'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import PemilihUnit from './components/PemilihUnit.vue'
import TabKsm from './components/TabKsm.vue'
import TabUnit from './components/TabUnit.vue'
import ProfilRsPage from './pages/ProfilRsPage.vue'

const adapterAsli = klien.defaults.adapter
let server: ReturnType<typeof buatServerGabungan>
let router: Router

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerGabungan())
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.superadmin', mfa_aktif: true })
  aturIzin(['master_data.organisasi.lihat', 'master_data.organisasi.input', 'master_data.organisasi.perbaiki', 'pengaturan.profil_rs.lihat', 'pengaturan.profil_rs.perbaiki', 'pengaturan.akun.input'])
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  document.documentElement.removeAttribute('style')
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any, props: Record<string, unknown> = {}, jalur = '/') {
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push(jalur)
  const w = mount(komponen, {
    props,
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
  return w
}
/** Adapter tiruan, vee-validate, dan debounce cari (300 ms) berjalan asinkron; tunggu sampai tenang. */
async function tenang(ms = 10) {
  for (let i = 0; i < 4; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, ms))
  }
}
const tombol = (teks: string) => [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
const klik = async (el: HTMLElement | undefined) => {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
function isian(label: string): HTMLInputElement {
  const lbl = [...document.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
async function isi(label: string, nilai: string) {
  const el = isian(label)
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang()
}
const tekan = (el: Element, key: string) => el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))
const item = (nama: string) => [...document.querySelectorAll('[role="treeitem"]')].find((t) => t.querySelector('.label')?.textContent?.trim() === nama) as HTMLElement | undefined

describe('PohonData muat-lambat (adaAnak)', () => {
  it('simpul adaAnak tanpa anak bisa dibuka dengan panah kanan; aria-busy saat memuat', async () => {
    const terbuka = ref<string[]>([])
    const memuat = ref(false)
    await pasang(
      defineComponent({
        setup: () => () =>
          h(PohonData, {
            simpul: [{ id: 'a', label: 'Gedung A', adaAnak: true, memuat: memuat.value }],
            label: 'Lokasi',
            terbuka: terbuka.value,
            'onUpdate:terbuka': (v: string[]) => (terbuka.value = v),
          }),
      }),
    )
    const a = document.querySelector('[role="treeitem"]')!
    expect(a.getAttribute('aria-expanded')).toBe('false')
    tekan(a, 'ArrowRight')
    await tenang()
    expect(terbuka.value).toEqual(['a'])
    memuat.value = true
    await tenang()
    expect(a.getAttribute('aria-busy')).toBe('true')
    expect(a.querySelector('[role="group"]')).toBeNull()
  })
})

describe('Tab unit organisasi', () => {
  it('akar dimuat; membuka IRNA memuat anaknya; memilih unit menulis ?unit= dan menampilkan rincian', async () => {
    await pasang(TabUnit)
    expect(item('Instalasi Rawat Inap')).toBeTruthy()
    expect(item('Bangsal Penyakit Dalam')).toBeUndefined()
    tekan(item('Instalasi Rawat Inap')!, 'ArrowRight')
    await tenang()
    expect(item('Bangsal Penyakit Dalam')).toBeTruthy()
    // Cucu belum dimuat sampai cabangnya dibuka.
    expect(item('Ruang Isolasi Penyakit Dalam')).toBeUndefined()

    await klik(item('Bangsal Penyakit Dalam')!)
    expect(router.currentRoute.value.query.unit).toBeTruthy()
    const rincian = document.querySelector('section[aria-labelledby="judul-rincian-unit"]')!
    expect(rincian.querySelector('h2')?.textContent).toBe('Bangsal Penyakit Dalam')
    expect(rincian.querySelector('nav[aria-label="Unit induk"]')?.textContent).toContain('Instalasi Rawat Inap')
  })

  it('cari menampilkan daftar datar dengan jalur; memilih hasil membuka cabangnya di pohon', async () => {
    await pasang(TabUnit)
    await isi('Cari unit', 'isolasi')
    await tenang(120)
    const hasil = [...document.querySelectorAll('.hasil-cari button')]
    expect(hasil).toHaveLength(1)
    expect(hasil[0]!.textContent).toContain('IRNA › IRNA-PD')
    await klik(hasil[0] as HTMLElement)
    await tenang()
    expect(item('Ruang Isolasi Penyakit Dalam')?.getAttribute('aria-selected')).toBe('true')
  })

  it('nonaktifkan unit yang masih punya anak aktif → 409 dengan daftar yang harus dibereskan', async () => {
    await pasang(TabUnit)
    await klik(item('Instalasi Rawat Inap')!)
    await klik(tombol('Nonaktifkan'))
    await isi('Dasar perubahan', 'SK Direktur 12/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-status-org"]')!)
    // Dialog peringatan = role alertdialog.
    expect(document.querySelector('[role="alertdialog"]')?.textContent).toContain('3 unit anak masih aktif')
  })

  it('tambah sub-unit di induk terpilih; kode yang sudah dipakai → pesan di kolom kode', async () => {
    await pasang(TabUnit)
    await klik(item('Instalasi Rawat Jalan')!)
    await klik(tombol('Sub-unit'))
    expect(document.querySelector('[role="dialog"] h2')?.textContent).toContain('Tambah sub-unit di IRJA')
    expect(isian('Unit induk').value).toBe('IRJA · Instalasi Rawat Jalan')
    await isi('Kode', 'irna')
    expect(isian('Kode').value).toBe('IRNA')
    await isi('Nama unit', 'Poli Uji')
    const jenis = isian('Jenis') as unknown as HTMLSelectElement
    jenis.value = 'poli'
    jenis.dispatchEvent(new Event('change'))
    await isi('Dasar perubahan', 'SK Direktur 13/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-unit"]')!)
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('sudah dipakai')

    await isi('Kode', 'POLI-UJI')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-unit"]')!)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.querySelector('#judul-rincian-unit')?.textContent).toBe('Poli Uji')
    expect(item('Poli Uji')).toBeTruthy()
  })
})

describe('Tab KSM', () => {
  it('daftar KSM aktif; ubah memakai ETag dari GET /ksm/{id}', async () => {
    await pasang(TabKsm)
    const baris = [...document.querySelectorAll('tbody tr')]
    expect(baris.length).toBeGreaterThan(0)
    const pertama = baris[0]!
    const nama = pertama.querySelectorAll('td')[1]!.textContent!.trim()
    await klik([...pertama.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ubah'))
    expect(isian('Nama KSM').value).toBe(nama)
    await isi('Nama KSM', `${nama} (ubah)`)
    await isi('Dasar perubahan', 'SK Komite Medik 1/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-ksm"]')!)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.body.textContent).toContain(`${nama} (ubah)`)
  })
})

describe('PemilihUnit (combobox)', () => {
  it('ketik → hasil berjalur; panah bawah + Enter memilih tanpa mengirim formulir; Esc hanya menutup daftar', async () => {
    const nilai = ref<string | null>(null)
    let terkirim = 0
    let escKeluar = 0
    document.addEventListener('keydown', (e) => e.key === 'Escape' && escKeluar++)
    await pasang(
      defineComponent({
        setup: () => () =>
          h('form', { onSubmit: (e: Event) => (e.preventDefault(), terkirim++) }, [
            h(PemilihUnit, { modelValue: nilai.value, 'onUpdate:modelValue': (v: string | null) => (nilai.value = v), id: 'pu', kecuali: null }),
          ]),
      }),
    )
    const input = document.getElementById('pu') as HTMLInputElement
    input.dispatchEvent(new Event('focus'))
    input.value = 'bangsal'
    input.dispatchEvent(new Event('input'))
    await tenang(100)
    expect(input.getAttribute('aria-expanded')).toBe('true')
    const opsi = [...document.querySelectorAll('[role="option"]')]
    expect(opsi.map((o) => o.textContent)).toEqual(expect.arrayContaining([expect.stringContaining('IRNA-ANAK')]))
    tekan(input, 'ArrowDown')
    await tenang()
    tekan(input, 'Enter')
    await tenang()
    expect(terkirim).toBe(0)
    expect(nilai.value).toBeTruthy()
    expect(input.value).toMatch(/^IRNA-/)

    tekan(input, 'ArrowDown')
    await tenang()
    expect(input.getAttribute('aria-expanded')).toBe('true')
    tekan(input, 'Escape')
    await tenang()
    expect(input.getAttribute('aria-expanded')).toBe('false')
    expect(escKeluar).toBe(0)
  })
})

describe('Penugasan cakupan unit', () => {
  it('cakupan Unit kerja wajib memilih unit; tersimpan dengan cakupan_id', async () => {
    const akunId = uid(8)
    const w = await pasang(DialogPenugasan, { open: false, akunId, namaAkun: 'Kasir' })
    await w.setProps({ open: true })
    await tenang()
    const peran = isian('Peran') as unknown as HTMLSelectElement
    peran.value = [...peran.options].find((o) => o.value && !o.disabled)!.value
    peran.dispatchEvent(new Event('change'))
    const unitRadio = [...document.querySelectorAll<HTMLInputElement>('input[name="cakupan"]')].find((r) => r.value === 'unit')!
    unitRadio.click()
    await tenang()
    await isi('Dasar pemberian', 'SK Penugasan 5/2026')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penugasan"]')!)
    expect(document.body.textContent).toContain('Pilih unit kerja.')

    const pu = isian('Unit kerja')
    pu.dispatchEvent(new Event('focus'))
    pu.value = 'IRNA-BEDAH'
    pu.dispatchEvent(new Event('input'))
    await tenang(100)
    tekan(pu, 'Enter')
    await tenang()
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-penugasan"]')!)
    expect(document.body.textContent).not.toContain('Pilih unit kerja.')
    const akun = server.tangani({ metode: 'GET', jalur: `/akun/${akunId}`, kueri: new URLSearchParams(), header: {}, isi: null })!.isi as {
      data: { penugasan: { cakupan_jenis: string; cakupan_label?: string | null }[] }
    }
    expect(akun.data.penugasan.find((p) => p.cakupan_jenis === 'unit')?.cakupan_label).toBe('IRNA-BEDAH · Bangsal Bedah')
  })
})

describe('Profil rumah sakit', () => {
  it('kontras kurang ditolak sebelum dikirim; simpan memasang identitas dan tema ke seluruh layar', async () => {
    await pasang(ProfilRsPage, {}, '/pengaturan/profil-rs')
    expect(document.body.textContent).toContain('Instalasi ini belum disiapkan')
    await isi('Nama rumah sakit', 'RS Pendidikan Contoh')
    await isi('Warna utama', '#FFEE00')
    expect(document.body.textContent).toMatch(/Kontras teks putih di atas warna ini 1,\d+:1 · minimal 4,5:1/)
    await isi('Dasar perubahan', 'SK Direktur 1/2026')
    await klik(tombol('Simpan profil'))
    expect(document.body.textContent).toContain('Kontras warna belum cukup')
    expect(useProfilRs().value.nama).toBeNull()

    await isi('Warna utama', '#1F4E8C')
    await klik(tombol('Simpan profil'))
    expect(useProfilRs().value.nama).toBe('RS Pendidikan Contoh')
    expect(document.documentElement.style.getPropertyValue('--brand-600')).toBe('#1F4E8C')
  })

  it('412 versi berubah → tombol muat ulang profil', async () => {
    await pasang(ProfilRsPage, {}, '/pengaturan/profil-rs')
    // Orang lain menyimpan lebih dulu.
    server.tangani({
      metode: 'PUT',
      jalur: '/profil-rs',
      kueri: new URLSearchParams(),
      header: { 'if-match': '"1"' },
      isi: { nama: 'RS Lain', kode_kemenkes: null, kelas: null, status_blu: null, kode_ppk_bpjs: null, alamat: null, warna: { brand: '#006937', accent: '#F3C300' }, kontak_admin: { unit: 'IT', kontak: null }, dasar_perubahan: 'uji' },
    })
    await isi('Nama rumah sakit', 'RS Saya')
    await isi('Dasar perubahan', 'SK Direktur 2/2026')
    await klik(tombol('Simpan profil'))
    expect(document.body.textContent).toContain('Profil diubah orang lain')
    await klik(tombol('Muat ulang profil'))
    expect(isian('Nama rumah sakit').value).toBe('RS Lain')
  })
})
