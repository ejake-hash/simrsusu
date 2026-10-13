import { mkdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
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
const DIR_TANGKAPAN = fileURLToPath(new URL('../../docs/lampiran/fase1/', import.meta.url))
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

// Bukan serial: satu layar gagal tidak membatalkan pemeriksaan layar lain (sesi per akun tetap dipakai ulang di worker).
test.beforeAll(async ({}, info) => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  const tanpaRahasia = Object.values(AKUN as Record<string, Akun>).filter((a) => 'rahasia' in a && !a.rahasia).map((a) => a.nama)
  if (tanpaRahasia.length) throw new Error(`Set rahasia TOTP e2e untuk: ${tanpaRahasia.join(', ')}.`)
  if (TANGKAPAN) mkdirSync(DIR_TANGKAPAN, { recursive: true })
  // Spec sebelumnya (pengaturan, penyiapan, pegawai, integrasi) memakai akun ber-MFA yang sama; kode TOTP satu periode
  // tidak boleh dipakai ulang, jadi mulai di periode TOTP berikutnya.
  // Penantian bisa sampai 30,5 detik, melebihi batas hook bawaan 30 detik: batas diperpanjang sebesar penantian.
  const sisa = 30_000 - (Date.now() % 30_000)
  info.setTimeout(info.timeout + sisa + 500)
  await new Promise((r) => setTimeout(r, sisa + 500))
})
// Tangkapan layar masuk repo publik: identitas RS harus fiktif ("RS Contoh Pendidikan", docs/brand/contoh), bukan sisa
// profil spec lain.
test.beforeAll(async ({ browser }, info) => {
  if (TANGKAPAN) await pasangIdentitasContoh(browser, asalDari(info.project.use.baseURL))
})

const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
/** Satu kali masuk per akun (rate limit masuk 10/menit per nama pengguna). */
const sesi = new Map<string, Awaited<ReturnType<APIRequestContext['storageState']>>>()
async function konteks(browser: Browser, asal: string, akun: Akun, lebar: number): Promise<BrowserContext> {
  if (!sesi.has(akun.nama)) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    await masukLewatApi(masuk.request, asal, akun.nama, akun.rahasia)
    await tungguJatahPenuh(masuk.request, asal)
    sesi.set(akun.nama, await masuk.storageState())
    await masuk.close()
  }
  // Tanpa animasi: axe memeriksa keadaan akhir, bukan toast/transisi yang masih memudar (color-contrast palsu).
  return browser.newContext({ baseURL: asal, storageState: sesi.get(akun.nama), viewport: { width: lebar, height: 900 }, reducedMotion: 'reduce' })
}

/**
 * Spec sebelumnya (mis. pengaturan) bisa sudah memakai sebagian besar jatah rate limit akun yang sama dalam menit
 * terakhir; SPA menganggap 429 di /saya sebagai belum masuk. Bila sisa jatah kurang dari separuh, tunggu satu jendela.
 */
async function tungguJatahPenuh(request: APIRequestContext, asal: string) {
  const r = await request.get('/api/v1/saya', { headers: { Accept: 'application/json', Origin: asal, Referer: `${asal}/` } })
  const sisa = Number(r.headers()['x-ratelimit-remaining'] ?? 0)
  const batas = Number(r.headers()['x-ratelimit-limit'] ?? 120)
  if (r.status() !== 429 && sisa >= batas / 2) return
  test.info().setTimeout(test.info().timeout + 62_000)
  await new Promise((selesai) => setTimeout(selesai, 61_000))
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

const BRAND_CONTOH = (berkas: string) => readFileSync(fileURLToPath(new URL(`../../docs/brand/contoh/${berkas}`, import.meta.url)))

/** PUT /profil-rs + logo/motif fiktif lewat API (superadmin), dengan If-Match versi profil (kontrak 1.8a). */
async function pasangIdentitasContoh(browser: Browser, asal: string) {
  const k = await konteks(browser, asal, AKUN.superadmin, 1440)
  const xsrf = decodeURIComponent((await k.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const header = { Accept: 'application/json', Origin: asal, Referer: `${asal}/`, 'X-XSRF-TOKEN': xsrf }
  const versi = async () => ((await (await k.request.get('/api/v1/profil-rs', { headers: header })).json()) as { data: { versi: string } }).data.versi
  const ubah = await k.request.put('/api/v1/profil-rs', {
    headers: { ...header, 'If-Match': `"${await versi()}"` },
    data: {
      nama: 'RS Contoh Pendidikan', kode_kemenkes: null, kelas: 'B', status_blu: null, kode_ppk_bpjs: null, alamat: null,
      warna: { brand: '#1F5C99', accent: '#D97706' }, kontak_admin: { unit: 'Instalasi SIMRS', kontak: null },
      dasar_perubahan: 'Identitas fiktif untuk tangkapan layar e2e',
    },
  })
  expect(ubah.ok(), `${ubah.status()} ${await ubah.text()}`).toBeTruthy()
  for (const [jenis, berkas] of [['logo', 'logo-rs-contoh.svg'], ['motif', 'motif-contoh.svg']] as const) {
    const r = await k.request.put(`/api/v1/profil-rs/berkas/${jenis}`, {
      headers: { ...header, 'If-Match': `"${await versi()}"` },
      multipart: { berkas: { name: berkas, mimeType: 'image/svg+xml', buffer: BRAND_CONTOH(berkas) } },
    })
    expect(r.ok(), `${jenis} ${r.status()} ${await r.text()}`).toBeTruthy()
  }
  await k.close()
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
    const k = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI, viewport: { width: lebar, height: 900 }, reducedMotion: 'reduce' })
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
    // Sanctum stateful hanya mengenali sesi dari origin SPA.
    const r = await k.request.get('/api/v1/saya/menu', { headers: { Accept: 'application/json', Origin: asal, Referer: `${asal}/` } })
    expect(r.ok(), `${akun.nama} ${r.status()}`).toBeTruthy()
    const menu = ((await r.json()) as { data: { grup: { menu: { rute: string; status: string; sub_menu: { rute: string; status: string }[] }[] }[] } }).data
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
