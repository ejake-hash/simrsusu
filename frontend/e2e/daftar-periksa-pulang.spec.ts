import { expect, test, type Page } from '@playwright/test'
import { halamanAkun, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * Master Data → Daftar periksa pulang (tiket 2.40) ke backend asli sebagai data.induk @1440 px + axe: ubah urutan butir
 * RUJUK_BALIK dengan dasar perubahan, lalu kembalikan ke nilai semula agar rencana pulang di spec admisi rawat inap dan
 * jalan berikutnya tetap sama. Butir isi awal migrasi (bukan data RS).
 */
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

async function ubahUrutan(page: Page, urutan: string, dasar: string) {
  await page.getByRole('row', { name: /RUJUK_BALIK/ }).getByRole('button', { name: 'Ubah Rujuk balik' }).click()
  const dialog = page.getByRole('dialog', { name: 'Ubah butir RUJUK_BALIK' })
  await expect(dialog).toContainText('Kode dan sumber tidak bisa diubah.')
  await dialog.getByLabel('Urutan tampil').fill(urutan)
  await dialog.getByRole('textbox', { name: /Dasar perubahan/ }).fill(dasar)
  return dialog
}

test('ubah urutan butir daftar periksa pulang dengan dasar, lalu kembalikan', async ({ browser, baseURL }) => {
  const asal = new URL(baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'data.induk')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/master-data/daftar-periksa-pulang')
  await expect(page.getByRole('heading', { name: 'Daftar periksa pulang', level: 1 })).toBeVisible()
  const baris = page.getByRole('row', { name: /RUJUK_BALIK/ })
  await expect(baris).toContainText('Rujuk balik')
  await expect(page.getByRole('row', { name: /TAGIHAN_JAMINAN/ })).toContainText('Kasir (Tahap D)')
  const semula = (await baris.getByRole('cell').nth(4).textContent())!.trim()
  expect(await pelanggaranAxe(page)).toEqual([])

  const dialog = await ubahUrutan(page, '30', 'Notulen komite pulang uji e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Simpan' }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText('Butir RUJUK_BALIK tersimpan. Berlaku untuk rencana pulang yang dibuat sesudahnya.')).toBeVisible()
  await expect(baris.getByRole('cell').nth(4)).toHaveText('30')

  const balik = await ubahUrutan(page, semula, 'Pemulihan urutan uji e2e')
  await balik.getByRole('button', { name: 'Simpan' }).click()
  await expect(balik).toHaveCount(0)
  await expect(baris.getByRole('cell').nth(4)).toHaveText(semula)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})
