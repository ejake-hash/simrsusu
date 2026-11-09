import { expect, test } from '@playwright/test'
import { halamanAkun, pelanggaranAxe, SANDI } from './bantu'

/**
 * Master Data → Instrumen triase (tiket 2.24) ke backend asli sebagai data.induk @1440 px + axe: tambah instrumen
 * SINTETIS dengan dua tingkat skala, lalu nonaktifkan dengan dasar (tidak dihapus, dan tidak lagi ditawarkan ke layar
 * Triase IGD). Kode unik per jalan.
 */
const KODE = `E2E-TRIASE-${Date.now().toString(36).toUpperCase()}`
const NAMA = `Instrumen contoh ${KODE}`

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

test('tambah instrumen triase dengan skala, lalu nonaktifkan dengan dasar', async ({ browser, baseURL }) => {
  const page = await halamanAkun(browser, new URL(baseURL ?? '').origin, 'data.induk')
  await page.goto('/master-data/instrumen-triase')
  await expect(page.getByRole('heading', { name: 'Instrumen triase', level: 1 })).toBeVisible()

  await page.getByRole('button', { name: 'Tambah instrumen' }).click()
  const dialog = page.getByRole('dialog', { name: 'Tambah instrumen triase' })
  // Diisi sebelum baris skala ditambah: label "Kode" belum bentrok dengan "Kode tingkat n".
  await dialog.getByLabel('Kode').fill(KODE)
  await dialog.getByLabel('Nama instrumen').fill(NAMA)
  await dialog.getByRole('button', { name: 'Tambah tingkat' }).click()
  await dialog.getByRole('button', { name: 'Tambah tingkat' }).click()
  await dialog.getByLabel('Kode tingkat 1').fill('T1')
  await dialog.getByLabel('Label tingkat 1').fill('Tingkat contoh satu')
  await dialog.getByLabel('Kode tingkat 2').fill('T2')
  await dialog.getByLabel('Label tingkat 2').fill('Tingkat contoh dua')
  await dialog.getByLabel('Dasar perubahan').fill('SK komite uji e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Tambah instrumen' }).click()
  await expect(dialog).toHaveCount(0)

  await page.getByRole('searchbox', { name: 'Cari instrumen' }).fill(KODE)
  const baris = page.getByRole('row', { name: new RegExp(KODE) })
  await expect(baris).toContainText('Tingkat contoh satu, Tingkat contoh dua')
  await expect(baris).toContainText('Aktif')

  await baris.getByRole('button', { name: `Nonaktifkan ${NAMA}` }).click()
  const status = page.getByRole('dialog', { name: 'Nonaktifkan instrumen triase?' }).or(page.getByRole('alertdialog', { name: 'Nonaktifkan instrumen triase?' }))
  await status.getByLabel('Dasar perubahan').fill('Instrumen diganti (uji e2e)')
  await status.getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(status).toHaveCount(0)
  await expect(baris).toHaveCount(0)
  await page.getByRole('combobox', { name: 'Status instrumen' }).selectOption('nonaktif')
  await expect(baris).toContainText('Nonaktif')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})
