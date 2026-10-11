import { expect, test as dasar, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Pengaturan → Peran & izin, Akun & akses (tiket 1.2c) ke backend asli dengan akun sintetis AkunSintetisSeeder.
 * Proyek Playwright sendiri yang berjalan sesudah proyek lain (playwright.config.ts): uji ini mengubah akses
 * contoh.admisi dan mereset MFA contoh.mfa, yang dipakai spec lain. Jalankan ulang seeder sebelum run berikutnya.
 */
const RAHASIA_SUPERADMIN = process.env.E2E_SUPERADMIN_MFA_RAHASIA ?? ''
// Kode peran unik per run agar run lokal tanpa seeding ulang tidak bentrok kode_sudah_dipakai.
const KODE_DASAR = `e2e_farmasi_${Date.now().toString(36)}`
const NAMA_DASAR = `E2E Petugas Stok ${Date.now().toString(36)}`

let sesiSuperadmin: Awaited<ReturnType<BrowserContext['storageState']>> | undefined
const test = dasar.extend({ storageState: async ({}, pakai) => pakai(sesiSuperadmin) })
test.describe.configure({ mode: 'serial' })

/** Storage kosong: browser.newContext() di dalam uji mewarisi storageState fixture (sesi superadmin), dan masuk
 * sebagai akun lain di atas cookie itu me-regenerasi lalu menghapus sesi superadmin. */
const TANPA_SESI = { cookies: [], origins: [] }

// Sesi akun lain dipakai ulang antar-uji: rate limit masuk per nama pengguna (10/menit) cepat habis bila tiap uji masuk.
const sesiAkun = new Map<string, Awaited<ReturnType<BrowserContext['storageState']>>>()

async function halamanAkun(browser: Browser, asal: string, nama: string): Promise<{ page: Page; tutup: () => Promise<void> }> {
  let sesi = sesiAkun.get(nama)
  if (!sesi) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    await masukLewatApi(masuk.request, asal, nama)
    sesi = await masuk.storageState()
    sesiAkun.set(nama, sesi)
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi })
  const page = await konteks.newPage()
  return { page, tutup: () => konteks.close() }
}

const menuUtama = (page: Page) => page.getByRole('navigation', { name: 'Menu utama' })

/** Ke Akun & akses lewat navigasi sub-menu bila sudah di Pengaturan (tanpa muat ulang: hemat rate limit API). */
async function pilihAkun(page: Page, namaPengguna: string, nama: string) {
  const subMenu = page.getByRole('navigation', { name: 'Sub-menu Pengaturan Sistem' }).getByRole('link', { name: 'Akun & akses' })
  if (await subMenu.isVisible()) await subMenu.click()
  else await page.goto('/pengaturan/akun')
  await page.getByRole('searchbox', { name: 'Cari akun' }).fill(namaPengguna)
  await page.getByRole('button', { name: `${nama} ${namaPengguna}`, exact: true }).click()
  await expect(page.getByRole('heading', { level: 2, name: nama })).toBeVisible()
}

async function bukaTab(page: Page, nama: string) {
  await page.getByRole('tab', { name: nama }).click()
}

// Rate limit API 120/menit per akun (config/rate_limit.php) tetap berlaku di e2e; uji jauh lebih cepat dari manusia,
// jadi permintaan superadmin dihitung dan uji berikutnya menunggu bila satu menit terakhir sudah mendekati batas.
const BATAS_PER_MENIT = 80
const waktuPermintaan: number[] = []

test.beforeEach(async ({ page }) => {
  const sekarang = Date.now()
  while (waktuPermintaan.length && sekarang - waktuPermintaan[0]! > 61_000) waktuPermintaan.shift()
  if (waktuPermintaan.length > BATAS_PER_MENIT) {
    const jeda = 61_000 - (sekarang - waktuPermintaan[0]!)
    test.info().setTimeout(test.info().timeout + jeda)
    await page.waitForTimeout(jeda)
  }
  page.on('request', (r) => {
    if (r.url().includes('/api/v1/')) waktuPermintaan.push(Date.now())
  })
})

test.beforeAll(async ({ playwright }, info) => {
  if (!SANDI || !RAHASIA_SUPERADMIN) throw new Error('Set E2E_SANDI dan E2E_SUPERADMIN_MFA_RAHASIA sesuai AkunSintetisSeeder.')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const permintaan = await playwright.request.newContext({ baseURL: asal })
  // Satu kali masuk per berkas: kode TOTP tidak bisa dipakai dua kali dalam periodenya.
  await masukLewatApi(permintaan, asal, 'contoh.superadmin', RAHASIA_SUPERADMIN)
  sesiSuperadmin = await permintaan.storageState()
  await permintaan.dispose()
})

test('1440 px: halaman induk Pengaturan berisi kartu sub-menu, juga dari kartu Beranda; contoh.dokter tidak melihat Pengaturan', async ({ page, browser }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/pengaturan')
  await expect(page.getByRole('heading', { level: 1, name: 'Pengaturan Sistem' })).toBeVisible()
  const kartu = page.getByRole('navigation', { name: 'Sub-menu Pengaturan Sistem' })
  await expect(kartu.getByRole('link', { name: 'Peran & izin' })).toBeVisible()

  // Beranda (1.2e): kartu modul aktif membuka halamannya (halaman induk di atas).
  await page.goto('/')
  await page.getByRole('region', { name: 'Modul saya' }).getByRole('link', { name: 'Pengaturan Sistem', exact: true }).click()
  await expect(page).toHaveURL(/\/pengaturan$/)
  await expect(kartu.getByRole('link', { name: 'Peran & izin' })).toBeVisible()
  await expect(kartu.getByRole('link', { name: 'Akun & akses' })).toBeVisible()

  const dokter = await halamanAkun(browser, new URL(info.project.use.baseURL ?? '').origin, 'contoh.dokter')
  await dokter.page.goto('/pengaturan/peran')
  await expect(dokter.page.getByRole('heading', { level: 1, name: 'Halaman tidak ditemukan' })).toBeVisible()
  await expect(menuUtama(dokter.page).getByRole('link', { name: /^Pengaturan/ })).toHaveCount(0)
  await dokter.tutup()
})

// Alur utama di tiga kelas layar (uji penerimaan 1.2c-fe); tiap lebar membuat perannya sendiri.
for (const [lebar, tinggi] of [
  [1440, 900],
  [1024, 768],
  [768, 1024],
] as const) {
  test(`${lebar} px: buat peran, beri sub-menu dengan dasar, tugaskan → contoh.admisi melihat menu baru`, async ({ page, browser }, info) => {
    const KODE_PERAN = `${KODE_DASAR}_${lebar}`
    const NAMA_PERAN = `${NAMA_DASAR} ${lebar}`
    await page.setViewportSize({ width: lebar, height: tinggi })
    await page.goto('/pengaturan/peran')

    await page.getByRole('button', { name: 'Buat peran' }).click()
    const buat = page.getByRole('dialog', { name: 'Buat peran' })
    await buat.getByLabel('Kode peran').fill(KODE_PERAN)
    await buat.getByLabel('Nama peran').fill(NAMA_PERAN)
    await buat.getByLabel('Dasar perubahan').fill('SK Direktur E2E/1')
    await buat.getByRole('button', { name: 'Buat peran' }).click()
    await expect(page.getByRole('heading', { level: 2, name: NAMA_PERAN })).toBeVisible()

    await page.getByRole('searchbox', { name: 'Cari menu di matriks' }).fill('Stok depo')
    await page.getByRole('combobox', { name: 'Tingkat Farmasi → Stok depo' }).selectOption('lihat')
    await expect(page.getByText('1 ditambah, 0 dicabut')).toBeVisible()

    await page.getByRole('button', { name: 'Simpan perubahan' }).click()
    const simpan = page.getByRole('alertdialog', { name: `Simpan perubahan izin ${NAMA_PERAN}?` })
    await expect(simpan).toContainText('Farmasi → Stok depo → Lihat')
    // Tanpa dasar tidak tersimpan (MD-2).
    await simpan.getByRole('button', { name: 'Simpan perubahan' }).click()
    await expect(simpan.getByText('Isi dasar perubahan')).toBeVisible()
    await simpan.getByLabel('Dasar perubahan').fill('SK Direktur E2E/2')
    await simpan.getByRole('button', { name: 'Simpan perubahan' }).click()
    await expect(page.getByRole('status').filter({ hasText: `Izin ${NAMA_PERAN} tersimpan: 1 ditambah, 0 dicabut` })).toBeVisible()

    await pilihAkun(page, 'contoh.admisi', 'Contoh Petugas Admisi')
    await page.getByRole('button', { name: 'Tugaskan peran' }).click()
    const tugas = page.getByRole('dialog', { name: 'Tugaskan peran ke Contoh Petugas Admisi' })
    await tugas.getByLabel('Peran').selectOption({ label: NAMA_PERAN })
    await tugas.getByLabel('Dasar pemberian').fill('Surat tugas E2E/3')
    await tugas.getByRole('button', { name: 'Tugaskan' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Peran ditugaskan.' })).toBeVisible()
    await expect(page.getByRole('listitem').filter({ hasText: NAMA_PERAN })).toBeVisible()

    const admisi = await halamanAkun(browser, new URL(info.project.use.baseURL ?? '').origin, 'contoh.admisi')
    await admisi.page.goto('/')
    await expect(menuUtama(admisi.page).getByRole('link', { name: /^Farmasi/ })).toBeVisible()
    await admisi.tutup()
  })

  test(`${lebar} px: cabut per akun (cabut menang), Akhiri dengan alasan mengembalikan akses (MD-1)`, async ({ page, browser }, info) => {
    const asal = new URL(info.project.use.baseURL ?? '').origin
    await page.setViewportSize({ width: lebar, height: tinggi })
    await pilihAkun(page, 'contoh.admisi', 'Contoh Petugas Admisi')
    await bukaTab(page, 'Per akun')

    await page.getByRole('button', { name: 'Tambah pengecualian' }).click()
    const tambah = page.getByRole('dialog', { name: 'Pengecualian untuk Contoh Petugas Admisi' })
    await tambah.getByRole('searchbox', { name: 'Cari sasaran' }).fill('Stok depo')
    await tambah.getByRole('radio', { name: 'Farmasi → Stok depo (seluruh halaman)' }).check()
    await tambah.getByRole('textbox', { name: 'Dasar', exact: true }).fill('Memo Kepala Instalasi E2E/4')
    await tambah.getByRole('textbox', { name: 'Alasan', exact: true }).fill('Uji pencabutan per akun')
    await tambah.getByRole('button', { name: 'Simpan pengecualian' }).click()
    await expect(page.getByRole('listitem').filter({ hasText: 'Dicabut' }).filter({ hasText: 'Farmasi → Stok depo' })).toBeVisible()

    const admisi = await halamanAkun(browser, asal, 'contoh.admisi')
    await admisi.page.goto('/')
    await expect(menuUtama(admisi.page).getByRole('link', { name: 'Beranda' })).toBeVisible()
    await expect(menuUtama(admisi.page).getByRole('link', { name: /^Farmasi/ })).toHaveCount(0)

    await page.getByRole('listitem').filter({ hasText: 'Farmasi → Stok depo' }).getByRole('button', { name: 'Akhiri' }).click()
    const akhiri = page.getByRole('alertdialog', { name: /Akhiri pengecualian/ })
    await expect(akhiri).toContainText('kembali sesuai perannya')
    await akhiri.getByRole('button', { name: 'Akhiri' }).click()
    await expect(akhiri.getByText('Tulis alasan')).toBeVisible()
    await akhiri.getByLabel('Alasan mengakhiri').fill('Uji selesai, akses kembali')
    await akhiri.getByRole('button', { name: 'Akhiri' }).click()
    await expect(page.getByText(/Riwayat pengecualian \(\d+\)/)).toBeVisible()

    await admisi.page.reload()
    await expect(menuUtama(admisi.page).getByRole('link', { name: /^Farmasi/ })).toBeVisible()
    await admisi.tutup()
  })
}

test('Lihat sebagai menampilkan menu akun lain tanpa tautan; akun sendiri tanpa tombol ubah', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await pilihAkun(page, 'contoh.admisi', 'Contoh Petugas Admisi')
  await bukaTab(page, 'Lihat sebagai')
  const pratinjau = page.getByRole('navigation', { name: 'Pratinjau menu Contoh Petugas Admisi' })
  await expect(pratinjau.getByText('Farmasi')).toBeVisible()
  await expect(pratinjau.getByRole('link')).toHaveCount(0)

  await pilihAkun(page, 'contoh.superadmin', 'Contoh Superadmin')
  await expect(page.getByText('Perubahan akun sendiri dilakukan superadmin lain.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Tugaskan peran' })).toHaveCount(0)
  await bukaTab(page, 'MFA')
  await expect(page.getByRole('button', { name: 'Reset MFA' })).toHaveCount(0)
})

test('reset MFA contoh.mfa → contoh.mfa diminta mendaftar ulang saat masuk', async ({ page, browser }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await pilihAkun(page, 'contoh.mfa', 'Contoh Akun MFA')
  await bukaTab(page, 'MFA')
  await expect(page.getByText('Dari luar jaringan RS, MFA selalu wajib.')).toBeVisible()
  await page.getByRole('button', { name: 'Reset MFA' }).click()
  const reset = page.getByRole('alertdialog', { name: 'Reset MFA Contoh Akun MFA?' })
  await expect(reset).toContainText('semua sesinya diputus')
  await reset.getByRole('textbox', { name: 'Alasan', exact: true }).fill('Ponsel autentikator hilang (uji e2e)')
  await reset.getByRole('textbox', { name: 'Dasar', exact: true }).fill('Tiket layanan E2E/5')
  await reset.getByRole('button', { name: 'Reset MFA' }).click()
  await expect(page.getByRole('definition').getByText('Belum terdaftar')).toBeVisible()

  const konteks = await browser.newContext({ baseURL: new URL(info.project.use.baseURL ?? '').origin, storageState: TANPA_SESI })
  const mfa = await konteks.newPage()
  await mfa.goto('/masuk')
  await mfa.getByLabel('Nama pengguna').fill('contoh.mfa')
  const sandi = mfa.getByLabel('Kata sandi', { exact: true })
  await sandi.fill(SANDI)
  await sandi.press('Enter')
  await expect(mfa.getByRole('heading', { name: 'Aktifkan verifikasi dua langkah' })).toBeVisible()
  await konteks.close()
})

for (const [lebar, tinggi] of [
  [1024, 768],
  [768, 1024],
] as const) {
  test(`${lebar} px: Peran & izin dan Akun & akses tanpa gulir samping halaman; navigasi sub-menu di halaman`, async ({ page }) => {
    await page.setViewportSize({ width: lebar, height: tinggi })
    await page.goto('/pengaturan/peran')
    await page.getByRole('button', { name: /Contoh Dokter/ }).click()
    await expect(page.getByRole('region', { name: 'Matriks izin peran' })).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Sub-menu Pengaturan Sistem' })).toBeVisible()
    await tanpaGulirSamping(page)

    await pilihAkun(page, 'contoh.dokter', 'Contoh Dokter')
    await tanpaGulirSamping(page)
  })
}

test('390 px: halaman pengaturan menampilkan pesan gunakan tablet/desktop', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/pengaturan/akun')
  await expect(page.getByText('Halaman ini dibuka dari tablet atau komputer.', { exact: false })).toBeVisible()
  await expect(page.getByRole('table')).toHaveCount(0)
  await tanpaGulirSamping(page)
})
