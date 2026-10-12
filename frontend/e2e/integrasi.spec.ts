import AxeBuilder from '@axe-core/playwright'
import { expect, test, type APIRequestContext, type Browser, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Gateway integrasi (tiket 1.9) ke backend asli dengan simulator (INTEGRASI_SIMULATOR=true) dan akun sintetis
 * contoh.integrasi (MFA; pengaturan.integrasi.* sensitif, integrasi.satusehat/bpjs.*, integrasi.log.lihat).
 * Kredensial yang diketik sintetis. Kotak keluar fase fondasi kosong (kiriman nyata menyusul), jadi yang diuji:
 * kredensial tulis-saja, aktifkan, uji koneksi → log, dan layar pemantauan.
 */
const RAHASIA = process.env.E2E_INTEGRASI_MFA_RAHASIA ?? ''
const TANPA_SESI = { cookies: [], origins: [] }
const AKHIRAN = Date.now().toString(36).slice(-4).toUpperCase().padStart(4, 'X')
const RAHASIA_KLIEN = `e2e-sintetis-rahasia-${AKHIRAN}`
let sesi: Awaited<ReturnType<APIRequestContext['storageState']>> | undefined

test.describe.configure({ mode: 'serial' })
test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'satu alur akun, lebar diatur per uji')
  if (!SANDI || !RAHASIA) throw new Error('Set E2E_SANDI dan E2E_INTEGRASI_MFA_RAHASIA sesuai AkunSintetisSeeder.')
})

async function halaman(browser: Browser, asal: string, lebar = 1440): Promise<Page> {
  if (!sesi) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    await masukLewatApi(masuk.request, asal, 'contoh.integrasi', RAHASIA)
    sesi = await masuk.storageState()
    await masuk.close()
  }
  // Tanpa animasi: axe memeriksa keadaan akhir, bukan toast yang masih memudar masuk (color-contrast palsu).
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi, viewport: { width: lebar, height: 900 }, reducedMotion: 'reduce' })
  return konteks.newPage()
}
const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const kartu = (page: Page, kode: string) => page.locator(`section[aria-labelledby="judul-sistem-${kode}"]`)
const ling = (page: Page, kode: string, l: string) => page.locator(`section[aria-labelledby="judul-ling-${kode}-${l}"]`)
const dialog = (page: Page) => page.getByRole('dialog').or(page.getByRole('alertdialog'))

test('kredensial SATUSEHAT sandbox tulis-saja, aktifkan, uji koneksi ke simulator; rahasia tidak pernah tampil', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/pengaturan/integrasi')
  await expect(page.locator('section.kartu-sistem')).toHaveCount(3)

  await ling(page, 'satusehat', 'sandbox').getByRole('button', { name: 'Ubah kredensial' }).click()
  await dialog(page).getByLabel('Client ID').fill(`e2e-client-${AKHIRAN}`)
  await dialog(page).getByLabel('Organization ID').fill('10000004')
  await dialog(page).getByLabel('Client secret').fill(RAHASIA_KLIEN)
  const aktifkan = dialog(page).getByRole('checkbox', { name: /Jadikan sandbox lingkungan aktif/ })
  if (await aktifkan.count()) await aktifkan.check()
  await dialog(page).getByRole('button', { name: 'Simpan' }).click()
  await expect(page.getByText('Kredensial SATUSEHAT disimpan.')).toBeVisible()
  await expect(ling(page, 'satusehat', 'sandbox')).toContainText('Aktif')
  await expect(ling(page, 'satusehat', 'sandbox')).toContainText(`e2e-client-${AKHIRAN}`)
  await expect(ling(page, 'satusehat', 'sandbox')).toContainText(`Terisi ····${RAHASIA_KLIEN.slice(-4)}`)
  await expect(page.getByText(RAHASIA_KLIEN)).toHaveCount(0)

  await ling(page, 'satusehat', 'sandbox').getByRole('button', { name: 'Uji koneksi' }).click()
  await expect(kartu(page, 'satusehat')).toContainText(/Uji koneksi sandbox .*berhasil/)

  const hasil = await new AxeBuilder({ page }).analyze()
  expect(hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
  await page.context().close()
})

test('log memuat panggilan uji koneksi tanpa rahasia; layar SATUSEHAT menampilkan kartu dan kotak keluar', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/integrasi/log')
  const log = page.getByRole('list', { name: 'Daftar log' })
  await expect(log.locator(':scope > li').first()).toContainText('SATUSEHAT sandbox')
  await expect(page.getByText(RAHASIA_KLIEN)).toHaveCount(0)

  await page.goto('/integrasi/satusehat')
  await expect(kartu(page, 'satusehat')).toBeVisible()
  await expect(kartu(page, 'aplicares')).toHaveCount(0)
  await expect(page.getByRole('heading', { name: 'Kotak keluar' })).toBeVisible()
  await page.context().close()
})

test('390 px: konfigurasi integrasi tanpa gulir samping', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 390)
  await page.goto('/pengaturan/integrasi')
  await expect(page.locator('section.kartu-sistem')).toHaveCount(3)
  await tanpaGulirSamping(page)
  await page.context().close()
})
