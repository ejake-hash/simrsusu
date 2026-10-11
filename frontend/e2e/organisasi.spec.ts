import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Master Data → Organisasi, unit & KSM (tiket 1.8a) ke backend asli dengan akun sintetis contoh.data.induk (pemegang
 * master_data, bukan superadmin) dan data OrganisasiSintetisSeeder: IRJ (POLI-PD, POLI-ANAK), IRNA (RUANG-MELATI,
 * RUANG-MAWAR), IGD, FARMASI (DEPO-RJ); KSM-IPD/ANAK/BEDAH. Unit baru memakai kode unik per run.
 */
type Sesi = Awaited<ReturnType<BrowserContext['storageState']>>
let sesi: Sesi | undefined
const AKHIRAN = Date.now().toString(36).toUpperCase()

test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'lebar layar diatur per uji')
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

/** Satu kali masuk per berkas (rate limit masuk 10/menit per nama pengguna). */
async function halaman(browser: Browser, asal: string, lebar = 1440, tinggi = 900): Promise<Page> {
  if (!sesi) {
    const masuk = await browser.newContext({ baseURL: asal })
    await masukLewatApi(masuk.request, asal, 'contoh.data.induk')
    sesi = await masuk.storageState()
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi, viewport: { width: lebar, height: tinggi } })
  return konteks.newPage()
}
const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const pohon = (page: Page) => page.getByRole('tree', { name: 'Struktur unit organisasi' })
const simpul = (page: Page, nama: string) => pohon(page).getByRole('treeitem', { name: new RegExp(`^${nama}`) })
/** Klik label barisnya: titik tengah treeitem yang terbuka bisa jatuh di baris anak. */
const klikSimpul = (page: Page, nama: string) => pohon(page).getByText(nama, { exact: true }).click()
const dialog = (page: Page) => page.getByRole('dialog').or(page.getByRole('alertdialog'))

async function seriusAtauKritis(page: Page): Promise<string[]> {
  const hasil = await new AxeBuilder({ page }).analyze()
  return hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').flatMap((v) => v.nodes.map((n) => `${v.id}: ${n.target.join(' ')}`))
}

test('pohon unit muat-lambat, rincian, tambah sub-unit, dan nonaktif ditolak selagi punya anak aktif', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/master-data/organisasi')
  await expect(page.getByRole('heading', { level: 1, name: 'Organisasi, unit & KSM' })).toBeVisible()
  await expect(simpul(page, 'Instalasi Rawat Inap \\(contoh\\)')).toBeVisible()
  await expect(simpul(page, 'Ruang Melati')).toHaveCount(0)

  // Keyboard: Enter memilih, panah kanan membuka dan memuat anaknya.
  await simpul(page, 'Instalasi Rawat Inap \\(contoh\\)').focus()
  await page.keyboard.press('Enter')
  await page.keyboard.press('ArrowRight')
  await expect(simpul(page, 'Ruang Melati \\(contoh\\)')).toBeVisible()
  await expect(page).toHaveURL(/\?unit=/)
  const rincian = page.getByRole('region', { name: 'Instalasi Rawat Inap (contoh)' })
  await expect(rincian.getByText('Sub-unit langsung')).toBeVisible()

  // Tambah sub-unit di IRNA.
  await rincian.getByRole('button', { name: 'Sub-unit' }).click()
  await expect(dialog(page).getByRole('heading', { name: 'Tambah sub-unit di IRNA' })).toBeVisible()
  await expect(dialog(page).getByLabel('Unit induk')).toHaveValue('IRNA · Instalasi Rawat Inap (contoh)')
  await dialog(page).getByLabel('Kode').fill(`ruang-e2e-${AKHIRAN}`)
  await expect(dialog(page).getByLabel('Kode')).toHaveValue(`RUANG-E2E-${AKHIRAN}`)
  await dialog(page).getByLabel('Jenis').selectOption('ruang')
  await dialog(page).getByLabel('Nama unit').fill(`Ruang Uji E2E ${AKHIRAN}`)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await expect(await seriusAtauKritis(page)).toEqual([])
  await dialog(page).getByRole('button', { name: 'Tambah unit' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 2, name: `Ruang Uji E2E ${AKHIRAN}` })).toBeVisible()
  await expect(simpul(page, `Ruang Uji E2E ${AKHIRAN}`)).toHaveAttribute('aria-selected', 'true')

  // Kode ganda → pesan di kolom kode.
  await page.getByRole('button', { name: 'Sub-unit' }).click()
  await dialog(page).getByLabel('Kode').fill('IRNA')
  await dialog(page).getByLabel('Jenis').selectOption('ruang')
  await dialog(page).getByLabel('Nama unit').fill('Duplikat')
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await dialog(page).getByRole('button', { name: 'Tambah unit' }).click()
  await expect(dialog(page).getByText(/sudah dipakai/)).toBeVisible()
  await dialog(page).getByRole('button', { name: 'Batal' }).click()

  // IRNA masih punya anak aktif → 409 dengan rincian.
  await klikSimpul(page, 'Instalasi Rawat Inap (contoh)')
  await expect(page.getByRole('heading', { level: 2, name: 'Instalasi Rawat Inap (contoh)' })).toBeVisible()
  await page.getByRole('button', { name: 'Nonaktifkan' }).click()
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await dialog(page).getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(dialog(page).getByText(/unit anak masih aktif/)).toBeVisible()
  await dialog(page).getByRole('button', { name: 'Batal' }).click()

  // Unit uji dinonaktifkan agar run berikutnya tidak menumpuk unit aktif.
  await page.getByRole('searchbox', { name: 'Cari unit' }).fill(`RUANG-E2E-${AKHIRAN}`)
  await page.getByRole('list', { name: 'Hasil pencarian unit' }).getByRole('button').first().click()
  await expect(page.getByRole('heading', { level: 2, name: `Ruang Uji E2E ${AKHIRAN}` })).toBeVisible()
  await page.getByRole('button', { name: 'Nonaktifkan' }).click()
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await dialog(page).getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(page.getByRole('region', { name: `Ruang Uji E2E ${AKHIRAN}` }).getByText('Nonaktif', { exact: true })).toBeVisible()
  await page.context().close()
})

test('tab KSM: daftar dari seeder, cari, ubah dengan dasar perubahan', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/master-data/organisasi?tab=ksm')
  const tabel = page.getByRole('region', { name: 'Daftar KSM' })
  await expect(tabel.getByText('KSM-IPD')).toBeVisible()
  await page.getByRole('searchbox', { name: 'Cari KSM' }).fill('bedah')
  await expect(tabel.getByRole('row')).toHaveCount(2)
  await tabel.getByRole('button', { name: /^Ubah KSM Bedah/ }).click()
  const nama = `KSM Bedah (contoh) ${AKHIRAN}`
  await dialog(page).getByLabel('Nama KSM').fill(nama)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Komite Medik uji e2e')
  await dialog(page).getByRole('button', { name: 'Simpan' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(tabel.getByText(nama)).toBeVisible()
  // Kembalikan nama agar run berikutnya tetap menemukan "bedah".
  await tabel.getByRole('button', { name: `Ubah ${nama}` }).click()
  await dialog(page).getByLabel('Nama KSM').fill('KSM Bedah (contoh)')
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Komite Medik uji e2e')
  await dialog(page).getByRole('button', { name: 'Simpan' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(await seriusAtauKritis(page)).toEqual([])
  await page.context().close()
})

for (const [lebar, tinggi] of [
  [1024, 768],
  [390, 844],
] as const) {
  test(`${lebar} px: organisasi tanpa gulir samping dan tanpa pelanggaran axe serius/kritis`, async ({ browser }, info) => {
    const page = await halaman(browser, asalDari(info.project.use.baseURL), lebar, tinggi)
    await page.goto('/master-data/organisasi')
    await klikSimpul(page, 'Instalasi Rawat Jalan (contoh)')
    await expect(page.getByRole('heading', { level: 2, name: 'Instalasi Rawat Jalan (contoh)' })).toBeInViewport()
    await tanpaGulirSamping(page)
    await expect(await seriusAtauKritis(page)).toEqual([])
    await page.context().close()
  })
}

test('akun tanpa master_data: halaman organisasi tidak ditemukan dan daftar unit ditolak server', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const masuk = await browser.newContext({ baseURL: asal, storageState: { cookies: [], origins: [] } })
  await masukLewatApi(masuk.request, asal, 'contoh.dokter')
  const page = await masuk.newPage()
  await page.goto('/master-data/organisasi')
  await expect(page.getByRole('heading', { level: 1, name: 'Halaman tidak ditemukan' })).toBeVisible()
  expect((await masuk.request.get('/api/v1/unit-organisasi?akar=true', { headers: { Accept: 'application/json', Origin: asal, Referer: `${asal}/` } })).status()).toBe(403)
  await masuk.close()
})
