import { expect, test, type Page } from '@playwright/test'
import { artisan, halamanAdmisi, pelanggaranAxe, php, SANDI } from './bantu'

/**
 * 2.20-fe: anjungan tahap 1 ke backend asli. Kios tanpa akun memakai token perangkat SINTETIS `apm-contoh` dari
 * `antrean:token-anjungan` (baris pertama keluaran; perangkat dibuat `lokal:kunjungan-contoh`), lalu petugas admisi
 * memanggil nomor itu dari antrean loket. Layar kios diuji di 1440 dan 1024 px (layar sentuh) + axe: tombol ≥ 64 px,
 * teks ≥ 20 px. Berjalan sesudah akses-darurat (playwright.config.ts) karena memakai data contoh yang sama.
 */
let token = ''
let nomor = ''

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  artisan()
  token = php('antrean:token-anjungan', 'apm-contoh').split('\n')[0]!.trim()
  expect(token).toMatch(/^[^|\s]+\|\S+$/)
})
test.afterAll(() => {
  php('antrean:token-anjungan', 'apm-contoh', '--cabut')
  artisan('--batal')
})

/** Semua tombol terlihat ≥ 64 px tinggi dan teks tombol ≥ 20 px (uji penerimaan 2.20). */
async function ukuranSentuh(page: Page) {
  const ukuran = await page.locator('main button:visible').evaluateAll((el) =>
    el.map((b) => ({ teks: b.textContent?.trim() ?? '', tinggi: b.getBoundingClientRect().height, huruf: parseFloat(getComputedStyle(b).fontSize) })),
  )
  expect(ukuran.length).toBeGreaterThan(0)
  for (const u of ukuran) {
    expect.soft(u.tinggi, `tinggi tombol "${u.teks}"`).toBeGreaterThanOrEqual(64)
    expect.soft(u.huruf, `huruf tombol "${u.teks}"`).toBeGreaterThanOrEqual(20)
  }
}

for (const lebar of [1440, 1024]) {
  test(`kios @${lebar}: ambil nomor prioritas, struk tampil, jadwal dokter tanpa data pasien`, async ({ browser }, info) => {
    const asal = new URL(info.project.use.baseURL ?? '').origin
    const kios = await browser.newContext({ baseURL: asal, viewport: { width: lebar, height: lebar === 1440 ? 900 : 768 }, hasTouch: true, reducedMotion: 'reduce' })
    const page = await kios.newPage()
    const permintaan: string[] = []
    page.on('request', (r) => permintaan.push(r.url()))

    await page.goto(`/anjungan#token=${token}`)
    await expect(page.getByRole('heading', { level: 1, name: /Silakan pilih layanan/ })).toBeVisible()
    await expect(page.getByText('Lobi Rawat Jalan (contoh)')).toBeVisible()
    expect(page.url()).not.toContain('token')
    expect(permintaan.some((u) => u.includes(token.split('|')[1]!))).toBe(false)
    await ukuranSentuh(page)
    expect(await pelanggaranAxe(page)).toEqual([])

    await page.getByRole('button', { name: /Ambil nomor antrean pendaftaran/ }).click()
    await page.getByRole('button', { name: /Antrean prioritas/ }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Pilih alasan antrean prioritas' })).toBeFocused()
    await ukuranSentuh(page)
    expect(await pelanggaranAxe(page)).toEqual([])
    await page.getByRole('button', { name: 'Ibu hamil' }).click()

    const struk = page.getByTestId('struk-loket')
    await expect(struk).toContainText('Antrean prioritas')
    await expect(struk).toContainText('Loket Pendaftaran 1 (contoh)')
    nomor = (await page.getByTestId('nomor-loket').textContent())!.trim()
    expect(nomor).toMatch(/^P-\d{3,}$/)
    expect(await pelanggaranAxe(page)).toEqual([])
    await page.getByRole('button', { name: 'Selesai' }).click()

    await page.getByRole('button', { name: /Lihat jadwal dokter/ }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Jadwal dokter' })).toBeVisible()
    await expect(page.getByRole('group', { name: 'Pilih tanggal' }).getByRole('button')).toHaveCount(7)
    // lokal:kunjungan-contoh membuat jadwal sintetis setiap hari 00.00–23.59 di poli contoh.
    const poliContoh = page.getByRole('region', { name: 'Poli Penyakit Dalam (contoh)' })
    await expect(poliContoh).toBeVisible()
    await expect(poliContoh.getByText(/^(Tersedia|Penuh)$/).first()).toBeVisible()
    await ukuranSentuh(page)
    expect(await pelanggaranAxe(page)).toEqual([])
    await kios.close()
  })
}

test('petugas loket melihat nomor prioritas dari anjungan beserta alasannya, lalu memanggilnya', async ({ browser }, info) => {
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page } = await halamanAdmisi(browser, asal)
  await page.goto('/pendaftaran/antrean')
  await page.getByRole('combobox', { name: 'Titik antrean' }).selectOption('loket')
  const tabel = page.getByRole('region', { name: 'Antrean loket pendaftaran' })
  const baris = tabel.getByRole('row', { name: new RegExp(`^${nomor}\\b`) })
  await expect(baris).toContainText('Prioritas')
  await expect(baris).toContainText('Ibu hamil')
  await expect(baris).toContainText('Menunggu')
  // Loket admisi tidak punya lokasi bawaan: panggil baru bisa sesudah petugas memilih lokasinya.
  await expect(baris.getByRole('button', { name: `Panggil ${nomor}` })).toBeDisabled()
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.getByRole('combobox', { name: /Loket Anda/ }).selectOption({ label: 'Loket Pendaftaran 1 (contoh)' })
  await baris.getByRole('button', { name: `Panggil ${nomor}` }).click()
  await expect(page.getByText(`Nomor ${nomor} dipanggil.`)).toBeVisible()
  await expect(baris).toContainText('Dipanggil')
  await expect(baris).toContainText('Loket Pendaftaran 1 (contoh)')
  await konteks.close()
})

test('token anjungan dicabut saat kios terbuka: layanan berikutnya meminta bantuan petugas dan token dibuang', async ({ browser }, info) => {
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const kios = await browser.newContext({ baseURL: asal, viewport: { width: 1440, height: 900 } })
  const page = await kios.newPage()
  await page.goto(`/anjungan#token=${token}`)
  await expect(page.getByRole('heading', { level: 1, name: /Silakan pilih layanan/ })).toBeVisible()
  php('antrean:token-anjungan', 'apm-contoh', '--cabut')
  try {
    await page.getByRole('button', { name: /Lihat jadwal dokter/ }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Anjungan tidak dapat dipakai' })).toBeVisible()
    expect(await page.evaluate(() => localStorage.getItem('karsitara.anjungan.token'))).toBeNull()
    await expect(page.locator('body')).not.toContainText('token')
  } finally {
    // --cabut menonaktifkan perangkat; buat token baru agar afterAll dan jalan berikutnya berangkat dari perangkat aktif.
    token = php('antrean:token-anjungan', 'apm-contoh').split('\n')[0]!.trim()
    await kios.close()
  }
})
