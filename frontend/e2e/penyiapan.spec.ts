import AxeBuilder from '@axe-core/playwright'
import { expect, test, type APIRequestContext, type Browser, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Pengaturan → Penyiapan instalasi (tiket 1.8f) ke backend asli dengan contoh.superadmin (penyiapan bawaan superadmin).
 * Instalasi e2e berisi akun sintetis: langkah 1 terpenuhi (contoh.superadmin + contoh.superadmin.dua ber-MFA, jaringan
 * RS dari .env), profil RS tanpa logo (langkah 2 belum), langkah 7 menunggu fase 2. Muat paket referensi diuji di
 * unit test dan backend (butuh master_data.katalog.input, bukan izin superadmin).
 */
const RAHASIA_SUPERADMIN = process.env.E2E_SUPERADMIN_MFA_RAHASIA ?? ''
const TANPA_SESI = { cookies: [], origins: [] }
let sesi: Awaited<ReturnType<APIRequestContext['storageState']>> | undefined
let periodeMasuk = 0

test.describe.configure({ mode: 'serial' })
test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'satu alur akun superadmin, lebar diatur per uji')
  if (!SANDI || !RAHASIA_SUPERADMIN) throw new Error('Set E2E_SANDI dan E2E_SUPERADMIN_MFA_RAHASIA sesuai AkunSintetisSeeder.')
})
// Kode TOTP satu periode tidak boleh dipakai ulang; spec pengaturan (sesudah ini) juga masuk sebagai contoh.superadmin.
test.afterAll(async () => {
  if (!periodeMasuk) return
  const sisa = (periodeMasuk + 1) * 30_000 - Date.now()
  if (sisa > 0) await new Promise((r) => setTimeout(r, sisa + 500))
})

async function halaman(browser: Browser, asal: string, lebar = 1440): Promise<Page> {
  if (!sesi) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    periodeMasuk = Math.floor(Date.now() / 30_000)
    await masukLewatApi(masuk.request, asal, 'contoh.superadmin', RAHASIA_SUPERADMIN)
    sesi = await masuk.storageState()
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi, viewport: { width: lebar, height: 900 } })
  return konteks.newPage()
}
const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const langkah = (page: Page, kode: string) => page.locator(`li[aria-labelledby="judul-langkah-${kode}"]`)

test('Beranda menandai instalasi belum siap; penyiapan menampilkan 9 langkah dan langkah 1 bisa dikonfirmasi (ulang)', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/')
  const pita = page.getByRole('region', { name: 'Instalasi belum bisa melayani pasien' })
  await expect(pita).toBeVisible()
  await pita.getByRole('link', { name: 'Buka penyiapan' }).click()
  await expect(page).toHaveURL(/\/pengaturan\/penyiapan$/)

  const daftar = page.getByRole('list', { name: 'Langkah penyiapan' })
  await expect(daftar.locator(':scope > li')).toHaveCount(9)
  await expect(langkah(page, 'penjamin_tarif')).toContainText('Belum')
  await expect(langkah(page, 'formularium')).toContainText('Menunggu fase 2')
  await expect(langkah(page, 'superadmin')).toContainText('Dua superadmin dengan MFA')
  await expect(langkah(page, 'profil_rs').getByRole('link', { name: 'Buka Profil rumah sakit' })).toHaveAttribute('href', '/pengaturan/profil-rs')
  // Superadmin tidak memegang katalog (data induk): tidak ada tombol muat paket.
  await expect(page.getByRole('button', { name: /^Muat paket/ })).toHaveCount(0)

  const tombol = langkah(page, 'superadmin').getByRole('button', { name: /^Konfirmasi (selesai|ulang)$/ })
  await tombol.click()
  await expect(page.getByText('Langkah 1 dikonfirmasi selesai.')).toBeVisible()
  await expect(langkah(page, 'superadmin').locator('.pil')).toHaveText('Selesai')
  await expect(langkah(page, 'superadmin')).toContainText('Dikonfirmasi oleh')
  // Langkah 2 belum lengkap (logo belum diunggah): langkah 8 belum bisa dilewati karena urutan.
  await expect(langkah(page, 'kredensial_integrasi').getByRole('button', { name: 'Lewati' })).toHaveCount(0)

  const hasil = await new AxeBuilder({ page }).analyze()
  expect(hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
  await page.context().close()
})

test('390 px: daftar langkah dan tabel paket tanpa gulir samping halaman', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 390)
  await page.goto('/pengaturan/penyiapan')
  await expect(page.getByRole('list', { name: 'Langkah penyiapan' }).locator(':scope > li')).toHaveCount(9)
  await expect(langkah(page, 'paket_referensi').getByRole('region', { name: 'Paket referensi' })).toBeVisible()
  await tanpaGulirSamping(page)
  await page.context().close()
})
