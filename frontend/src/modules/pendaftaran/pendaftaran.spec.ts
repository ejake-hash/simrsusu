import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'
import { uid } from '@/modules/akses/tiruan/dataPengaturan'
import { pasangTiruan } from '@/modules/akses/tiruan/pasangTiruan'
import { penggunaTiruan } from '@/modules/akses/tiruan/pengguna'
import { buatServerGabungan } from '@/modules/akses/tiruan/serverGabungan'
import { ID_SUPERADMIN, type PermintaanTiruan } from '@/modules/akses/tiruan/serverTiruan'
import { ApiError, klien } from '@/shared/api/klien'
import { aturIzin, vCan } from '@/shared/composables/usePermission'
import { buatPasien, cariPasien, ubahPasien } from './api'
import PasienPage from './pages/PasienPage.vue'

const adapterAsli = klien.defaults.adapter
let permintaan: PermintaanTiruan[]
let gabungan: ReturnType<typeof buatServerGabungan>
const IZIN_PENUH = ['pendaftaran.pasien.lihat', 'pendaftaran.pasien.input', 'pendaftaran.pasien.perbaiki', 'pendaftaran.pasien.identitas.lihat']
/** Akun contoh.admisi (uid 2): peran Petugas Admisi tanpa bagian identitas → server mengirim NIK termasking. */
const ID_ADMISI = uid(2)

function pasangServer() {
  setActivePinia(createPinia())
  gabungan = buatServerGabungan()
  const server = pasangTiruan(klien, gabungan)
  const tangani = server.tangani.bind(server)
  permintaan = []
  server.tangani = (r: PermintaanTiruan) => (permintaan.push(r), tangani(r))
  useSesiStore().pengguna = penggunaTiruan({ id: ID_SUPERADMIN, nama_pengguna: 'contoh.pendaftaran', mfa_aktif: true })
}
beforeEach(() => {
  pasangServer()
  aturIzin(IZIN_PENUH)
})
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  document.body.innerHTML = ''
})

async function tenang(ms = 10) {
  for (let i = 0; i < 6; i++) {
    await flushPromises()
    await new Promise((r) => setTimeout(r, ms))
  }
}
async function pasang() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/pendaftaran/pasien')
  mount(PasienPage, {
    attachTo: document.body,
    global: { plugins: [router, [VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]], directives: { can: vCan } },
  })
  await tenang()
}
const tombol = (teks: string) => [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === teks) as HTMLButtonElement | undefined
const klik = async (el: HTMLElement | null | undefined) => {
  expect(el, 'elemen ada').toBeTruthy()
  el!.click()
  await tenang()
}
function isian(label: string): HTMLInputElement | HTMLTextAreaElement {
  const lbl = [...document.querySelectorAll('[role="dialog"] label[for], [role="alertdialog"] label[for]')].find((l) => l.textContent?.trim().replace(/\s*\*$/, '') === label)
  expect(lbl, `label ${label}`).toBeTruthy()
  return document.getElementById(lbl!.getAttribute('for')!) as HTMLInputElement
}
async function isi(label: string, nilai: string) {
  const el = isian(label)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await tenang()
}
async function ketikCari(nilai: string) {
  const el = document.getElementById('cari-pasien') as HTMLInputElement
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  await tenang(80)
}
const noRmDiTabel = () => [...document.querySelectorAll('tbody td code')].map((c) => c.textContent?.replace(/\./g, ''))

describe('klien cari pasien (X-Cari, bukan query string)', () => {
  it('kata cari dikirim di header X-Cari, tidak pernah di URL/query', async () => {
    await cariPasien('1271010101800042')
    const p = permintaan[permintaan.length - 1]!
    expect(p.header['x-cari']).toBe(encodeURIComponent('1271010101800042'))
    expect(p.kueri.get('q')).toBeNull()
    expect([...p.kueri.keys()]).not.toContain('X-Cari')
  })

  it('16 digit → NIK cocok persis; 13 digit → No. kartu; 1–12 digit → No. RM', async () => {
    const nik = await cariPasien('1271010101800042')
    expect(nik.meta.jenis_cari).toBe('nik')
    expect(nik.data).toHaveLength(1)
    const kartu = await cariPasien('0001234567890')
    expect(kartu.meta.jenis_cari).toBe('no_kartu')
    expect(kartu.data[0]?.no_rm).toBe(nik.data[0]?.no_rm)
    const rm = await cariPasien(String(Number(nik.data[0]!.no_rm)))
    expect(rm.meta.jenis_cari).toBe('no_rm')
    expect(rm.data).toHaveLength(1)
  })

  it('angka panjang lain (14/15/>16) → 422 kata_cari_tidak_dikenali; nama tanpa tanggal → 422', async () => {
    await expect(cariPasien('12345678901234')).rejects.toMatchObject({ status: 422, kode: 'kata_cari_tidak_dikenali' })
    await expect(cariPasien('Contoh Pasien')).rejects.toMatchObject({ status: 422, kode: 'kata_cari_tidak_dikenali' })
  })

  it('nama + tanggal lahir → trigram, tanggal sama persis', async () => {
    const hasil = await cariPasien('Contoh Pasien Satu 12-03-1980')
    expect(hasil.meta.jenis_cari).toBe('nama_tanggal_lahir')
    expect(hasil.data).toHaveLength(1)
    const salah = await cariPasien('Contoh Pasien Satu 01-01-2000')
    expect(salah.data).toHaveLength(0)
  })

  it('respons pencarian tidak di-cache (Cache-Control: no-store)', async () => {
    const r = await klien.get('/pasien', { headers: { 'X-Cari': encodeURIComponent('1271010101800042') } })
    expect(r.headers['cache-control']).toBe('no-store')
  })
})

describe('Layar pendaftaran pasien', () => {
  it('hasil muncul setelah mengetik; kata cari lewat X-Cari, bukan query string', async () => {
    await pasang()
    expect(noRmDiTabel()).toHaveLength(0)
    await ketikCari('1271010101800042')
    expect(noRmDiTabel().length).toBeGreaterThan(0)
    const cari = permintaan.filter((p) => p.jalur === '/pasien' && p.metode === 'get')
    expect(cari.length).toBeGreaterThan(0)
    // Kata cari hanya di header X-Cari; query string hanya kursor/batas, tidak memuat NIK.
    for (const p of cari) {
      expect(p.header['x-cari']).toBeTruthy()
      expect([...p.kueri.keys()].every((k) => ['kursor', 'batas'].includes(k))).toBe(true)
      expect([...p.kueri.values()].some((v) => v.includes('1271'))).toBe(false)
    }
  })

  it('akun tanpa bagian identitas menerima NIK termasking dari server (bukan disembunyikan di layar)', async () => {
    gabungan.akses.sebagai(ID_ADMISI)
    const hasil = await cariPasien('1271010101800042')
    const p = hasil.data[0]!
    expect(p.nik).toBeUndefined()
    expect(p.nik_samaran).toBeTruthy()
    expect(p.nik_samaran).toContain('•')
    expect(p.nik_samaran).not.toBe('1271010101800042')
  })

  it('NIK yang sama untuk pasien kedua → 409 dengan rujukan No. RM yang ada', async () => {
    const ada = await cariPasien('1271010101800042')
    const noRm = ada.data[0]!.no_rm
    try {
      await buatPasien({ nama: 'Contoh Kembar', tanggal_lahir: '1980-03-12', jenis_kelamin: 'laki_laki', nik: '1271010101800042' })
      throw new Error('seharusnya 409')
    } catch (e) {
      expect(e).toBeInstanceOf(ApiError)
      expect((e as ApiError).status).toBe(409)
      expect((e as ApiError).kode).toBe('nik_sudah_terdaftar')
      expect((e as ApiError).rincian[0]).toMatchObject({ no_rm: noRm })
    }
  })

  it('pasien tanpa NIK (Mr. X) tidak diperiksa NIK dan tetap mendapat No. RM', async () => {
    const a = await buatPasien({ jenis_kelamin: 'laki_laki', tanpa_identitas: true, ciri_pengenal: 'Laki-laki ~40 th, ditemukan di IGD' })
    expect(a.data.no_rm).toMatch(/^\d+$/)
    expect(a.data.nama).toBe('Mr. X')
    expect(a.data.tanpa_identitas).toBe(true)
    // Mr. X kedua juga dapat No. RM berbeda (tidak dibandingkan lewat NIK).
    const b = await buatPasien({ jenis_kelamin: 'perempuan', tanpa_identitas: true })
    expect(b.data.nama).toBe('Ny. X')
    expect(b.data.no_rm).not.toBe(a.data.no_rm)
  })

  it('Mr. X bisa dilengkapi kemudian; perbaikan membuat versi baru dengan jejak', async () => {
    const mrx = await buatPasien({ jenis_kelamin: 'tidak_diketahui', tanpa_identitas: true })
    expect(mrx.data.versi).toBe('1')
    const lengkap = await ubahPasien(mrx.data.id, mrx.etag, {
      tanpa_identitas: false,
      nama: 'Contoh Terlacak',
      tanggal_lahir: '1992-05-05',
      jenis_kelamin: 'laki_laki',
      alasan_perubahan: 'Identitas diketahui dari keluarga',
    })
    expect(lengkap.data.tanpa_identitas).toBe(false)
    expect(lengkap.data.nama).toBe('Contoh Terlacak')
    expect(Number(lengkap.data.versi)).toBe(2)
  })

  it('tanpa_identitas tidak bisa kembali true; If-Match salah → 412', async () => {
    const p = await cariPasien('1271010101800042')
    const t = p.data[0]!
    await expect(ubahPasien(t.id, '"1"', { tanpa_identitas: true, alasan_perubahan: 'uji kembali tanpa identitas' })).rejects.toMatchObject({ status: 422 })
    await expect(ubahPasien(t.id, '"999"', { tempat_lahir: 'Kota Lain', alasan_perubahan: 'uji versi berubah' })).rejects.toMatchObject({ status: 412, kode: 'versi_berubah' })
  })

  it('If-Match wajib (428); No. kartu BPJS ganda → 409 no_kartu_sudah_terdaftar dengan rujukan', async () => {
    const p = await cariPasien('1271010101800042')
    const t = p.data[0]!
    await expect(ubahPasien(t.id, '', { tempat_lahir: 'Kota Lain', alasan_perubahan: 'uji if-match wajib' })).rejects.toMatchObject({ status: 428, kode: 'if_match_wajib' })
    try {
      await buatPasien({ nama: 'Contoh Kartu Ganda', tanggal_lahir: '1991-01-01', jenis_kelamin: 'perempuan', no_kartu_bpjs: '0001234567890' })
      throw new Error('seharusnya 409')
    } catch (e) {
      expect((e as ApiError).kode).toBe('no_kartu_sudah_terdaftar')
      expect((e as ApiError).rincian[0]).toMatchObject({ no_rm: t.no_rm })
    }
  })

  it('akun tanpa izin identitas tetap bisa MENGISI NIK pasien baru (petugas menyalin kartu), tetapi tidak MENGUBAH yang terisi', async () => {
    gabungan.akses.sebagai(ID_ADMISI)
    // Mengisi NIK saat daftar baru tidak butuh izin bagian identitas (kontrak POST).
    const baru = await buatPasien({ nama: 'Contoh WNI Baru', tanggal_lahir: '1995-05-05', jenis_kelamin: 'laki_laki', nik: '3201019999990001' })
    expect(baru.data.nik_samaran).toBeTruthy()
    expect(baru.data.nik).toBeUndefined()
    // Mengubah NIK yang sudah terisi butuh izin identitas → 403.
    await expect(ubahPasien(baru.data.id, baru.etag, { nik: '3201010000000002', alasan_perubahan: 'uji ubah nik tanpa izin' })).rejects.toMatchObject({ status: 403, kode: 'akses_ditolak' })
  })

  it('daftarkan pasien baru dari layar: POST terkirim dan No. RM baru muncul di pencarian', async () => {
    await pasang()
    await klik(tombol('Pasien baru'))
    await isi('Nama lengkap', 'Contoh Pasien Baru')
    await isi('Tanggal lahir', '2001-07-07')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-pasien"]'))
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur === '/pasien')).toBe(true)
    await tenang(120)
    expect(noRmDiTabel().length).toBeGreaterThan(0)
  })

  it('Mr. X lewat sakelar di layar: nama/tanggal lahir disembunyikan, server mengisi Mr. X', async () => {
    await pasang()
    await klik(tombol('Pasien baru'))
    const sakelar = document.querySelector<HTMLInputElement>('.sakelar-identitas input[type="checkbox"]')
    sakelar!.click()
    await tenang()
    // Tanpa identitas: tidak ada kolom "Nama lengkap" wajib di dialog.
    expect([...document.querySelectorAll('[role="dialog"] label')].some((l) => l.textContent?.trim().startsWith('Nama lengkap'))).toBe(false)
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-pasien"]'))
    const post = permintaan.find((p) => p.metode === 'post' && p.jalur === '/pasien')
    expect(post).toBeTruthy()
    expect((post!.isi as { tanpa_identitas?: boolean }).tanpa_identitas).toBe(true)
  })

  it('nama mirip + tanggal lahir sama memunculkan peringatan sebelum simpan; baru tersimpan setelah dikonfirmasi', async () => {
    await pasang()
    await klik(tombol('Pasien baru'))
    await isi('Nama lengkap', 'Contoh Pasien Satu')
    await isi('Tanggal lahir', '1980-03-12')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-pasien"]'))
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur === '/pasien/cek-duplikat')).toBe(true)
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur === '/pasien')).toBe(false)
    expect(document.querySelector('[data-uji="peringatan-duplikat"]')?.textContent).toContain('Contoh Pasien Satu')
    // Tombol berubah menjadi konfirmasi; menekannya menyimpan tanpa memeriksa ulang.
    await klik(tombol('Tetap daftarkan sebagai pasien baru') ?? null)
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur === '/pasien')).toBe(true)
  })

  it('tanpa kemiripan, pasien langsung tersimpan tanpa peringatan', async () => {
    await pasang()
    await klik(tombol('Pasien baru'))
    await isi('Nama lengkap', 'Zzz Tidak Ada Kembar')
    await isi('Tanggal lahir', '1980-03-12')
    await klik(document.querySelector<HTMLButtonElement>('button[form="form-pasien"]'))
    expect(document.querySelector('[data-uji="peringatan-duplikat"]')).toBeNull()
    expect(permintaan.some((p) => p.metode === 'post' && p.jalur === '/pasien')).toBe(true)
  })
})
