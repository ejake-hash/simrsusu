import { expect, test } from '@playwright/test'
import { halamanAkun, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * Master Data → Area & batas papan IGD (tiket 2.25) ke backend asli sebagai data.induk @1440 px + axe: tambah area
 * SINTETIS, lalu nonaktifkan dengan dasar (tidak dihapus; tidak lagi bisa dipilih di papan IGD). Batas waktu hanya
 * dibaca (tidak diubah agar spec lain tidak terpengaruh). Kode unik per jalan.
 */
const KODE = `E2E-AREA-${Date.now().toString(36).toUpperCase()}`
const NAMA = `Area contoh ${KODE}`

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

test('tambah area IGD, lalu nonaktifkan dengan dasar; batas waktu tampil dengan penjelasan', async ({ browser, baseURL }) => {
  const asal = new URL(baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'data.induk')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/master-data/papan-igd')
  await expect(page.getByRole('heading', { name: 'Area & batas papan IGD', level: 1 })).toBeVisible()
  await expect(page.getByText('Batas waktu tanggap', { exact: true })).toBeVisible()
  await expect(page.getByText('Tanda mendekati 6 jam', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Ubah batas waktu' })).toBeVisible()

  await page.getByRole('button', { name: 'Tambah area' }).click()
  const dialog = page.getByRole('dialog', { name: 'Tambah area IGD' })
  await dialog.getByLabel('Kode').fill(KODE)
  await dialog.getByLabel('Nama area').fill(NAMA)
  await dialog.getByLabel('Urutan tampil').fill('90')
  await dialog.getByRole('textbox', { name: /Dasar perubahan/ }).fill('SK komite uji e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Tambah area' }).click()
  await expect(dialog).toHaveCount(0)

  const baris = page.getByRole('row', { name: new RegExp(KODE) })
  await expect(baris).toContainText(NAMA)
  await expect(baris).toContainText('Aktif')

  await baris.getByRole('button', { name: `Nonaktifkan ${NAMA}` }).click()
  const status = page.getByRole('dialog', { name: 'Nonaktifkan area IGD?' }).or(page.getByRole('alertdialog', { name: 'Nonaktifkan area IGD?' }))
  await expect(status).toContainText('tidak bisa dipilih lagi untuk penempatan baru')
  await status.getByRole('textbox', { name: /Dasar perubahan/ }).fill('Area ditutup (uji e2e)')
  await status.getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(status).toHaveCount(0)
  await expect(baris).toHaveCount(0)
  await page.getByRole('combobox', { name: 'Status area' }).selectOption('nonaktif')
  await expect(baris).toContainText('Nonaktif')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})
