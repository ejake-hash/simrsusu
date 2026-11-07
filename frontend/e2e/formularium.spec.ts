import { expect, test } from '@playwright/test'
import { halamanAkun, pelanggaranAxe, SANDI } from './bantu'

/**
 * Farmasi → Formularium (tiket 2.11) ke backend asli sebagai contoh.data.induk: tambah barang (kode ganda 422), impor CSV
 * dengan pratinjau yang menunjuk nomor baris, tambah formularium, tambah item lewat pemilih barang, lalu nonaktifkan item.
 * Instalasi belum siap melayani (spec lain mengembalikannya lewat `--batal`), jadi formularium ditulis langsung. Kode dan
 * tanggal unik per jalan: rentang berlaku satu hari di tahun 2200-an agar tidak tumpang tindih antarjalan.
 */
const UNIK = Date.now().toString(36).toUpperCase()
const KODE_BARANG = `E2E-${UNIK}`
const NAMA_BARANG = `Obat Contoh ${UNIK}`
const KODE_FRM = `FRM-${UNIK}`
const NAMA_FRM = `Formularium Contoh ${UNIK}`
const HARI = new Date(Date.UTC(2200, 0, 1) + (Math.floor(Date.now() / 1000) % 1000000) * 86400000).toISOString().slice(0, 10)

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

test('katalog barang dan formularium: tambah, kode ganda, impor pratinjau, item', async ({ browser, baseURL }) => {
  const page = await halamanAkun(browser, new URL(baseURL ?? '').origin, 'contoh.data.induk')
  await page.goto('/farmasi/formularium')
  await expect(page.getByRole('heading', { name: 'Formularium', level: 1 })).toBeVisible()

  // Katalog barang
  await page.getByRole('button', { name: 'Tambah barang' }).click()
  const dialog = page.getByRole('dialog', { name: 'Tambah barang' })
  await dialog.getByLabel('Kode barang').fill(KODE_BARANG)
  await dialog.getByLabel('Nama barang').fill(NAMA_BARANG)
  await dialog.getByLabel('Generik atau paten').selectOption('generik')
  await dialog.getByLabel('Satuan terkecil').fill('tablet')
  await dialog.getByLabel('Dasar perubahan').fill('Uji e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Tambah barang' }).click()
  await expect(dialog).toHaveCount(0)

  await page.getByRole('searchbox', { name: 'Cari barang' }).fill(KODE_BARANG)
  const baris = page.getByRole('row', { name: new RegExp(KODE_BARANG) })
  await expect(baris).toContainText(NAMA_BARANG)
  await expect(baris).toContainText('Belum diisi')

  await page.getByRole('button', { name: 'Tambah barang' }).click()
  await dialog.getByLabel('Kode barang').fill(KODE_BARANG)
  await dialog.getByLabel('Nama barang').fill(`${NAMA_BARANG} ganda`)
  await dialog.getByLabel('Generik atau paten').selectOption('paten')
  await dialog.getByLabel('Satuan terkecil').fill('tablet')
  await dialog.getByLabel('Dasar perubahan').fill('Uji e2e')
  await dialog.getByRole('button', { name: 'Tambah barang' }).click()
  await expect(dialog.getByLabel('Kode barang')).toHaveAttribute('aria-invalid', 'true')
  await dialog.getByRole('button', { name: 'Batal' }).click()

  // Impor CSV: baris tanpa satuan ditunjuk dengan nomor barisnya, tidak ada yang disimpan.
  await page.getByRole('button', { name: 'Impor CSV' }).click()
  const impor = page.getByRole('dialog', { name: 'Impor katalog barang' })
  const judul = 'kode;nama;jenis;generik_paten;zat_aktif;kekuatan;sediaan;satuan_terkecil;kode_kfa;kewaspadaan_tinggi;narkotika;lasa'
  await impor.getByLabel('Berkas CSV').setInputFiles({ name: 'barang.csv', mimeType: 'text/csv', buffer: Buffer.from(`${judul}\nIMP-${UNIK};Tanpa Satuan;bmhp;;;;;;;;;\n`) })
  await impor.getByLabel('Dasar perubahan').fill('Uji e2e')
  await impor.getByRole('button', { name: 'Periksa berkas' }).click()
  await expect(impor.getByRole('alert')).toContainText('Tidak ada yang disimpan')
  await expect(impor.getByRole('cell', { name: '2', exact: true })).toBeVisible()
  await expect(impor.getByRole('button', { name: /^Terapkan/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await impor.getByRole('button', { name: 'Batal' }).click()

  // Formularium
  await page.getByRole('tab', { name: 'Formularium' }).click()
  await page.getByRole('button', { name: 'Tambah formularium' }).click()
  const formularium = page.getByRole('dialog', { name: 'Tambah formularium' })
  await formularium.getByLabel('Kode formularium').fill(KODE_FRM)
  await formularium.getByLabel('Jenis formularium').selectOption('rs')
  await formularium.getByLabel('Nama formularium').fill(NAMA_FRM)
  await formularium.getByLabel('Berlaku dari').fill(HARI)
  await formularium.getByLabel('Berlaku sampai').fill(HARI)
  await formularium.getByLabel('Dasar perubahan').fill('Uji e2e')
  await formularium.getByRole('button', { name: 'Tambah formularium' }).click()
  await expect(formularium).toHaveCount(0)

  await page.getByRole('row', { name: new RegExp(KODE_FRM) }).click()
  await expect(page.getByRole('heading', { name: `Item ${NAMA_FRM}` })).toBeVisible()
  await page.getByRole('button', { name: 'Tambah barang' }).click()
  const item = page.getByRole('dialog', { name: 'Tambah barang ke formularium' })
  await item.getByLabel('Cari barang aktif').fill(KODE_BARANG)
  await item.getByRole('button', { name: new RegExp(KODE_BARANG) }).click()
  await item.getByLabel('Maksimum per resep').fill('10')
  await item.getByLabel('Dasar perubahan').fill('Uji e2e')
  await item.getByRole('button', { name: 'Tambah ke formularium' }).click()
  await expect(item).toHaveCount(0)

  const barisItem = page.getByRole('row', { name: new RegExp(KODE_BARANG) })
  await expect(barisItem).toContainText('10 tablet')
  await expect(barisItem).toContainText('Aktif')
  expect(await pelanggaranAxe(page)).toEqual([])

  await barisItem.getByRole('button', { name: `Nonaktifkan item ${NAMA_BARANG}` }).click()
  const status = page.getByRole('alertdialog', { name: 'Nonaktifkan item formularium?' })
  await status.getByLabel('Dasar perubahan').fill('Uji e2e')
  await status.getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(status).toHaveCount(0)
  await expect(barisItem).toHaveCount(0)
  await page.context().close()
})
