import { expect, test, type Page } from '@playwright/test'
import { artisan, halamanAkun, pelanggaranAxe, SANDI } from './bantu'

/**
 * Pendaftaran → duplikat pasien + Master pasien gabung RM (tiket 2.9) ke backend asli. contoh.admisi mendaftarkan dua
 * pasien bernama sama dan tanggal lahir sama (yang kedua memunculkan peringatan duplikat), lalu mengusulkan gabung;
 * contoh.rekam.medis (pemverifikasi, bukan pengusul) menyetujui. Nama dan tanggal lahir unik per jalan; pendaftaran
 * pasien butuh instalasi siap melayani (`lokal:kunjungan-contoh`).
 */
const HURUF = Date.now().toString(36).toUpperCase().replace(/\d/g, (d) => 'QRSTUVWXYZ'[Number(d)])
const NAMA = `Contoh Ganda ${HURUF}`
const LAHIR = new Date(Date.UTC(1950, 0, 1) + (Date.now() % 18_000) * 86_400_000).toISOString().slice(0, 10)
let rmLama = ''
let rmTetap = ''

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  artisan()
})
test.afterAll(() => {
  artisan('--batal')
})

/** Mengembalikan No. RM baru; `rmSebelumnya` = pasien pertama (toast-nya mungkin masih tampil, jadi dikecualikan). */
async function daftarkan(page: Page, rmSebelumnya?: string): Promise<string> {
  await page.getByRole('button', { name: 'Pasien baru' }).click()
  const dialog = page.getByRole('dialog', { name: 'Daftarkan pasien baru' })
  await dialog.getByLabel('Nama lengkap').fill(NAMA)
  await dialog.getByRole('textbox', { name: 'Tanggal lahir' }).fill(LAHIR)
  await dialog.getByRole('combobox', { name: 'Jenis kelamin' }).selectOption('laki_laki')
  await dialog.getByRole('button', { name: 'Daftarkan pasien' }).click()
  if (rmSebelumnya) {
    const peringatan = dialog.getByRole('alert').filter({ hasText: 'Mungkin pasien ini sudah terdaftar.' })
    await expect(peringatan).toContainText(NAMA)
    expect(await pelanggaranAxe(page)).toEqual([])
    await dialog.getByRole('button', { name: 'Tetap daftarkan sebagai pasien baru' }).click()
  }
  const toast = page.getByText(new RegExp(`^Pasien terdaftar dengan No\\. RM (?!${rmSebelumnya ?? '-'}\\.)\\d+\\.$`))
  await expect(toast).toBeVisible()
  return /(\d+)\.$/.exec((await toast.textContent()) ?? '')?.[1] ?? ''
}

test('nama dan tanggal lahir sama memunculkan peringatan; pendaftaran mengusulkan gabung RM', async ({ browser, baseURL }) => {
  const page = await halamanAkun(browser, new URL(baseURL ?? '').origin, 'contoh.admisi')
  await page.goto('/pendaftaran/pasien')
  rmTetap = await daftarkan(page)
  rmLama = await daftarkan(page, rmTetap)

  await page.goto('/pendaftaran/master-pasien')
  await page.getByRole('button', { name: 'Usulkan gabung' }).click()
  const usul = page.getByRole('dialog', { name: 'Usulkan gabung RM ganda' })
  await usul.getByLabel('No. RM yang digabung (RM lama)').fill(rmLama)
  await usul.getByRole('button', { name: 'Periksa' }).first().click()
  await usul.getByLabel('No. RM yang dipertahankan').fill(rmTetap)
  await usul.getByRole('button', { name: 'Periksa' }).last().click()
  await expect(usul.getByRole('status').filter({ hasText: NAMA })).toHaveCount(2)
  await usul.getByLabel('Alasan').fill('Pasien sama terdaftar dua kali (uji e2e)')
  await usul.getByRole('button', { name: 'Usulkan gabung' }).click()
  await expect(page.getByText('Usulan gabung terkirim ke Rekam Medis.')).toBeVisible()

  const baris = page.getByRole('row').filter({ hasText: NAMA })
  await expect(baris).toContainText('contoh.admisi')
  // Pengusul tidak bisa memverifikasi usulannya sendiri.
  await expect(baris.getByRole('button', { name: /^Verifikasi gabung/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})

test('Rekam Medis (bukan pengusul) menyetujui gabung', async ({ browser, baseURL }) => {
  const page = await halamanAkun(browser, new URL(baseURL ?? '').origin, 'contoh.rekam.medis')
  await page.goto('/pendaftaran/master-pasien')
  const baris = page.getByRole('row').filter({ hasText: NAMA })
  await baris.getByRole('button', { name: `Verifikasi gabung ${rmLama}` }).click()
  const dialog = page.getByRole('dialog', { name: /^Verifikasi gabung/ })
  await dialog.getByRole('radio', { name: 'Setujui gabung' }).check()
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Setujui gabung' }).click()
  await expect(page.getByText('RM digabung. Kunjungan dipindah ke RM tujuan.')).toBeVisible()

  await page.getByRole('combobox', { name: 'Status' }).selectOption('digabung')
  await expect(baris).toContainText('Sudah digabung')
  await page.context().close()
})
