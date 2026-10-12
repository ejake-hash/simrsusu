import { mkdirSync } from 'node:fs'
import { resolve } from 'node:path'
import AxeBuilder from '@axe-core/playwright'
import { expect, test, type APIRequestContext, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Tiket 1.12-fe: e2e menyeluruh semua layar fase 1 di 1440/1024/768/390 px + axe (tanpa pelanggaran serius/kritis,
 * tanpa gulir samping halaman). Berjalan sebagai proyek Playwright `fase1` sesudah semua spec lain (data sintetis
 * sudah terisi). Setiap layar dibuka dengan akun sintetis pemegang izinnya; daftar halaman dicocokkan dengan menu
 * server (`/saya/menu`) agar halaman TERSEDIA yang baru tidak terlewat.
 *
 * Tangkapan layar hanya bila E2E_TANGKAPAN=1 → docs/lampiran/fase1/<layar>-<lebar>.png. Repo publik: database e2e
 * harus berisi identitas fiktif saja (paket "RS Contoh Pendidikan", akun contoh.*), tanpa data RS nyata.
 */
const LEBAR = [1440, 1024, 768, 390] as const
const TANGKAPAN = process.env.E2E_TANGKAPAN === '1'
const DIR_TANGKAPAN = resolve(__dirname, '../../docs/lampiran/fase1')
const TANPA_SESI = { cookies: [], origins: [] }

interface Akun {
  nama: string
  rahasia?: string
}
const AKUN = {
  superadmin: { nama: 'contoh.superadmin', rahasia: process.env.E2E_SUPERADMIN_MFA_RAHASIA },
  dataInduk: { nama: 'contoh.data.induk' },
  pengusul: { nama: 'contoh.usulan.pengusul' },
  sdm: { nama: 'contoh.sdm', rahasia: process.env.E2E_SDM_MFA_RAHASIA },
  ttd: { nama: 'contoh.ttd' },
  integrasi: { nama: 'contoh.integrasi', rahasia: process.env.E2E_INTEGRASI_MFA_RAHASIA },
} satisfies Record<string, Akun>

interface Layar {
  /** Nama berkas tangkapan dan judul uji. */
  kode: string
  jalur: string
  akun: keyof typeof AKUN
  /** Penanda halaman sudah termuat (heading utama). */
  judul: string | RegExp
}
/** Semua layar fase 1 yang dibangun (tiket 1.1–1.9). Halaman menu yang TERSEDIA di server wajib ada di sini. */
const LAYAR: Layar[] = [
  { kode: 'beranda', jalur: '/', akun: 'superadmin', judul: /Selamat (pagi|siang|sore|malam)/ },
  { kode: 'notifikasi', jalur: '/notifikasi', akun: 'superadmin', judul: 'Notifikasi' },
  { kode: 'pengaturan-peran', jalur: '/pengaturan/peran', akun: 'superadmin', judul: 'Peran & izin' },
  { kode: 'pengaturan-akun', jalur: '/pengaturan/akun', akun: 'superadmin', judul: 'Akun & akses' },
  { kode: 'pengaturan-persetujuan', jalur: '/pengaturan/persetujuan', akun: 'superadmin', judul: 'Persetujuan akses' },
  { kode: 'pengaturan-profil-rs', jalur: '/pengaturan/profil-rs', akun: 'superadmin', judul: 'Profil rumah sakit' },
  { kode: 'pengaturan-penyiapan', jalur: '/pengaturan/penyiapan', akun: 'superadmin', judul: 'Penyiapan instalasi' },
  { kode: 'pengaturan-integrasi', jalur: '/pengaturan/integrasi', akun: 'integrasi', judul: 'Konfigurasi integrasi' },
  { kode: 'integrasi-satusehat', jalur: '/integrasi/satusehat', akun: 'integrasi', judul: 'SATUSEHAT' },
  { kode: 'integrasi-bpjs', jalur: '/integrasi/bpjs', akun: 'integrasi', judul: 'Bridging BPJS' },
  { kode: 'integrasi-log', jalur: '/integrasi/log', akun: 'integrasi', judul: 'Log integrasi' },
  { kode: 'organisasi-unit', jalur: '/master-data/organisasi', akun: 'dataInduk', judul: 'Organisasi, Unit, Ruang & Tempat Tidur' },
  { kode: 'organisasi-lokasi', jalur: '/master-data/organisasi?tab=lokasi', akun: 'dataInduk', judul: /Organisasi/ },
  { kode: 'organisasi-bangsal', jalur: '/master-data/organisasi?tab=bangsal', akun: 'dataInduk', judul: /Organisasi/ },
  { kode: 'organisasi-kelas', jalur: '/master-data/organisasi?tab=kelas', akun: 'dataInduk', judul: /Organisasi/ },
  { kode: 'organisasi-poli', jalur: '/master-data/organisasi?tab=poli', akun: 'dataInduk', judul: /Organisasi/ },
  { kode: 'jenis-tugas', jalur: '/master-data/jenis-tugas', akun: 'dataInduk', judul: 'Jenis Tugas, Tenggat & Eskalasi' },
  { kode: 'usulan', jalur: '/master-data/usulan', akun: 'pengusul', judul: 'Usulan perubahan' },
  { kode: 'pegawai', jalur: '/sdm/pegawai', akun: 'sdm', judul: 'Data pegawai' },
  { kode: 'tanda-tangan', jalur: '/tanda-tangan', akun: 'ttd', judul: 'Tanda tangan elektronik' },
]

test.describe.configure({ mode: 'serial' })
test.beforeAll(async () => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  const tanpaRahasia = Object.values(AKUN as Record<string, Akun>).filter((a) => 'rahasia' in a && !a.rahasia).map((a) => a.nama)
  if (tanpaRahasia.length) throw new Error(`Set rahasia TOTP e2e untuk: ${tanpaRahasia.join(', ')}.`)
  if (TANGKAPAN) mkdirSync(DIR_TANGKAPAN, { recursive: true })
  // Spec sebelumnya (pengaturan, penyiapan, pegawai, integrasi) memakai akun ber-MFA yang sama; kode TOTP satu periode
  // tidak boleh dipakai ulang, jadi mulai di periode TOTP berikutnya.
  const sisa = 30_000 - (Date.now() % 30_000)
  await new Promise((r) => setTimeout(r, sisa + 500))
})

const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
/** Satu kali masuk per akun (rate limit masuk 10/menit per nama pengguna). */
const sesi = new Map<string, Awaited<ReturnType<APIRequestContext['storageState']>>>()
async function konteks(browser: Browser, asal: string, akun: Akun, lebar: number): Promise<BrowserContext> {
  if (!sesi.has(akun.nama)) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    await masukLewatApi(masuk.request, asal, akun.nama, akun.rahasia)
    sesi.set(akun.nama, await masuk.storageState())
    await masuk.close()
  }
  return browser.newContext({ baseURL: asal, storageState: sesi.get(akun.nama), viewport: { width: lebar, height: 900 } })
}

// Rate limit API 120/menit per akun: setiap layar memuat beberapa permintaan; jeda bila satu menit terakhir mendekati batas.
const BATAS_PER_MENIT = 80
const waktuPermintaan = new Map<string, number[]>()
async function jagaBatasLaju(akun: string) {
  const daftar = waktuPermintaan.get(akun) ?? []
  const sekarang = Date.now()
  while (daftar.length && sekarang - daftar[0]! > 61_000) daftar.shift()
  waktuPermintaan.set(akun, daftar)
  if (daftar.length <= BATAS_PER_MENIT) return
  const jeda = 61_000 - (sekarang - daftar[0]!)
  test.info().setTimeout(test.info().timeout + jeda)
  await new Promise((r) => setTimeout(r, jeda))
}

async function periksa(page: Page, kode: string, lebar: number) {
  await tanpaGulirSamping(page)
  const hasil = await new AxeBuilder({ page }).analyze()
  const serius = hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').flatMap((v) => v.nodes.map((n) => `${v.id}: ${n.target.join(' ')}`))
  expect(serius, `${kode} @ ${lebar} px`).toEqual([])
  if (TANGKAPAN) await page.screenshot({ path: resolve(DIR_TANGKAPAN, `${kode}-${lebar}.png`), fullPage: true })
}

test('halaman masuk di empat lebar', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  for (const lebar of LEBAR) {
    const k = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI, viewport: { width: lebar, height: 900 } })
    const page = await k.newPage()
    await page.goto('/masuk')
    await expect(page.getByRole('heading', { name: /Masuk/ }).first()).toBeVisible()
    await periksa(page, 'masuk', lebar)
    await k.close()
  }
})

test('daftar layar mencakup semua halaman menu yang TERSEDIA bagi akun sintetis', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const terdaftar = new Set(LAYAR.map((l) => l.jalur.split('?')[0]))
  const kurang: string[] = []
  for (const akun of Object.values(AKUN)) {
    const k = await konteks(browser, asal, akun, 1440)
    const r = await k.request.get('/api/v1/saya/menu', { headers: { Accept: 'application/json' } })
    expect(r.ok(), `${akun.nama} ${r.status()}`).toBeTruthy()
    const menu = (await r.json()) as { grup: { menu: { rute: string; status: string; sub_menu: { rute: string; status: string }[] }[] }[] }
    // Halaman = sub-menu, atau menu tanpa sub-menu (menu induk ber-sub-menu hanya pengelompok).
    const halaman = menu.grup.flatMap((g) => g.menu.flatMap((m) => (m.sub_menu.length ? m.sub_menu : [m])))
    for (const x of halaman) {
      if (x.status === 'aktif' && x.rute !== '/' && !terdaftar.has(x.rute)) kurang.push(`${akun.nama}: ${x.rute}`)
    }
    await k.close()
  }
  expect(kurang, 'halaman TERSEDIA tanpa uji di LAYAR').toEqual([])
})

for (const layar of LAYAR) {
  test(`${layar.kode}: 1440/1024/768/390 px tanpa gulir samping dan tanpa pelanggaran axe serius/kritis`, async ({ browser }, info) => {
    const asal = asalDari(info.project.use.baseURL)
    const akun = AKUN[layar.akun]
    for (const lebar of LEBAR) {
      await jagaBatasLaju(akun.nama)
      const k = await konteks(browser, asal, akun, lebar)
      k.on('request', (r) => {
        if (!r.url().includes('/api/v1/')) return
        if (!waktuPermintaan.has(akun.nama)) waktuPermintaan.set(akun.nama, [])
        waktuPermintaan.get(akun.nama)!.push(Date.now())
      })
      const page = await k.newPage()
      await page.goto(layar.jalur)
      await expect(page.getByRole('heading', { name: layar.judul, level: 1 }).first()).toBeVisible()
      // Tunggu permintaan data awal selesai agar axe memeriksa keadaan terisi, bukan kerangka memuat.
      await page.waitForLoadState('networkidle')
      await periksa(page, layar.kode, lebar)
      await k.close()
    }
  })
}
