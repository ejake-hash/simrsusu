import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, pastikanPasienBpjs, php, SANDI } from './bantu'

/**
 * Keluar Tahap A (backlog fase 2): pasien BPJS → kunjungan poli → SEP (simulator) → nomor antrean → dipanggil petugas →
 * tampil di display, lalu aksi petugas lainnya (jeda panggilan, lewati, sisip, batal). Akun contoh.admisi; display tanpa
 * sesi dengan token perangkat dari `antrean:token-display` (baris pertama keluaran). Memakai data contoh yang sama dengan
 * pendaftaran.spec.ts, jadi berjalan sesudahnya (playwright.config.ts). Seri: aksi petugas memakai nomor dari uji pertama.
 */
const kartu = '9990000000001' // satu-satunya peserta yang diterima simulator VClaim
const nama = 'Peserta Sintetis BPJS'
let token = ''
let nomor = ''

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  bersihkanPesertaSintetis()
  artisan()
  token = php('antrean:token-display', 'tv-poli-pd', '--area=poli-pd').split('\n')[0]!.trim()
})
test.afterAll(() => {
  bersihkanPesertaSintetis()
  php('antrean:token-display', 'tv-poli-pd', '--cabut')
  artisan('--batal')
})

const axeBerat = async (page: Page) => (await new AxeBuilder({ page }).analyze()).violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)

async function layarAntrean(page: Page) {
  await page.goto('/pendaftaran/antrean')
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: 'Poli Penyakit Dalam (contoh)' })
  const baris = page.getByRole('region', { name: 'Antrean poliklinik' }).getByRole('row', { name: new RegExp(nama) })
  await expect(baris).toBeVisible()
  return baris
}

test('keluar Tahap A: pasien BPJS → kunjungan → SEP → antrean → dipanggil → display menampilkan nomornya', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page } = await halamanAdmisi(browser, asal)

  await pastikanPasienBpjs(page, nama, kartu)
  const panel = await daftarkanKunjunganJkn(page, nama, kartu)
  await panel.getByRole('textbox', { name: 'No. kartu BPJS atau NIK' }).fill(kartu)
  await panel.getByRole('button', { name: 'Periksa kepesertaan' }).click()
  await expect(panel.getByText('Peserta aktif')).toBeVisible()
  await panel.getByRole('textbox', { name: /Nomor rujukan/ }).fill('SIMHABIS001')
  await panel.getByRole('button', { name: 'Periksa rujukan' }).click()
  await panel.getByRole('button', { name: 'Terbitkan SEP', exact: true }).click()
  await page.getByRole('dialog', { name: 'Terbitkan SEP' }).getByRole('button', { name: 'Terbitkan SEP' }).click()
  await expect(panel.getByText(/^SEP terbit\. No\. SEP /)).toBeVisible()

  // Nomor antrean terbit bersama kunjungan; petugas memanggilnya.
  const baris = await layarAntrean(page)
  nomor = (await baris.getByRole('cell').first().textContent())!.trim()
  expect(nomor).toMatch(/^PD-\d{3,}$/)
  await expect(baris).toContainText('Menunggu')
  await expect(baris).toContainText('No. RM')
  await baris.getByRole('button', { name: `Panggil ${nomor}` }).click()
  await expect(page.getByText(`Nomor ${nomor} dipanggil.`)).toBeVisible()
  await expect(baris).toContainText('Dipanggil')
  expect(await axeBerat(page)).toEqual([])

  // Display: perangkat tanpa sesi, token lewat fragmen URL lalu dihapus dari URL dan disimpan di localStorage.
  const tv = await browser.newContext({ baseURL: asal, viewport: { width: 1920, height: 1080 }, reducedMotion: 'reduce' })
  const layar = await tv.newPage()
  const permintaan: string[] = []
  layar.on('request', (r) => permintaan.push(r.url()))
  await layar.goto(`/display/poli-pd#token=${token}`)
  await expect(layar.getByTestId('nomor-utama')).toHaveText(nomor)
  await expect(layar.getByRole('heading', { level: 1 })).not.toBeEmpty()
  await expect(layar.getByText('Sedang dipanggil')).toBeVisible()
  expect(layar.url()).not.toContain('token')
  expect(permintaan.some((u) => u.includes(token.split('|')[1]!))).toBe(false)
  expect(await layar.evaluate(() => localStorage.getItem('karsitara.display.token'))).toBe(token)
  // Hanya nomor, inisial, tujuan: nama dan No. RM pasien tidak ada di layar.
  await expect(layar.locator('body')).not.toContainText(nama)
  await expect(layar.locator('body')).not.toContainText('No. RM')
  expect(await axeBerat(layar)).toEqual([])
  // Muat ulang tanpa fragmen: token tersimpan dipakai.
  await layar.reload()
  await expect(layar.getByTestId('nomor-utama')).toHaveText(nomor)
  await tv.close()
  await konteks.close()
})

test('layar antrean: jeda panggilan ditolak jelas, lewati, sisip, batal beralasan; display menolak token salah', async ({ browser }, info) => {
  test.setTimeout(90_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page } = await halamanAdmisi(browser, asal)
  const baris = await layarAntrean(page)
  await expect(baris).toContainText(nomor)
  await expect(baris).toContainText('Dipanggil')

  // Panggil ulang segera: jeda dari data induk belum lewat (409), pesan server tampil dan baris tidak berubah.
  await baris.getByRole('button', { name: `Panggil ulang ${nomor}` }).click()
  await expect(page.getByRole('alert').filter({ hasText: 'Panggil lagi mulai pukul' })).toBeVisible()
  await expect(baris).toContainText('Panggilan 1 dari')

  await baris.getByRole('button', { name: `Lewati ${nomor}` }).click()
  await expect(baris).toContainText('Dilewati')
  await baris.getByRole('button', { name: `Sisipkan ${nomor}` }).click()
  await expect(baris).toContainText('Menunggu')

  await baris.getByRole('button', { name: `Batalkan ${nomor}` }).click()
  const dialog = page.getByRole('alertdialog', { name: 'Batalkan nomor antrean' })
  await expect(dialog).toContainText(nama)
  await expect(dialog.getByRole('button', { name: 'Batalkan nomor' })).toBeDisabled()
  await dialog.getByRole('textbox', { name: /Alasan pembatalan/ }).fill('Pasien pulang (uji e2e)')
  await dialog.getByRole('button', { name: 'Batalkan nomor' }).click()
  await expect(page.getByText(`Nomor ${nomor} dibatalkan.`)).toBeVisible()
  await expect(baris).toHaveCount(0) // bawaan: semua kecuali batal
  await page.getByRole('combobox', { name: 'Status' }).selectOption('batal')
  await expect(page.getByRole('region', { name: 'Antrean poliklinik' }).getByRole('row', { name: new RegExp(`${nomor}.*Pasien pulang`) })).toBeVisible()
  expect(await axeBerat(page)).toEqual([])

  // Display dengan token salah: ditolak dan token tersimpan dibuang.
  const tv = await browser.newContext({ baseURL: asal })
  const layar = await tv.newPage()
  await layar.goto('/display/poli-pd#token=0|tokensalah')
  await expect(layar.getByRole('heading', { name: 'Display tidak dapat dibuka' })).toBeVisible()
  expect(await layar.evaluate(() => localStorage.getItem('karsitara.display.token'))).toBeNull()
  await tv.close()
  await konteks.close()
})
