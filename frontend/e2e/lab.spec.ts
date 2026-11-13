import { expect, test, type Browser, type Locator, type Page } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, halamanAkun, pastikanPasienBpjs, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * 2.30-fe: dokter poli (`dokter.poli`) memesan lab CITO dari Pemeriksaan → analis (`analis`, peran contoh_analis, izin
 * penunjang.laboratorium.lihat/input/verifikasi/sahkan cakupan RS) melihatnya di seksi Cito Penunjang → Laboratorium dengan
 * identitas dua penanda, mengambil sampel (jenis + lokasi) → barcode LAB-YYYYMMDD-NNNNN, lalu menerima spesimen di lab →
 * order keluar dari tahap Spesimen. Dokter lalu melihat butir "Diterima lab" tanpa tombol Batalkan.
 * 2.31-fe: analis di tahap Hasil menginput hasil (penanda berteks dari server), memvalidasi lewat dialog beridentitas, dan
 * membuat satu adendum; Sp.PK (`dokter.pk`) mengotorisasi butir bertanda butuh otorisasi; dokter poli melihat linimasa hasil.
 * Data induk SINTETIS dari `lokal:kunjungan-contoh`: CONTOH-LAB-01 numerik dengan rujukan per JK (pasien contoh perempuan),
 * -03 butuh Sp.PK, -04 tanpa rujukan. Nilai input memakai angka seeder (5 dan 10), bukan angka klinis.
 * Backend asli @1440 px + axe. Indikasi dan alasan adendum unik per jalan agar baris jalan sebelumnya bisa dibedakan.
 * Seri: langkah berikut memakai nomor dan indikasi langkah sebelumnya.
 */
const kartu = '9990000000001'
const nama = 'Peserta Sintetis BPJS'
const poli = 'Poli Penyakit Dalam (contoh)'
const satu = 'Pemeriksaan lab contoh satu'
const tiga = 'Pemeriksaan lab contoh tiga'
const empat = 'Pemeriksaan lab contoh empat'
const pemeriksaan = [satu, tiga, empat]
const jalan = Date.now() % 100000
const indikasi = `Indikasi cito e2e ${jalan}`
const alasanAdendum = `Koreksi uji sintetis ${jalan}`
const identitas = new RegExp(`${nama}, No\\. RM [\\d.]+, lahir `)
let nomor = ''

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

/** Dokter poli membuka Pemeriksaan kunjungan `nomor` (semua tahap) dan mengembalikan panel pemeriksaan dokter. */
async function bukaPemeriksaan(browser: Browser, asal: string): Promise<{ page: Page; panel: Locator; order: Locator }> {
  const page = await halamanAkun(browser, asal, 'dokter.poli')
  await page.goto('/rawat-jalan/pemeriksaan')
  await expect(page.getByRole('heading', { name: 'Pemeriksaan', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  await page.getByRole('combobox', { name: 'Tahap' }).selectOption('')
  const baris = page.getByRole('region', { name: 'Worklist poliklinik' }).getByRole('row', { name: new RegExp(nama) })
  await expect(baris.first()).toBeVisible()
  if (!nomor) nomor = (await baris.first().getByRole('cell').first().textContent())!.trim()
  await page.getByRole('button', { name: `Periksa ${nomor}` }).click()
  const panel = page.getByRole('region', { name: 'Pemeriksaan dokter' })
  const order = panel.getByRole('region', { name: 'Order penunjang' })
  await expect(order).toBeVisible()
  return { page, panel, order }
}

/** Buka Penunjang → Laboratorium lewat kartu Beranda (klik, bukan page.goto ke rute lab). */
async function bukaLab(page: Page) {
  await page.goto('/')
  const kartu = page.getByRole('region', { name: 'Modul saya' }).getByRole('article', { name: 'Penunjang' })
  await expect(kartu.getByText(/Segera hadir/)).toHaveCount(0)
  await kartu.getByRole('link', { name: 'Laboratorium', exact: true }).click()
  await expect(page).toHaveURL(/\/penunjang\/laboratorium$/)
  await expect(page.getByRole('heading', { name: 'Daftar kerja laboratorium', level: 1 })).toBeVisible()
}

test('dokter poli memesan lab cito dari pemeriksaan', async ({ browser }, info) => {
  test.setTimeout(150_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page: admisi } = await halamanAdmisi(browser, asal)
  await tungguJatahPenuh(konteks.request, asal)
  await pastikanPasienBpjs(admisi, nama, kartu)
  await daftarkanKunjunganJkn(admisi, nama, kartu)
  await konteks.close()

  const { page, order } = await bukaPemeriksaan(browser, asal)
  await order.getByRole('radio', { name: 'Cito' }).check()
  for (const p of pemeriksaan) {
    await order.getByRole('button', { name: 'Tambah pemeriksaan' }).click()
    await page.getByRole('combobox', { name: 'Cari pemeriksaan laboratorium' }).fill(p.replace('Pemeriksaan ', ''))
    await page.getByRole('option', { name: new RegExp(p) }).click()
  }
  await order.getByRole('textbox', { name: /Indikasi klinis/ }).fill(indikasi)
  await order.getByRole('button', { name: 'Kirim order laboratorium' }).click()
  // Pasien contoh dipakai spec lain hari ini: butir yang sama bisa terhitung order ganda.
  const ganda = order.getByRole('textbox', { name: /Alasan tetap memesan/ })
  const terkirim = page.getByText('Order laboratorium terkirim: 3 pemeriksaan.')
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

test('analis: order cito di seksi Cito, ambil sampel → barcode, terima di lab → keluar dari tahap Spesimen', async ({ browser }, info) => {
  test.setTimeout(180_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'analis')
  await tungguJatahPenuh(page.context().request, asal)
  await bukaLab(page)
  await expect(page.getByRole('tab', { name: 'Spesimen' })).toHaveAttribute('aria-selected', 'true')
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

  for (const [n, p] of pemeriksaan.entries()) {
    await baris.getByRole('button', { name: `Ambil sampel ${p} ${nama}` }).click()
    const dialog = page.getByRole('dialog', { name: `Ambil sampel ${p}` })
    // Identitas dua penanda tampil sebelum konfirmasi (5.1 butir 9).
    await expect(dialog).toContainText(identitas)
    await dialog.getByRole('textbox', { name: /Jenis spesimen/ }).fill('Darah vena')
    await dialog.getByRole('radio', { name: 'Di samping tempat tidur' }).check()
    if (n === 0) expect(await pelanggaranAxe(page)).toEqual([])
    await dialog.getByRole('button', { name: 'Catat sampel dan buat barcode' }).click()
    await expect(dialog).toHaveCount(0)
    const hasil = page.getByRole('status').filter({ hasText: /tercatat\. Barcode/ })
    await expect(hasil).toContainText(new RegExp(`Sampel ${p} untuk ${nama} tercatat\\. Barcode LAB-\\d{8}-\\d{5}\\.`))
    const barcode = /LAB-\d{8}-\d{5}/.exec((await hasil.textContent()) ?? '')![0]
    await expect(baris).toContainText(barcode)
    await expect(baris.getByRole('button', { name: `Ambil sampel ${p} ${nama}` })).toHaveCount(0)

    await baris.getByRole('button', { name: `Terima di lab ${p} ${nama}` }).click()
    const terima = page.getByRole('dialog', { name: `Terima spesimen ${barcode}` })
    await expect(terima).toContainText(identitas)
    if (n === 0) expect(await pelanggaranAxe(page)).toEqual([])
    await terima.getByRole('button', { name: 'Terima di lab' }).click()
    await expect(terima).toHaveCount(0)
    await expect(page.getByRole('status').filter({ hasText: /diterima di lab\./ })).toContainText(`Spesimen ${barcode} (${p}, ${nama})`)
  }
  // Semua butir sudah diterima: order tidak lagi aktif di tahap Spesimen.
  await expect(baris).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('pemesan melihat butir Diterima lab dan tidak bisa membatalkan order itu', async ({ browser }, info) => {
  test.setTimeout(90_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { page, order } = await bukaPemeriksaan(browser, asal)
  const butir = order.getByRole('listitem').filter({ hasText: indikasi })
  await expect(butir).toContainText('Diterima lab')
  await expect(butir).toContainText('Sudah diterima lab, tidak bisa dibatalkan.')
  await expect(butir.getByRole('button', { name: /Batalkan order/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('analis tahap Hasil: input hasil berpenanda, validasi beridentitas, adendum', async ({ browser }, info) => {
  test.setTimeout(180_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'analis')
  await tungguJatahPenuh(page.context().request, asal)
  await bukaLab(page)
  await page.getByRole('tab', { name: 'Hasil' }).click()
  const baris = page.getByRole('region', { name: 'Daftar kerja lab Cito' }).getByRole('row').filter({ hasText: indikasi })
  await expect(baris).toHaveCount(1)
  await expect(baris).toContainText('Diterima lab')
  expect(await pelanggaranAxe(page)).toEqual([])

  // Input 5 untuk tiap butir: penanda dihitung server dari data induk sintetis.
  const penanda: Record<string, string> = { [satu]: 'Rendah', [tiga]: 'Tinggi', [empat]: 'Tanpa nilai rujukan RS, tidak ditandai' }
  for (const [n, p] of pemeriksaan.entries()) {
    await baris.getByRole('button', { name: `Input hasil ${p} ${nama}`, exact: true }).click()
    const dialog = page.getByRole('dialog', { name: `Input hasil ${p}` })
    await expect(dialog).toContainText(identitas)
    await dialog.getByRole('textbox', { name: /^Hasil/ }).fill('5')
    if (n === 0) expect(await pelanggaranAxe(page)).toEqual([])
    await dialog.getByRole('button', { name: 'Simpan hasil' }).click()
    await expect(dialog).toHaveCount(0)
    await expect(page.getByRole('status').filter({ hasText: /tersimpan:/ })).toContainText(`Hasil ${p} untuk ${nama} tersimpan: 5`)
    await expect(baris.getByRole('listitem').filter({ hasText: p })).toContainText(penanda[p]!)
  }
  await expect(baris.getByRole('listitem').filter({ hasText: satu })).toContainText('Rujukan 8–18')
  expect(await pelanggaranAxe(page)).toEqual([])

  // Validasi butir satu: dialog menampilkan identitas dan hasil sebelum tombol konfirmasi.
  await baris.getByRole('button', { name: `Validasi ${satu} ${nama}`, exact: true }).click()
  const validasi = page.getByRole('dialog', { name: `Validasi hasil ${satu}` })
  await expect(validasi).toContainText(identitas)
  await expect(validasi).toContainText('Rendah')
  expect(await pelanggaranAxe(page)).toEqual([])
  await validasi.getByRole('button', { name: 'Validasi hasil' }).click()
  await expect(validasi).toHaveCount(0)
  await expect(page.getByRole('status').filter({ hasText: /divalidasi\./ })).toContainText(`Hasil ${satu} untuk ${nama} divalidasi. Hasil keluar`)

  // Adendum butir satu selagi order masih di tahap Hasil: hasil asli tetap terlihat, adendum bertanda.
  await baris.getByRole('button', { name: `Adendum ${satu} ${nama}`, exact: true }).click()
  const adendum = page.getByRole('dialog', { name: `Adendum ${satu}` })
  await expect(adendum).toContainText(identitas)
  await adendum.getByRole('textbox', { name: /Hasil koreksi/ }).fill('10')
  await adendum.getByRole('textbox', { name: /Alasan koreksi/ }).fill(alasanAdendum)
  expect(await pelanggaranAxe(page)).toEqual([])
  await adendum.getByRole('button', { name: 'Simpan adendum' }).click()
  await expect(adendum).toHaveCount(0)
  const butirSatu = baris.getByRole('listitem').filter({ hasText: satu })
  await expect(butirSatu).toContainText('Hasil asli')
  await expect(butirSatu).toContainText('Adendum')
  await expect(butirSatu).toContainText(`Alasan: ${alasanAdendum}`)

  for (const p of [empat, tiga]) {
    await baris.getByRole('button', { name: `Validasi ${p} ${nama}`, exact: true }).click()
    const d = page.getByRole('dialog', { name: `Validasi hasil ${p}` })
    await expect(d).toContainText(identitas)
    await d.getByRole('button', { name: 'Validasi hasil' }).click()
    await expect(d).toHaveCount(0)
  }
  await expect(page.getByRole('status').filter({ hasText: /divalidasi\./ })).toContainText(`Hasil ${tiga} untuk ${nama} divalidasi. Menunggu otorisasi Sp.PK.`)
  // Tidak ada butir tersisa di tahap Hasil.
  await expect(baris).toHaveCount(0)
  await page.close()
})

test('Sp.PK tahap Otorisasi: otorisasi lewat dialog beridentitas', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'dokter.pk')
  await tungguJatahPenuh(page.context().request, asal)
  await bukaLab(page)
  await page.getByRole('tab', { name: 'Otorisasi' }).click()
  const baris = page.getByRole('region', { name: 'Daftar kerja lab Cito' }).getByRole('row').filter({ hasText: indikasi })
  await expect(baris).toHaveCount(1)
  await expect(baris).toContainText('Menunggu otorisasi Sp.PK')
  expect(await pelanggaranAxe(page)).toEqual([])
  await baris.getByRole('button', { name: `Otorisasi ${tiga} ${nama}`, exact: true }).click()
  const dialog = page.getByRole('dialog', { name: `Otorisasi hasil ${tiga}` })
  await expect(dialog).toContainText(identitas)
  await expect(dialog).toContainText('Tinggi')
  await expect(dialog).toContainText('Divalidasi analis')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Otorisasi hasil' }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByRole('status').filter({ hasText: /diotorisasi\./ })).toContainText(`Hasil ${tiga} untuk ${nama} diotorisasi. Hasil keluar`)
  await expect(baris).toHaveCount(0)
  await page.close()
})

test('pemesan melihat hasil di linimasa kunjungan: penanda berteks dan adendum bertanda', async ({ browser }, info) => {
  test.setTimeout(90_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { page, panel } = await bukaPemeriksaan(browser, asal)
  const linimasa = panel.getByRole('region', { name: 'Hasil laboratorium' })
  const hasilSatu = linimasa.getByRole('listitem').filter({ hasText: alasanAdendum }).first()
  await expect(hasilSatu).toContainText(satu)
  await expect(hasilSatu).toContainText('Rendah')
  await expect(hasilSatu).toContainText('Hasil asli')
  await expect(hasilSatu).toContainText('Adendum')
  await expect(linimasa.getByRole('listitem').filter({ hasText: tiga }).first()).toContainText('Tinggi')
  await expect(linimasa.getByRole('listitem').filter({ hasText: tiga }).first()).toContainText('Diotorisasi Sp.PK')
  await expect(linimasa.getByRole('listitem').filter({ hasText: empat }).first()).toContainText('Tanpa nilai rujukan RS, tidak ditandai')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})
