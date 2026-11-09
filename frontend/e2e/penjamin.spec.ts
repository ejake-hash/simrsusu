import { expect, test } from '@playwright/test'
import { halamanAkun, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * Master Data → Penjamin (tiket 2.1) ke backend asli sebagai data.induk: tambah penjamin, kode ganda ditolak
 * server (422 di kolom Kode), nonaktifkan dengan dasar, lalu tampil di filter Nonaktif. Kode unik per jalan.
 */
const KODE = `E2E-${Date.now().toString(36).toUpperCase()}`
const NAMA = `Asuransi Contoh ${KODE}`

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

test('tambah penjamin, kode ganda ditolak, nonaktifkan dengan dasar', async ({ browser, baseURL }) => {
  const asal = new URL(baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'data.induk')
  // Spec fase2 sebelumnya (instrumen triase, matriks, papan IGD) memakai akun yang sama dalam menit yang sama.
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/master-data/penjamin')
  await expect(page.getByRole('heading', { name: 'Penjamin', level: 1 })).toBeVisible()

  await page.getByRole('button', { name: 'Tambah penjamin' }).click()
  const dialog = page.getByRole('dialog', { name: 'Tambah penjamin' })
  await dialog.getByLabel('Kode').fill(KODE)
  await dialog.getByLabel('Jenis').selectOption({ label: 'Asuransi swasta' })
  await dialog.getByLabel('Nama penjamin').fill(NAMA)
  await dialog.getByLabel('Masa berlaku surat jaminan (hari)').fill('30')
  await dialog.getByLabel('Plafon (Rp)').fill('25000000')
  await dialog.getByLabel('Dasar perubahan').fill('PKS uji e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Tambah penjamin' }).click()
  await expect(dialog).toHaveCount(0)

  await page.getByRole('searchbox', { name: 'Cari penjamin' }).fill(KODE)
  const baris = page.getByRole('row', { name: new RegExp(KODE) })
  await expect(baris).toContainText(NAMA)
  await expect(baris).toContainText('Asuransi swasta')
  await expect(baris).toContainText('Aktif')

  // Kode sama ditolak server (422 validasi_gagal) dan ditandai di kolomnya.
  await page.getByRole('button', { name: 'Tambah penjamin' }).click()
  await dialog.getByLabel('Kode').fill(KODE)
  await dialog.getByLabel('Nama penjamin').fill(`${NAMA} ganda`)
  await dialog.getByLabel('Dasar perubahan').fill('PKS uji e2e')
  await dialog.getByRole('button', { name: 'Tambah penjamin' }).click()
  await expect(dialog.getByLabel('Kode')).toHaveAttribute('aria-invalid', 'true')
  await dialog.getByRole('button', { name: 'Batal' }).click()

  await baris.getByRole('button', { name: `Nonaktifkan ${NAMA}` }).click()
  const status = page.getByRole('dialog', { name: 'Nonaktifkan penjamin?' }).or(page.getByRole('alertdialog', { name: 'Nonaktifkan penjamin?' }))
  await status.getByLabel('Dasar perubahan').fill('PKS berakhir (uji e2e)')
  await status.getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(status).toHaveCount(0)
  await expect(baris).toHaveCount(0)

  await page.getByRole('combobox', { name: 'Status penjamin' }).selectOption('nonaktif')
  await expect(baris).toContainText('Nonaktif')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})
