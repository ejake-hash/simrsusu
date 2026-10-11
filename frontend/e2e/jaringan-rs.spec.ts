import { expect, test, type Page } from '@playwright/test'

// Pendaftaran MFA hanya dari jaringan RS (PRD 10.3.2, tiket 1.1c). Akun sintetis contoh.admisi
// belum punya MFA. "Dari luar" ditiru dengan X-Forwarded-For: backend harus memercayai proksi Vite
// lewat PROKSI_TEPERCAYA=127.0.0.1 (CI dan README frontend).
const SANDI = process.env.E2E_SANDI ?? ''
const IP_LUAR = '203.0.113.25'

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

async function masukSandi(page: Page, nama: string) {
  await page.goto('/masuk')
  await page.getByLabel('Nama pengguna').fill(nama)
  const sandi = page.getByLabel('Kata sandi', { exact: true })
  await sandi.fill(SANDI)
  await sandi.press('Enter')
}

test('dari luar jaringan RS, akun tanpa MFA ditolak dengan arahan yang jelas', async ({ page }) => {
  await page.setExtraHTTPHeaders({ 'X-Forwarded-For': IP_LUAR })

  await masukSandi(page, 'contoh.admisi')

  await expect(page.getByRole('alert')).toContainText(
    'Verifikasi dua langkah akun ini belum aktif, dan hanya bisa diaktifkan dari jaringan rumah sakit.',
  )
  await expect(page).toHaveURL(/\/masuk$/)
  // Tidak ada langkah pendaftaran (QR) yang ditawarkan dari luar.
  await expect(page.getByRole('heading', { name: 'Aktifkan verifikasi dua langkah' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Masuk' })).toBeEnabled()

  const lebar = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth])
  expect(lebar[0]).toBeLessThanOrEqual(lebar[1])
})

// Instalasi tanpa AUTENTIKASI_MFA_JARINGAN_RS tidak bisa disiapkan di backend e2e yang sama, jadi
// respons 503 ditiru; perilaku backend-nya diuji PHPUnit (MfaTest).
test('instalasi belum disiapkan: pesan konfigurasi, bukan "sandi salah"', async ({ page }) => {
  await page.route('**/api/v1/autentikasi/masuk', (rute) =>
    rute.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ galat: { kode: 'konfigurasi_belum_lengkap', pesan: 'SIMRS belum selesai disiapkan.', rincian: [] } }),
    }),
  )

  await masukSandi(page, 'contoh.admisi')

  await expect(page.getByRole('alert')).toContainText('SIMRS belum selesai disiapkan sehingga belum bisa dipakai untuk masuk.')
  await expect(page.getByRole('alert')).not.toContainText('sandi salah')
})
