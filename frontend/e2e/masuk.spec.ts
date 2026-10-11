import { expect, test, type Page } from '@playwright/test'

// Akun dari AkunSintetisSeeder (data sintetis, bukan data pasien atau pegawai asli).
const SANDI = process.env.E2E_SANDI ?? ''
const AKUN = {
  sukses: { nama: 'contoh.admisi', pegawai: 'Contoh Petugas Admisi' },
  salah: { nama: 'contoh.perawat', pegawai: 'Contoh Perawat' },
  // Akun khusus uji kunci: terkunci 15 menit setelah uji; seed ulang sebelum run berikutnya.
  terkunci: { nama: 'contoh.terkunci' },
}

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

/** Judul beranda menyebut nama pegawai; header menyembunyikan nama di layar sempit. */
// Salam Beranda mengikuti jam perangkat (pagi/siang/sore/malam).
const sapaan = (page: Page, pegawai: string) => page.getByRole('heading', { level: 1, name: new RegExp(`^Selamat (pagi|siang|sore|malam), ${pegawai}$`) })

async function isiLewatKeyboard(page: Page, nama: string, sandi: string) {
  await expect(page.getByLabel('Nama pengguna')).toBeFocused()
  await page.keyboard.type(nama)
  await page.keyboard.press('Tab')
  await expect(page.getByLabel('Kata sandi', { exact: true })).toBeFocused()
  await page.keyboard.type(sandi)
  await page.keyboard.press('Enter')
}

test('rute terlindung → masuk lewat keyboard → kembali ke beranda tanpa jejak di storage', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/masuk$/)
  await expect(page).toHaveTitle('Masuk · Karsitara')

  await isiLewatKeyboard(page, AKUN.sukses.nama, SANDI)

  await expect(page).toHaveURL(/\/$/)
  await expect(sapaan(page, AKUN.sukses.pegawai)).toBeVisible()
  const storage = await page.evaluate(() => [localStorage.length, sessionStorage.length])
  expect(storage).toEqual([0, 0])

  // Muat ulang: sesi dari cookie server, bukan dari storage.
  await page.reload()
  await expect(sapaan(page, AKUN.sukses.pegawai)).toBeVisible()

  await page.getByRole('button', { name: 'Keluar' }).click()
  await expect(page).toHaveURL(/\/masuk$/)
  await page.goto('/')
  await expect(page).toHaveURL(/\/masuk$/)
})

test('sandi salah: pesan di dekat form, lalu sandi benar berhasil', async ({ page }) => {
  await page.goto('/masuk')
  await isiLewatKeyboard(page, AKUN.salah.nama, 'bukan-sandi-yang-benar')

  await expect(page.getByRole('alert')).toContainText('Nama pengguna atau sandi salah.')
  const sandi = page.getByLabel('Kata sandi', { exact: true })
  await expect(sandi).toHaveValue('')
  await expect(sandi).toBeFocused()

  // Berhasil masuk juga mengembalikan hitungan gagal akun ini ke nol.
  await page.keyboard.type(SANDI)
  await page.keyboard.press('Enter')
  await expect(sapaan(page, AKUN.salah.pegawai)).toBeVisible()
})

test('lima kali salah: akun terkunci dengan sisa waktu dari server', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'Mengunci akun; cukup sekali per run.')

  await page.goto('/masuk')
  await page.getByLabel('Nama pengguna').fill(AKUN.terkunci.nama)
  const sandi = page.getByLabel('Kata sandi', { exact: true })
  for (let i = 0; i < 5; i++) {
    await sandi.fill(`sandi-salah-ke-${i}`)
    await sandi.press('Enter')
    await expect(page.getByRole('alert')).toContainText('Nama pengguna atau sandi salah.')
  }

  await sandi.fill(SANDI)
  await sandi.press('Enter')
  await expect(page.getByRole('alert')).toContainText('Akun terkunci sementara')
  await expect(page.getByRole('alert')).toContainText(/Coba lagi dalam 1[45] menit\./)
})

test('?kembali ke luar aplikasi diabaikan', async ({ page }) => {
  await page.goto('/masuk?kembali=//contoh.invalid/')
  await isiLewatKeyboard(page, AKUN.sukses.nama, SANDI)
  await expect(page).toHaveURL(/^http:\/\/localhost:\d+\/$/)
})

// Caps Lock diuji di tes unit: Chromium headless tidak mengubah status modifier CapsLock.
test('tampilan: tanpa scroll samping, tampilkan sandi', async ({ page }) => {
  await page.goto('/masuk')
  const lebar = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth])
  expect(lebar[0]).toBeLessThanOrEqual(lebar[1])

  const sandi = page.getByLabel('Kata sandi', { exact: true })
  await sandi.fill('rahasia')
  await page.getByRole('button', { name: 'Tampilkan kata sandi' }).click()
  await expect(sandi).toHaveAttribute('type', 'text')
})
