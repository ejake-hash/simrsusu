import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError, AxiosHeaders, type AxiosAdapter } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import DialogMfaUlang from '@/app/DialogMfaUlang.vue'
import { jawabMfaUlang, mintaMfaUlang } from '@/app/mfaUlang'
import { useSesiStore } from '@/app/sesi'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { aturPenanganMfaUlang, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import RincianAkun from './components/pengaturan/RincianAkun.vue'
import TabPenyetujuLabel from './components/persetujuan/TabPenyetujuLabel.vue'
import { indeksPohon } from './matriks'
import { uid } from './tiruan/dataPengaturan'
import { pasangTiruan } from './tiruan/pasangTiruan'
import { penggunaTiruan } from './tiruan/pengguna'
import { buatServerTiruan, ID_KABID, ID_SUPERADMIN, ID_SUPERADMIN_DUA, type ServerTiruan } from './tiruan/serverTiruan'
import type { PersetujuanAkses } from './tipePersetujuan'

/** Tiket 1.5 fe: penyetuju label, izin label akun (persetujuan pejabat PDP), dialog MFA ulang. Server tiruan kontrak v0.14.0. */
const adapterAsli = klien.defaults.adapter
const ADMISI = uid(2)
const KABID = uid(108)
let server: ServerTiruan
const terpasang: VueWrapper[] = []

beforeEach(() => {
  // Masa verifikasi dari uji sebelumnya tidak boleh terbawa.
  jawabMfaUlang(false)
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerTiruan())
  masukSebagai(ID_SUPERADMIN, 'contoh.superadmin')
})
afterEach(() => {
  terpasang.splice(0).forEach((w) => w.unmount())
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

function masukSebagai(id: string, nama: string) {
  server.sebagai(id)
  useSesiStore().pengguna = penggunaTiruan({ id, nama_pengguna: nama, mfa_aktif: true })
  aturIzin(['pengaturan.peran.lihat', 'pengaturan.peran.perbaiki', 'pengaturan.akun.lihat', 'pengaturan.akun.input', 'pengaturan.akun.perbaiki', 'pengaturan.persetujuan.lihat'])
}
const minta = (metode: string, jalur: string, isiMinta?: unknown, kueri = '') => server.tangani({ metode, jalur, kueri: new URLSearchParams(kueri), header: {}, isi: isiMinta })!
// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  terpasang.push(mount(komponen, {
    props,
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  }))
  await tenang()
}
async function tenang() {
  for (let i = 0; i < 4; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, 10))
  }
}
const tombol = (teks: string, dalam: ParentNode = document) => [...dalam.querySelectorAll('button, a')].find((b) => b.textContent?.trim() === teks) as HTMLElement | undefined
async function klik(el: HTMLElement | undefined) {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
function kolom(label: string): HTMLInputElement {
  const lbl = [...document.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
/** Tab reka-ui berpindah pada mousedown. */
async function bukaTab(nama: string) {
  const tab = [...document.querySelectorAll('[role="tab"]')].find((t) => t.textContent === nama) as HTMLElement | undefined
  expect(tab, `tab ${nama}`).toBeTruthy()
  tab!.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
  await tenang()
}
async function isi(label: string, nilai: string) {
  const el = kolom(label)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await tenang()
}
function tanggalDepan(hari: number): string {
  const d = new Date(Date.now() + hari * 86_400_000)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
/** Superadmin kedua menyetujui pengajuan penyetuju label (kontrol ganda K13). */
function setujuiSebagai(id: string, nama: string, persetujuanId: string) {
  masukSebagai(id, nama)
  const r = minta('POST', `/persetujuan-akses/${persetujuanId}/setujui`, { catatan: null })
  expect(r.status).toBe(200)
}
function persetujuanTerakhir(): PersetujuanAkses {
  const r = minta('GET', '/persetujuan-akses', undefined, 'tab=saya_ajukan')
  return (r.isi as { data: PersetujuanAkses[] }).data[0]!
}

describe('Penyetuju label sensitivitas', () => {
  it('label tanpa penyetuju ditandai; menetapkan penyetuju menunggu superadmin lain lalu berlaku', async () => {
    await pasang(TabPenyetujuLabel)
    const tabel = document.querySelector('section[aria-labelledby="judul-penyetuju-label"]')!
    expect(tabel.textContent).toContain('HIV')
    expect(tabel.querySelectorAll('.pil-peringatan')).toHaveLength(5)

    await klik(document.querySelector<HTMLElement>('button[aria-label="Tetapkan penyetuju label HIV"]')!)
    await isi('Peran penyetuju', KABID)
    await isi('Dasar perubahan', 'SK PDP 1/2026')
    await klik(tombol('Ajukan', document.querySelector('[role="dialog"]')!))
    expect(daftarToast.value.map((t) => t.pesan).join(' ')).toMatch(/Penyetuju label HIV menunggu persetujuan Superadmin \(PSA-/)

    setujuiSebagai(ID_SUPERADMIN_DUA, 'contoh.superadmin.dua', persetujuanTerakhir().id)
    const label = (minta('GET', '/label-sensitivitas').isi as { data: { kode: string; penyetuju_peran: { id: string } | null }[] }).data
    expect(label.find((l) => l.kode === 'hiv')?.penyetuju_peran?.id).toBe(KABID)
  })
})

describe('Izin label akun', () => {
  it('ajukan → menunggu pejabat PDP → disetujui berlaku → cabut dengan alasan menjadi riwayat', async () => {
    minta('PUT', '/label-sensitivitas/hiv/penyetuju', { peran_id: KABID, dasar_perubahan: 'SK PDP 1/2026' })
    setujuiSebagai(ID_SUPERADMIN_DUA, 'contoh.superadmin.dua', persetujuanTerakhir().id)
    masukSebagai(ID_SUPERADMIN, 'contoh.superadmin')

    await pasang(RincianAkun, { akunId: ADMISI, indeks: indeksPohon(server.pohon) })
    await bukaTab('Label')
    expect(document.body.textContent).toContain('Akun ini tidak punya izin label yang berlaku.')
    await klik(tombol('Ajukan izin label'))
    const dialog = document.querySelector('[role="alertdialog"]')!
    // Label tanpa penyetuju tidak bisa dipilih.
    const opsi = [...kolom('Label sensitivitas').querySelectorAll('option')]
    expect(opsi.find((o) => o.value === 'psikiatri')?.disabled).toBe(true)
    expect(opsi.find((o) => o.value === 'hiv')?.disabled).toBe(false)

    await klik(tombol('Ajukan', dialog))
    expect(dialog.textContent).toContain('Pilih label.')
    await isi('Label sensitivitas', 'hiv')
    expect(dialog.textContent).toContain('Disetujui oleh pemegang peran Contoh Kabid Keuangan')
    await isi('Berlaku sampai', tanggalDepan(400))
    await isi('Dasar pemberian', 'ST 7/2026')
    await isi('Alasan', 'Konselor VCT di poli penyakit dalam')
    await klik(tombol('Ajukan', dialog))
    expect(dialog.textContent).toContain('Pilih tanggal antara besok dan')
    // Hari ke-365 pukul 23.59 melewati batas server (sekarang + 365 hari); hari ke-364 tanggal terakhir yang diterima.
    await isi('Berlaku sampai', tanggalDepan(365))
    await klik(tombol('Ajukan', dialog))
    expect(dialog.textContent).toContain('paling lama 365 hari')
    await isi('Berlaku sampai', tanggalDepan(364))
    await klik(tombol('Ajukan', dialog))
    expect(document.body.textContent).toMatch(/Izin label HIV untuk Contoh Petugas Admisi menunggu persetujuan Contoh Kabid Keuangan \(PSA-/)
    expect(document.querySelector('ul[aria-label="Menunggu persetujuan"]')?.textContent).toContain('HIV')
    // Pengaju melihat tautan ke pengajuannya sendiri.
    expect(tombol('Lihat pengajuan')).toBeTruthy()

    const izinLabel = persetujuanTerakhir()
    expect(izinLabel.jenis).toBe('izin_label')
    setujuiSebagai(ID_KABID, 'contoh.kabid', izinLabel.id)
    masukSebagai(ID_SUPERADMIN, 'contoh.superadmin')
    terpasang.splice(0).forEach((w) => w.unmount())
    document.body.innerHTML = ''
    await pasang(RincianAkun, { akunId: ADMISI, indeks: indeksPohon(server.pohon) })
    await bukaTab('Label')
    const berlaku = document.querySelector('ul[aria-label="Izin label yang berlaku"]')!
    expect(berlaku.textContent).toContain('HIV')

    await klik(tombol('Cabut', berlaku))
    const cabut = document.querySelector('[role="alertdialog"]')!
    expect(cabut.textContent).toContain('Contoh Petugas Admisi langsung kehilangan akses ke data berlabel HIV.')
    await isi('Alasan mencabut', 'Pindah tugas ke bagian lain')
    await klik(tombol('Cabut', cabut))
    expect(document.querySelector('ul[aria-label="Izin label yang berlaku"]')).toBeNull()
    expect(document.querySelector('details.riwayat')?.textContent).toContain('Pindah tugas ke bagian lain')
  })
})

describe('Dialog MFA ulang', () => {
  it('kode salah tetap di dialog; kode benar menjawab permintaan dan menutup dialog', async () => {
    await pasang(DialogMfaUlang)
    const janji = mintaMfaUlang()
    await tenang()
    const dialog = document.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('Verifikasi ulang diperlukan')
    expect(document.activeElement).toBe(kolom('Kode dari aplikasi autentikator'))

    await isi('Kode dari aplikasi autentikator', '12')
    await klik(tombol('Verifikasi', dialog))
    expect(dialog.textContent).toContain('Masukkan 6 digit dari aplikasi autentikator.')

    await isi('Kode dari aplikasi autentikator', '000000')
    await klik(tombol('Verifikasi', dialog))
    expect(dialog.textContent).toContain('Kode salah. Periksa kode terbaru lalu coba lagi.')
    expect(kolom('Kode dari aplikasi autentikator').value).toBe('')

    await isi('Kode dari aplikasi autentikator', '123 456')
    await klik(tombol('Verifikasi', dialog))
    await expect(janji).resolves.toBe('dialog')
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('alur terpadu: 403 mfa_ulang_diperlukan → dialog → kode → permintaan yang sama diulang otomatis', async () => {
    aturPenanganMfaUlang(mintaMfaUlang)
    await pasang(DialogMfaUlang)
    let panggilan = 0
    const adapter: AxiosAdapter = async (config) => {
      panggilan++
      if (!config.sudahMfaUlang) {
        throw new AxiosError('gagal', 'ERR', config, null, {
          status: 403, statusText: '', headers: {}, config: { ...config, headers: new AxiosHeaders() },
          data: { error: { kode: 'mfa_ulang_diperlukan', pesan: 'Verifikasi MFA ulang diperlukan.', rincian: [] } },
        })
      }
      return { data: { data: { status: 'diterapkan' } }, status: 200, statusText: 'OK', headers: {}, config }
    }
    const aksi = klien.post('/uji/aksi-butuh-mfa', { x: 1 }, { adapter })
    await tenang()
    const dialog = document.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('Verifikasi ulang diperlukan')
    await isi('Kode dari aplikasi autentikator', '654321')
    await klik(tombol('Verifikasi', dialog))
    await expect(aksi).resolves.toMatchObject({ data: { data: { status: 'diterapkan' } } })
    expect(panggilan).toBe(2)
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('Batalkan aksi menjawab false', async () => {
    await pasang(DialogMfaUlang)
    const janji = mintaMfaUlang()
    await tenang()
    await klik(tombol('Batalkan aksi'))
    await expect(janji).resolves.toBe(false)
  })
})
