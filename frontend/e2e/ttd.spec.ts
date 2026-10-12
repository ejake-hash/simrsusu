import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type Page } from '@playwright/test'
import { masukLewatApi, SANDI } from './bantu'

/**
 * Tanda tangan elektronik T1 (tiket 1.6) ke backend asli, akun sintetis TtdSintetisSeeder: contoh.ttd.baru (belum
 * punya TTE, alur aktivasi) dan contoh.ttd (TTE aktif, PIN E2E_TTD_PIN; dipakai untuk penguncian, bukan
 * contoh.superadmin.dua yang dipakai e2e pengaturan). CI memakai TTD_KUNCI_MENIT=1. Pengulangan lokal butuh db:seed ulang.
 */
const PIN = process.env.E2E_TTD_PIN ?? ''
const TANPA_SESI = { cookies: [], origins: [] }

test.describe.configure({ mode: 'serial' })
test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'alur akun tunggal, cukup sekali')
  if (!SANDI || !PIN) throw new Error('Set E2E_SANDI dan E2E_TTD_PIN sesuai AkunSintetisSeeder/TtdSintetisSeeder.')
})

async function halaman(browser: Browser, asal: string, nama: string): Promise<Page> {
  const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
  await masukLewatApi(masuk.request, asal, nama)
  const sesi = await masuk.storageState()
  await masuk.close()
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi, viewport: { width: 1440, height: 900 } })
  return konteks.newPage()
}
const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin

test('aktivasi TTE dari menu akun: sandi akun wajib, PIN lemah ditolak server; berhasil → aktif; kartu Keamanan akun ikut berubah', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 'contoh.ttd.baru')
  await page.goto('/')
  await expect(page.getByRole('region', { name: 'Keamanan akun' })).toContainText('Belum aktif')
  await page.getByRole('button', { name: 'Menu akun' }).click()
  await page.getByRole('navigation', { name: 'Menu akun' }).getByRole('link', { name: 'Tanda tangan elektronik' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Tanda tangan elektronik' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Status' })).toContainText('Belum diaktifkan.')

  const aktivasi = page.getByRole('region', { name: 'Aktifkan' })
  await aktivasi.getByLabel('Sandi akun', { exact: true }).fill(SANDI)
  await aktivasi.getByLabel('PIN baru').fill('123456')
  await aktivasi.getByLabel('Ulangi PIN').fill('123456')
  const ditolak = page.waitForResponse((r) => r.url().endsWith('/api/v1/saya/ttd/aktivasi'))
  await aktivasi.getByRole('button', { name: 'Aktifkan tanda tangan' }).click()
  expect((await ditolak).status()).toBe(422)
  await expect(aktivasi.getByLabel('PIN baru')).toHaveValue('')

  await aktivasi.getByLabel('Sandi akun', { exact: true }).fill(SANDI)
  await aktivasi.getByLabel('PIN baru').fill('714953')
  await aktivasi.getByLabel('Ulangi PIN').fill('714953')
  await aktivasi.getByRole('button', { name: 'Aktifkan tanda tangan' }).click()
  await expect(page.getByText('Tanda tangan elektronik aktif.')).toBeVisible()
  await expect(page.getByRole('region', { name: 'Status' })).toContainText(/Aktif sejak/)
  await expect(page.getByRole('region', { name: 'Ganti PIN' })).toBeVisible()
  await expect(await new AxeBuilder({ page }).analyze().then((h) => h.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id))).toEqual([])

  await page.goto('/')
  await expect(page.getByRole('region', { name: 'Keamanan akun' }).getByText('Aktif', { exact: true }).last()).toBeVisible()
  await page.context().close()
})

test('ganti PIN dengan PIN lama salah: sisa percobaan disebut; ke-5 terkunci (403 Retry-After) dan formulir nonaktif', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 'contoh.ttd')
  await page.goto('/tanda-tangan')
  const ganti = page.getByRole('region', { name: 'Ganti PIN' })
  for (let i = 1; i <= 5; i++) {
    await ganti.getByLabel('PIN lama').fill(PIN === '135790' ? '246813' : '135790')
    await ganti.getByLabel('PIN baru', { exact: true }).fill('864201')
    await ganti.getByLabel('Ulangi PIN baru').fill('864201')
    await ganti.getByRole('button', { name: 'Ganti PIN' }).click()
    if (i < 5) await expect(ganti.getByText(`Sisa percobaan: ${5 - i}`, { exact: false })).toBeVisible()
  }
  await expect(ganti.getByRole('alert')).toContainText(/terkunci/i)
  await expect(page.getByRole('region', { name: 'Status' })).toContainText(/Terkunci sampai/)
  await expect(ganti.getByLabel('PIN lama')).toBeDisabled()
  await page.context().close()
})
