import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { daftarToast, tutupToast } from '@/design-system/components/toast'
import { klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import RincianAkun from './components/pengaturan/RincianAkun.vue'
import RincianPersetujuan from './components/persetujuan/RincianPersetujuan.vue'
import TabPermintaan from './components/persetujuan/TabPermintaan.vue'
import { indeksPohon } from './matriks'
import { uid } from './tiruan/dataPengaturan'
import { pasangTiruan } from './tiruan/pasangTiruan'
import { penggunaTiruan } from './tiruan/pengguna'
import { buatServerTiruan, ID_KABID, ID_SUPERADMIN, ID_SUPERADMIN_DUA, type ServerTiruan } from './tiruan/serverTiruan'
import type { PersetujuanAkses } from './tipePersetujuan'

const adapterAsli = klien.defaults.adapter
const KASIR = uid(105)
const ADMISI = uid(2)
let server: ServerTiruan
let router: Router

beforeEach(() => {
  setActivePinia(createPinia())
  server = pasangTiruan(klien, buatServerTiruan())
  masukSebagai(ID_SUPERADMIN, 'contoh.superadmin')
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
  daftarToast.value.forEach((t) => tutupToast(t.id))
})

function masukSebagai(id: string, nama: string) {
  server.sebagai(id)
  useSesiStore().pengguna = penggunaTiruan({ id, nama_pengguna: nama, mfa_aktif: true })
  aturIzin(
    id === ID_KABID
      ? ['pengaturan.persetujuan.lihat']
      : ['pengaturan.peran.lihat', 'pengaturan.peran.perbaiki', 'pengaturan.akun.lihat', 'pengaturan.akun.input', 'pengaturan.akun.perbaiki', 'pengaturan.persetujuan.lihat'],
  )
}
// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function pasang(komponen: any, props: Record<string, unknown> = {}) {
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/')
  mount(komponen, {
    props,
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
}
async function tenang() {
  for (let i = 0; i < 4; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, 10))
  }
}
const tombol = (teks: string, dalam: ParentNode = document) => [...dalam.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
async function klik(el: HTMLElement | undefined) {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
async function isi(label: string, nilai: string) {
  const lbl = [...document.querySelectorAll('label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  const el = document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang()
}
/** Ajukan izin sensitif kasir.tagihan.batal ke peran Kasir lewat server (sebagai superadmin). */
function ajukanIzinSensitif(): PersetujuanAkses {
  const minta = (metode: string, jalur: string, isiMinta?: unknown, header: Record<string, string> = {}) =>
    server.tangani({ metode, jalur, kueri: new URLSearchParams(), header, isi: isiMinta })!
  const awal = minta('GET', `/peran/${KASIR}`)
  const izin = [...(awal.isi as { data: { izin: string[] } }).data.izin, 'kasir.tagihan.batal']
  const r = minta('PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 1/2026' }, { 'if-match': awal.header!.ETag! })
  return (r.isi as { perubahan: { menunggu_persetujuan: PersetujuanAkses[] } }).perubahan.menunggu_persetujuan[0]!
}

describe('Rincian persetujuan (MD-4)', () => {
  it('superadmin pengaju: tanpa tombol Setujui/Tolak, ada Tarik pengajuan dan keterangan penyetuju', async () => {
    const p = ajukanIzinSensitif()
    await pasang(RincianPersetujuan, { id: p.id })
    expect(document.querySelector('#judul-rincian-persetujuan')?.textContent).toBe('Tambah izin Kasir → Tagihan pasien → Batal ke peran Contoh Kasir')
    expect(tombol('Setujui')).toBeUndefined()
    expect(tombol('Tolak')).toBeUndefined()
    expect(tombol('Tarik pengajuan')).toBeTruthy()
    expect(document.body.textContent).toContain('Diputuskan oleh pemegang peran Contoh Kabid Keuangan. Pengaju tidak bisa menyetujui pengajuannya sendiri.')
  })

  it('penyetuju yang ditunjuk: setujui → berlaku; toast menyebut nomornya', async () => {
    const p = ajukanIzinSensitif()
    masukSebagai(ID_KABID, 'contoh.kabid')
    await pasang(RincianPersetujuan, { id: p.id })
    await klik(tombol('Setujui'))
    const dialog = document.querySelector('[role="dialog"]')!
    expect(dialog.textContent).toContain('Perubahan langsung berlaku untuk 1 akun')
    await klik(tombol('Setujui', dialog))
    expect(daftarToast.value.map((t) => t.pesan)).toContain(`${p.nomor} disetujui dan sudah berlaku.`)
    expect(document.body.textContent).toContain('Sudah berlaku')
    expect(tombol('Setujui')).toBeUndefined()
  })

  it('tolak butuh alasan minimal 5 karakter', async () => {
    const p = ajukanIzinSensitif()
    masukSebagai(ID_KABID, 'contoh.kabid')
    await pasang(RincianPersetujuan, { id: p.id })
    await klik(tombol('Tolak'))
    const dialog = document.querySelector('[role="alertdialog"]')!
    await klik(tombol('Tolak', dialog))
    expect(dialog.textContent).toContain('Tulis alasan penolakan')
    await isi('Alasan penolakan', 'Belum ada SK pemberian')
    await klik(tombol('Tolak', dialog))
    expect(document.querySelector('.status')?.textContent).toContain('Ditolak')
  })

  it('akun yang tidak terkait → 404 ditampilkan sebagai tidak ditemukan', async () => {
    const p = ajukanIzinSensitif()
    masukSebagai(uid(4), 'contoh.dokter')
    await pasang(RincianPersetujuan, { id: p.id })
    expect(document.body.textContent).toContain('Pengajuan tidak ditemukan atau bukan untuk Anda.')
  })
})

describe('Rincian akun: pengajuan menunggu (1.2d)', () => {
  it('penugasan superadmin 202 tampil di "Menunggu persetujuan", bukan di penugasan berlaku; pengaju bisa menariknya', async () => {
    server.tangani({ metode: 'POST', jalur: `/akun/${ADMISI}/penugasan`, kueri: new URLSearchParams(), header: {}, isi: { peran_id: uid(101), cakupan_jenis: 'rs', cakupan_id: null, dasar_pemberian: 'SK 9/2026' } })
    await pasang(RincianAkun, { akunId: ADMISI, indeks: indeksPohon(server.pohon) })
    const menunggu = document.querySelector('section[aria-labelledby="judul-menunggu"]')!
    expect(menunggu.textContent).toContain('Tugaskan peran Superadmin ke Contoh Petugas Admisi (Seluruh RS)')
    expect(menunggu.textContent).toContain('Belum berlaku')
    await klik(tombol('Tarik pengajuan', menunggu))
    await isi('Alasan menarik', 'Salah memilih akun')
    await klik(tombol('Tarik pengajuan', document.querySelector('[role="alertdialog"]')!))
    expect(document.querySelector('section[aria-labelledby="judul-menunggu"]')).toBeNull()
  })

  it('superadmin kedua (bukan pengaju) melihat ringkasan tanpa tombol tarik', async () => {
    server.tangani({ metode: 'POST', jalur: `/akun/${ADMISI}/penugasan`, kueri: new URLSearchParams(), header: {}, isi: { peran_id: uid(101), cakupan_jenis: 'rs', cakupan_id: null, dasar_pemberian: 'SK 9/2026' } })
    masukSebagai(ID_SUPERADMIN_DUA, 'contoh.superadmin.dua')
    await pasang(RincianAkun, { akunId: ADMISI, indeks: indeksPohon(server.pohon) })
    const menunggu = document.querySelector('section[aria-labelledby="judul-menunggu"]')!
    expect(menunggu.textContent).toContain('Tugaskan peran Superadmin')
    expect(tombol('Tarik pengajuan', menunggu)).toBeUndefined()
  })
})

describe('Permintaan akses', () => {
  it('admin menindaklanjuti dengan peran: dasar = nomor permintaan; status menjadi ditindaklanjuti', async () => {
    masukSebagai(ADMISI, 'contoh.admisi')
    server.tangani({ metode: 'POST', jalur: '/permintaan-akses', kueri: new URLSearchParams(), header: {}, isi: { sasaran_jenis: 'menu', sasaran_kode: 'igd.papan', alasan: 'Bertugas di IGD bulan ini' } })
    masukSebagai(ID_SUPERADMIN, 'contoh.superadmin')
    await pasang(TabPermintaan)
    const baris = [...document.querySelectorAll('tbody tr')]
    expect(baris).toHaveLength(1)
    expect(baris[0]!.textContent).toContain('IGD → Papan pasien IGD')
    await klik([...baris[0]!.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Tugaskan peran'))
    const dialog = document.querySelector('[role="dialog"]')!
    expect(dialog.textContent).toMatch(/Menindaklanjuti permintaan PMA-2026-\d+/)
    const lbl = [...dialog.querySelectorAll('label[for]')].find((l) => l.textContent?.startsWith('Dasar pemberian'))!
    expect((document.getElementById(lbl.getAttribute('for')!) as HTMLInputElement).value).toMatch(/^PMA-2026-\d+$/)
    const peran = document.getElementById([...dialog.querySelectorAll('label[for]')].find((l) => l.textContent?.startsWith('Peran'))!.getAttribute('for')!) as HTMLSelectElement
    peran.value = [...peran.options].find((o) => o.textContent?.includes('Contoh Perawat'))!.value
    peran.dispatchEvent(new Event('change'))
    await tenang()
    await klik(tombol('Tugaskan', dialog))
    expect(daftarToast.value.map((t) => t.pesan).some((x) => /PMA-2026-\d+: Peran ditugaskan\./.test(x))).toBe(true)
    expect(document.body.textContent).toContain('Tidak ada permintaan akses.')
  })
})
