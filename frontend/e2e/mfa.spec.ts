import { expect, test, type Page } from '@playwright/test'
import { kodeTotp } from './totp'

// Akun dan rahasia dari AkunSintetisSeeder (sintetis). contoh.mfa.baru hanya bisa didaftarkan sekali
// per seeding; jalankan ulang seeder sebelum run berikutnya.
const SANDI = process.env.E2E_SANDI ?? ''
const RAHASIA_MFA = process.env.E2E_MFA_RAHASIA ?? ''

test.beforeAll(() => {
  if (!SANDI || !RAHASIA_MFA) {
    throw new Error('Set E2E_SANDI dan E2E_MFA_RAHASIA sama dengan AKUN_SINTETIS_SANDI dan AKUN_SINTETIS_MFA_RAHASIA.')
  }
})

async function masukSandi(page: Page, nama: string) {
  await page.goto('/masuk')
  await page.getByLabel('Nama pengguna').fill(nama)
  const sandi = page.getByLabel('Kata sandi', { exact: true })
  await sandi.fill(SANDI)
  await sandi.press('Enter')
}

async function tanpaJejakDiStorage(page: Page) {
  expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0])
}

// Kode TOTP tidak bisa dipakai dua kali dalam periodenya, jadi alur yang memakai kode hanya di satu proyek.
test('akun wajib MFA: kode salah lalu kode TOTP benar → masuk', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'Kode TOTP sekali pakai; cukup sekali per run.')

  await masukSandi(page, 'contoh.mfa')
  await expect(page.getByRole('heading', { name: 'Masukkan kode 6 digit' })).toBeVisible()
  const kolom = page.getByLabel('Kode verifikasi')
  await expect(kolom).toBeFocused()

  // Sebelum kode benar, sesi belum masuk: rute terlindung tetap ke /masuk.
  const saya = await page.request.get('/api/v1/saya', { headers: { Accept: 'application/json', Referer: page.url() } })
  expect(saya.status()).toBe(401)

  await page.keyboard.type('000000')
  await page.keyboard.press('Enter')
  await expect(page.getByRole('alert')).toContainText('Kode salah.')
  await expect(kolom).toHaveValue('')

  await page.keyboard.type(kodeTotp(RAHASIA_MFA))
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByText('Contoh Akun MFA', { exact: true })).toBeVisible()
  await tanpaJejakDiStorage(page)
})

test('MFA pertama kali: pindai/ketik kunci → aktifkan → 10 kode cadangan → masuk', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'Pendaftaran hanya sekali per seeding.')

  await masukSandi(page, 'contoh.mfa.baru')
  await expect(page.getByRole('heading', { name: 'Aktifkan verifikasi dua langkah' })).toBeVisible()
  await expect(page.getByRole('img', { name: 'Kode QR untuk aplikasi autentikator' })).toBeVisible()

  await page.getByText('Tidak bisa memindai?').click()
  const kunci = (await page.getByTestId('kunci-manual').textContent()) ?? ''
  await page.getByLabel('Kode verifikasi').fill(kodeTotp(kunci.replace(/\s/g, '')))
  await page.getByRole('button', { name: 'Aktifkan' }).click()

  await expect(page.getByRole('heading', { name: 'Simpan kode cadangan' })).toBeFocused()
  await expect(page.getByTestId('kode-cadangan').getByRole('listitem')).toHaveCount(10)
  const lanjut = page.getByRole('button', { name: 'Lanjut ke aplikasi' })
  await expect(lanjut).toBeDisabled()
  await page.getByLabel('Saya sudah menyimpan kode cadangan ini').check()
  await lanjut.click()

  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByText('Contoh Akun MFA Baru', { exact: true })).toBeVisible()
  await tanpaJejakDiStorage(page)
})

test('langkah kode di layar sempit: tanpa scroll samping, kembali ke langkah sandi', async ({ page }) => {
  await masukSandi(page, 'contoh.mfa')
  await expect(page.getByRole('heading', { name: 'Masukkan kode 6 digit' })).toBeVisible()

  const lebar = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth])
  expect(lebar[0]).toBeLessThanOrEqual(lebar[1])

  await page.getByRole('button', { name: 'Kembali' }).click()
  await expect(page.getByRole('heading', { name: 'Masuk ke akun Anda' })).toBeVisible()
})
