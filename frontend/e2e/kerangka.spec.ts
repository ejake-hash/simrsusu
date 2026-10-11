import { expect, test as dasar, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test'

/**
 * Kerangka aplikasi (tiket 1.2b) di empat kelas layar PRD 5.5. Masuk ke backend asli dengan akun
 * sintetis; menu dari GET /saya/menu (tiket 1.2a) sesuai peran contoh_dokter di AkunSintetisSeeder:
 * IGD, Rawat Jalan, Rekam Medis, semuanya Segera hadir di APP_ENV=local.
 */
const SANDI = process.env.E2E_SANDI ?? ''
const AKUN = { nama: 'contoh.dokter', pegawai: 'Contoh Dokter' }

// Masuk sekali per berkas, lalu cookie sesinya dipakai semua uji: rate limit masuk per nama
// pengguna (10/menit) cepat habis bila tiap uji masuk sendiri.
let sesi: Awaited<ReturnType<BrowserContext['storageState']>> | undefined
const test = dasar.extend({ storageState: async ({}, pakai) => pakai(sesi) })

test.beforeAll(async ({ playwright }, info) => {
  // Lebar layar diatur per uji, jadi cukup satu proyek.
  test.skip(info.project.name !== 'desktop', 'lebar layar diatur per uji')
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const permintaan = await playwright.request.newContext({ baseURL: asal })
  await masukLewatApi(permintaan, asal)
  sesi = await permintaan.storageState()
  await permintaan.dispose()
})

/** Langkah sandi kontrak 10.3.2 lewat API (bukan form): halaman masuk sudah diuji di masuk.spec.ts. */
async function masukLewatApi(permintaan: APIRequestContext, asal: string) {
  await permintaan.get('/sanctum/csrf-cookie')
  const xsrf = (await permintaan.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? ''
  const respons = await permintaan.post('/api/v1/autentikasi/masuk', {
    data: { nama_pengguna: AKUN.nama, sandi: SANDI },
    // Backend hanya menerima langkah masuk dari origin SPA (asal_tidak_diizinkan).
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json', Origin: asal },
  })
  expect(respons.ok(), `${respons.status()} ${await respons.text()}`).toBeTruthy()
}

async function bukaBeranda(page: Page, lebar: number, tinggi = 900) {
  await page.setViewportSize({ width: lebar, height: tinggi })
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^Selamat (pagi|siang|sore|malam), ${AKUN.pegawai}$`) })).toBeVisible()
  // Menu sudah dimuat (di mode laci ia tersembunyi dari pohon aksesibilitas sampai laci dibuka).
  await expect(page.locator('#sidebar nav')).toBeAttached()
}

async function tanpaGulirSamping(page: Page) {
  const [lebarIsi, lebarLayar] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth])
  expect(lebarIsi).toBeLessThanOrEqual(lebarLayar)
}

const sidebar = (page: Page) => page.getByRole('complementary', { name: 'Navigasi utama' })
const menu = (page: Page) => page.getByRole('navigation', { name: 'Menu utama' })
const tombolMenu = (page: Page) => page.getByRole('button', { name: 'Buka menu' })

test('1440 px: sidebar 248 px dengan grup, kartu RS, menu aktif; kolom cari nonaktif', async ({ page }) => {
  await bukaBeranda(page, 1440)
  await tanpaGulirSamping(page)

  expect((await sidebar(page).boundingBox())?.width).toBe(248)
  await expect(tombolMenu(page)).toBeHidden()
  await expect(menu(page).getByText('Pelayanan', { exact: true })).toBeVisible()
  // Grup tanpa menu yang diizinkan untuk peran ini tidak dikirim server sama sekali.
  for (const grup of ['Pendapatan', 'Tata Kelola']) {
    await expect(menu(page).getByText(grup, { exact: true })).toHaveCount(0)
  }
  await expect(menu(page).getByRole('link', { name: 'Beranda' })).toHaveAttribute('aria-current', 'page')
  await expect(menu(page).getByRole('link', { name: 'IGD Segera hadir' })).toBeVisible()
  await expect(page.getByRole('searchbox', { name: 'Cari pasien' })).toBeDisabled()
  await expect(page.getByRole('button', { name: 'Keluar' })).toBeVisible()
})

test('menu segera → Segera hadir dengan fase; sub-menu tampil di bawah menu aktif; kembali ke Beranda', async ({ page }) => {
  await bukaBeranda(page, 1440)

  await menu(page).getByRole('link', { name: /^IGD/ }).click()
  await expect(page).toHaveURL(/\/igd$/)
  await expect(page.getByRole('heading', { level: 1, name: 'IGD' })).toBeVisible()
  await expect(page.getByRole('main').getByText('Segera hadir', { exact: true })).toBeVisible()
  await expect(page.getByText('tersedia pada fase 2')).toBeVisible()
  await expect(page).toHaveTitle('IGD · Karsitara')

  await menu(page).getByRole('link', { name: 'Papan pasien IGD' }).click()
  await expect(page).toHaveURL(/\/igd\/papan$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('IGD · Papan pasien IGD')
  await expect(menu(page).getByRole('link', { name: 'Papan pasien IGD' })).toHaveAttribute('aria-current', 'page')

  await page.getByRole('link', { name: 'Kembali ke Beranda' }).click()
  await expect(page).toHaveURL(/\/$/)
  await expect(menu(page).getByRole('link', { name: 'Papan pasien IGD' })).toHaveCount(0)
})

test('jalur di luar menu → Halaman tidak ditemukan', async ({ page }) => {
  await page.goto('/tidak-ada-di-menu')
  await expect(page.getByRole('heading', { level: 1, name: 'Halaman tidak ditemukan' })).toBeVisible()
})

test('1024 px: sidebar ikon 64 px, label lewat tooltip, target sentuh 44 px', async ({ page }) => {
  await bukaBeranda(page, 1024, 768)
  await tanpaGulirSamping(page)

  expect((await sidebar(page).boundingBox())?.width).toBe(64)
  await expect(tombolMenu(page)).toBeHidden()
  // Label grup menjadi garis pemisah 1 px.
  expect((await menu(page).getByText('Pelayanan', { exact: true }).boundingBox())?.height).toBeLessThanOrEqual(1)

  const rekamMedis = menu(page).getByRole('link', { name: /^Rekam Medis/ })
  const kotak = await rekamMedis.boundingBox()
  expect(kotak?.width).toBeGreaterThanOrEqual(44)
  expect(kotak?.height).toBeGreaterThanOrEqual(44)

  // Tooltip juga muncul lewat fokus keyboard, bukan hanya hover (PRD 5.5 aturan 2).
  await rekamMedis.focus()
  await expect(page.locator('.tip')).toHaveText('Rekam Medis')
  await page.keyboard.press('Escape')
  await expect(page.locator('.tip')).toHaveCount(0)
})

test('768 px: menu laci, Esc menutup dan fokus kembali ke tombol menu', async ({ page }) => {
  await bukaBeranda(page, 768, 1024)
  await tanpaGulirSamping(page)

  await expect(sidebar(page)).toBeHidden()
  await tombolMenu(page).click()
  await expect(sidebar(page)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Tutup menu' })).toBeFocused()
  await expect(menu(page).getByRole('link', { name: /^Rawat Jalan/ })).toBeVisible()
  const kotak = await menu(page).getByRole('link', { name: /^Rawat Jalan/ }).boundingBox()
  expect(kotak?.height).toBeGreaterThanOrEqual(44)

  await page.keyboard.press('Escape')
  await expect(sidebar(page)).toBeHidden()
  await expect(tombolMenu(page)).toBeFocused()

  // Memilih menu menutup laci.
  await tombolMenu(page).click()
  await menu(page).getByRole('link', { name: /^Rekam Medis/ }).click()
  await expect(page).toHaveURL(/\/rekam-medis$/)
  await expect(sidebar(page)).toBeHidden()
})

test('390 px: laci hanya Beranda dan akun, tanpa gulir samping', async ({ page }) => {
  await bukaBeranda(page, 390, 844)
  await tanpaGulirSamping(page)

  await tombolMenu(page).click()
  await expect(menu(page).getByRole('link')).toHaveCount(1)
  await expect(menu(page).getByRole('link', { name: 'Beranda' })).toBeVisible()
  await expect(sidebar(page).getByText(AKUN.pegawai)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Keluar' })).toBeAttached()
})
