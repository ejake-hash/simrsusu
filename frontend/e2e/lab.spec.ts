import { expect, test, type Browser, type Page } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, halamanAkun, pastikanPasienBpjs, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * 2.30-fe: dokter poli (`dokter.poli`) memesan lab CITO dari Pemeriksaan → analis (`analis`, peran contoh_analis, izin
 * penunjang.laboratorium.lihat/input/verifikasi cakupan RS) melihatnya di seksi Cito Penunjang → Laboratorium dengan identitas
 * dua penanda, mengambil sampel (jenis + lokasi) → barcode LAB-YYYYMMDD-NNNNN, lalu menerima spesimen di lab → order satu
 * butir keluar dari daftar kerja. Dokter lalu melihat butir "Diterima lab" tanpa tombol Batalkan. Backend asli @1440 px +
 * axe. Data SINTETIS: peserta BPJS contoh, poli contoh, katalog CONTOH-LAB-*. Indikasi unik per jalan agar baris order ini
 * bisa dibedakan dari order jalan sebelumnya. Seri: langkah berikut memakai nomor dan indikasi langkah sebelumnya.
 */
const kartu = '9990000000001'
const nama = 'Peserta Sintetis BPJS'
const poli = 'Poli Penyakit Dalam (contoh)'
const pemeriksaan = 'Pemeriksaan lab contoh satu'
const indikasi = `Indikasi cito e2e ${Date.now() % 100000}`
let nomor = ''
let barcode = ''

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  bersihkanPesertaSintetis()
  artisan()
})
test.afterAll(() => {
  bersihkanPesertaSintetis()
  artisan('--batal')
})

/** Dokter poli membuka Pemeriksaan kunjungan `nomor` (semua tahap) dan mengembalikan wilayah Order penunjang. */
async function bukaOrder(browser: Browser, asal: string): Promise<{ page: Page; order: ReturnType<Page['getByRole']> }> {
  const page = await halamanAkun(browser, asal, 'dokter.poli')
  await page.goto('/rawat-jalan/pemeriksaan')
  await expect(page.getByRole('heading', { name: 'Pemeriksaan', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  await page.getByRole('combobox', { name: 'Tahap' }).selectOption('')
  const baris = page.getByRole('region', { name: 'Worklist poliklinik' }).getByRole('row', { name: new RegExp(nama) })
  await expect(baris.first()).toBeVisible()
  if (!nomor) nomor = (await baris.first().getByRole('cell').first().textContent())!.trim()
  await page.getByRole('button', { name: `Periksa ${nomor}` }).click()
  const order = page.getByRole('region', { name: 'Pemeriksaan dokter' }).getByRole('region', { name: 'Order penunjang' })
  await expect(order).toBeVisible()
  return { page, order }
}

test('dokter poli memesan lab cito dari pemeriksaan', async ({ browser }, info) => {
  test.setTimeout(150_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page: admisi } = await halamanAdmisi(browser, asal)
  await tungguJatahPenuh(konteks.request, asal)
  await pastikanPasienBpjs(admisi, nama, kartu)
  await daftarkanKunjunganJkn(admisi, nama, kartu)
  await konteks.close()

  const { page, order } = await bukaOrder(browser, asal)
  await order.getByRole('radio', { name: 'Cito' }).check()
  await order.getByRole('button', { name: 'Tambah pemeriksaan' }).click()
  await page.getByRole('combobox', { name: 'Cari pemeriksaan laboratorium' }).fill('lab contoh')
  await page.getByRole('option', { name: /Pemeriksaan lab contoh satu/ }).click()
  await order.getByRole('textbox', { name: /Indikasi klinis/ }).fill(indikasi)
  await order.getByRole('button', { name: 'Kirim order laboratorium' }).click()
  // Pasien contoh dipakai spec lain hari ini: butir yang sama bisa terhitung order ganda.
  const ganda = order.getByRole('textbox', { name: /Alasan tetap memesan/ })
  const terkirim = page.getByText('Order laboratorium terkirim: 1 pemeriksaan.')
  await expect(terkirim.or(ganda)).toBeVisible()
  if (await ganda.isVisible()) {
    await ganda.fill('Cito uji sintetis')
    await order.getByRole('button', { name: 'Tetap kirim order' }).click()
  }
  await expect(terkirim).toBeVisible()
  const butir = order.getByRole('listitem').filter({ hasText: indikasi })
  await expect(butir).toContainText('Cito')
  await expect(butir.getByRole('button', { name: /Batalkan order/ })).toBeVisible()
  await page.close()
})

test('analis: order cito di seksi Cito, ambil sampel → barcode, terima di lab → keluar dari daftar', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'analis')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/penunjang/laboratorium')
  await expect(page.getByRole('heading', { name: 'Daftar kerja laboratorium', level: 1 })).toBeVisible()
  const cito = page.getByRole('region', { name: 'Daftar kerja lab Cito' })
  const baris = cito.getByRole('row').filter({ hasText: indikasi })
  await expect(baris).toHaveCount(1)
  await expect(baris).toContainText(nama)
  await expect(baris).toContainText(/No\. RM [\d.]+ · lahir /)
  await expect(baris).toContainText(`Poliklinik · ${poli}`)
  await expect(baris).toContainText('Dipesan')
  // Order ini tidak ikut di seksi Rutin.
  await expect(page.getByRole('region', { name: 'Daftar kerja lab Rutin' }).getByRole('row').filter({ hasText: indikasi })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])

  await baris.getByRole('button', { name: `Ambil sampel ${pemeriksaan} ${nama}` }).click()
  const dialog = page.getByRole('dialog', { name: `Ambil sampel ${pemeriksaan}` })
  // Identitas dua penanda tampil sebelum konfirmasi (5.1 butir 9).
  await expect(dialog).toContainText(new RegExp(`${nama}, No\\. RM [\\d.]+, lahir `))
  await dialog.getByRole('textbox', { name: /Jenis spesimen/ }).fill('Darah vena')
  await dialog.getByRole('radio', { name: 'Di samping tempat tidur' }).check()
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Catat sampel dan buat barcode' }).click()
  await expect(dialog).toHaveCount(0)
  const hasil = page.getByRole('status').filter({ hasText: /tercatat\. Barcode/ })
  await expect(hasil).toContainText(new RegExp(`Sampel ${pemeriksaan} untuk ${nama} tercatat\\. Barcode LAB-\\d{8}-\\d{5}\\.`))
  barcode = /LAB-\d{8}-\d{5}/.exec((await hasil.textContent()) ?? '')![0]
  await expect(baris).toContainText(barcode)
  await expect(baris).toContainText('Sampel diambil')
  await expect(baris.getByRole('button', { name: /^Ambil sampel/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])

  await baris.getByRole('button', { name: `Terima di lab ${pemeriksaan} ${nama}` }).click()
  const terima = page.getByRole('dialog', { name: `Terima spesimen ${barcode}` })
  await expect(terima).toContainText(new RegExp(`${nama}, No\\. RM [\\d.]+, lahir `))
  expect(await pelanggaranAxe(page)).toEqual([])
  await terima.getByRole('button', { name: 'Terima di lab' }).click()
  await expect(terima).toHaveCount(0)
  await expect(page.getByRole('status').filter({ hasText: /diterima di lab\./ })).toContainText(`Spesimen ${barcode} (${pemeriksaan}, ${nama})`)
  // Satu butir sudah diterima: order tidak lagi aktif di daftar kerja.
  await expect(baris).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('pemesan melihat butir Diterima lab dan tidak bisa membatalkan order itu', async ({ browser }, info) => {
  test.setTimeout(90_000)
  expect(barcode).not.toBe('')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { page, order } = await bukaOrder(browser, asal)
  const butir = order.getByRole('listitem').filter({ hasText: indikasi })
  await expect(butir).toContainText('Diterima lab')
  await expect(butir).toContainText('Sudah diterima lab, tidak bisa dibatalkan.')
  await expect(butir.getByRole('button', { name: /Batalkan order/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})
