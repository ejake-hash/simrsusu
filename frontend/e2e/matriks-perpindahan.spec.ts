import { expect, test, type Page } from '@playwright/test'
import { halamanAkun, pelanggaranAxe, php, SANDI } from './bantu'

/**
 * Master Data → Matriks perpindahan (tiket 2.26) ke backend asli @1440 px + axe. data.induk melihat sel matriks (isi awal
 * migrasi = tabel PRD 7.00.3), mengusulkan penonaktifan sel IGD → DOA lewat usulan data induk, lalu usulan.verifikator
 * mengembalikannya: matriks tidak berubah, jadi spec lain (disposisi IGD) dan jalan ulang tetap memakai sel yang sama.
 */
let usulanId = ''

test.describe.configure({ mode: 'serial' })
/**
 * Jalan sebelumnya yang terputus bisa meninggalkan usulan uji yang masih berjalan dan mengunci sel (409
 * objek_sedang_diusulkan): kembalikan usulan matriks berjalan beralasan "Uji e2e" (tidak dihapus).
 */
const kembalikanUsulanUji = () =>
  php('tinker', '--execute', "DB::table('inti.usulan_data_induk')->where('jenis_data', 'matriks_perpindahan')->whereIn('tahap', ['diusulkan', 'diverifikasi', 'disetujui'])->where('alasan', 'like', 'Uji e2e%')->update(['tahap' => 'dikembalikan', 'diubah_pada' => now()]);")

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  kembalikanUsulanUji()
})

const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const dialog = (page: Page) => page.getByRole('dialog').or(page.getByRole('alertdialog'))
const tombolSel = (page: Page, sel: string) => page.getByRole('button', { name: `Usulkan perubahan sel ${sel}` })

test('data induk melihat matriks dan mengusulkan penonaktifan sel lewat usulan', async ({ browser, baseURL }) => {
  const page = await halamanAkun(browser, asalDari(baseURL), 'data.induk')
  await page.goto('/master-data/matriks-perpindahan')
  await expect(page.getByRole('heading', { name: 'Matriks perpindahan', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Pos asal' }).selectOption('igd')

  const doa = page.getByRole('row').filter({ has: tombolSel(page, 'IGD → DOA') })
  await expect(doa).toContainText('igd.disposisi.input')
  await expect(doa).toContainText('Aktif')
  // Sel aktif yang perintahnya lewat modul lain diberi keterangan.
  await expect(page.getByRole('row').filter({ has: tombolSel(page, 'IGD → Penunjang') })).toContainText('Perintah lewat order penunjang')
  // Tidak ada tulis langsung.
  await expect(page.getByRole('button', { name: /^(Ubah|Nonaktifkan)/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])

  await tombolSel(page, 'IGD → DOA').click()
  const d = dialog(page)
  await expect(d).toContainText('Usulkan perubahan sel IGD → DOA')
  await d.getByLabel('Jenis perubahan').selectOption('tutup')
  await d.getByLabel('Alasan penutupan').fill('Uji e2e: diusulkan lalu dikembalikan')
  await d.getByRole('textbox', { name: /^Dasar/ }).fill('Memo uji e2e 2.26')
  await d.getByLabel('Alasan usulan').fill('Uji e2e: alur usulan matriks (sintetis)')
  expect(await pelanggaranAxe(page)).toEqual([])
  await d.getByRole('button', { name: 'Ajukan usulan' }).click()
  await expect(page.getByText(/diajukan; menunggu verifikasi\./)).toBeVisible()
  await expect(page).toHaveURL(/\/master-data\/usulan\?usulan=/)
  usulanId = new URL(page.url()).searchParams.get('usulan') ?? ''
  expect(usulanId).not.toBe('')
  await page.context().close()
})

test('verifikator mengembalikan usulan; sel tetap aktif', async ({ browser, baseURL }) => {
  const asal = asalDari(baseURL)
  const verifikator = await halamanAkun(browser, asal, 'usulan.verifikator')
  await verifikator.goto(`/master-data/usulan?usulan=${usulanId}`)
  const rincian = verifikator.locator('section.rincian')
  await expect(rincian).toContainText('Nonaktifkan sel matriks perpindahan')
  await rincian.getByRole('button', { name: 'Kembalikan', exact: true }).click()
  const d = dialog(verifikator)
  await d.getByLabel('Catatan pengembalian').fill('Uji e2e: tidak diterapkan')
  await d.getByRole('button', { name: 'Kembalikan', exact: true }).click()
  await expect(verifikator.getByText(/dikembalikan ke pengusul\./)).toBeVisible()
  await verifikator.context().close()

  const page = await halamanAkun(browser, asal, 'data.induk')
  await page.goto('/master-data/matriks-perpindahan')
  await page.getByRole('combobox', { name: 'Pos asal' }).selectOption('igd')
  await expect(page.getByRole('row').filter({ has: tombolSel(page, 'IGD → DOA') })).toContainText('Aktif')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})
