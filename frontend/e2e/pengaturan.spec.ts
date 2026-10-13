import AxeBuilder from '@axe-core/playwright'
import { expect, test as dasar, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Pengaturan → Peran & izin, Akun & akses (tiket 1.2c) ke backend asli dengan akun sintetis AkunSintetisSeeder.
 * Proyek Playwright sendiri yang berjalan sesudah proyek lain (playwright.config.ts): uji ini mengubah akses
 * contoh.admisi dan mereset MFA contoh.mfa, yang dipakai spec lain. Jalankan ulang seeder sebelum run berikutnya.
 */
const RAHASIA_SUPERADMIN = process.env.E2E_SUPERADMIN_MFA_RAHASIA ?? ''
/** Superadmin kedua (tiket 1.2d): penyetuju pengajuan superadmin pertama (kontrol ganda K4/K13). */
const RAHASIA_SUPERADMIN_DUA = process.env.E2E_SUPERADMIN_DUA_MFA_RAHASIA ?? ''
/** contoh.sdm (peran Contoh Admin SDM) berperan sebagai pejabat PDP penyetuju label di uji 1.5. */
const RAHASIA_SDM = process.env.E2E_SDM_MFA_RAHASIA ?? ''
// Kode peran unik per run agar run lokal tanpa seeding ulang tidak bentrok kode_sudah_dipakai.
const KODE_DASAR = `e2e_farmasi_${Date.now().toString(36)}`
const NAMA_DASAR = `E2E Petugas Stok ${Date.now().toString(36)}`

let sesiSuperadmin: Awaited<ReturnType<BrowserContext['storageState']>> | undefined
const test = dasar.extend({ storageState: async ({}, pakai) => pakai(sesiSuperadmin) })
test.describe.configure({ mode: 'serial' })

/** Storage kosong: browser.newContext() di dalam uji mewarisi storageState fixture (sesi superadmin), dan masuk
 * sebagai akun lain di atas cookie itu me-regenerasi lalu menghapus sesi superadmin. */
const TANPA_SESI = { cookies: [], origins: [] }
/** PIN TTE sintetis contoh.superadmin.dua (TtdSintetisSeeder, AKUN_SINTETIS_TTD_PIN = E2E_TTD_PIN). */
const PIN_TTD = process.env.E2E_TTD_PIN ?? ''

// Sesi akun lain dipakai ulang antar-uji: rate limit masuk per nama pengguna (10/menit) cepat habis bila tiap uji masuk.
const sesiAkun = new Map<string, Awaited<ReturnType<BrowserContext['storageState']>>>()

async function halamanAkun(browser: Browser, asal: string, nama: string, rahasiaTotp?: string): Promise<{ page: Page; tutup: () => Promise<void> }> {
  let sesi = sesiAkun.get(nama)
  if (!sesi) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    await masukLewatApi(masuk.request, asal, nama, rahasiaTotp)
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
  // Halaman yang ada tetapi tidak diberikan: Akses ditolak dengan Minta akses (1.2d); alurnya diuji di bawah.
  await expect(dokter.page.getByRole('heading', { level: 1, name: 'Akses ditolak' })).toBeVisible()
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

// ── Tiket 1.8a: profil RS dan cakupan unit (di berkas ini agar memakai satu sesi superadmin dan jeda rate limit yang sama) ──

/** PNG 1×1 sintetis; tipe dan dimensi diperiksa server dari isi berkas. */
const PNG_SINTETIS = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64')
const NAMA_RS = 'RS Pendidikan Contoh (e2e)'

test('1440 px: profil RS — kontras diuji sebelum simpan; nama dan logo baru tampil di halaman masuk tanpa build ulang', async ({ page, browser }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/pengaturan/profil-rs')
  await expect(page.getByRole('heading', { level: 1, name: 'Profil rumah sakit' })).toBeVisible()
  const form = page.getByRole('form', { name: 'Identitas' })
  await form.getByLabel('Nama rumah sakit').fill(NAMA_RS)
  await form.getByLabel('Unit pengelola akun').fill('Instalasi SIMRS (contoh)')
  await form.getByRole('textbox', { name: 'Warna utama', exact: true }).fill('#FFEE00')
  await expect(form.getByText(/Kontras teks putih di atas warna ini 1,\d+:1 · minimal 4,5:1/)).toBeVisible()
  await form.getByLabel('Dasar perubahan').fill('SK Direktur E2E/profil')
  await form.getByRole('button', { name: 'Simpan profil' }).click()
  await expect(form.getByText('Kontras warna belum cukup')).toBeVisible()
  await form.getByRole('textbox', { name: 'Warna utama', exact: true }).fill('#006937')
  await expect(page.getByRole('img', { name: 'Pratinjau identitas' })).toBeVisible()
  await form.getByRole('button', { name: 'Simpan profil' }).click()
  await expect(page.getByText('Profil rumah sakit disimpan.')).toBeVisible()
  await expect(page.getByText('Instalasi ini belum disiapkan')).toHaveCount(0)

  await page.locator('input[data-berkas="logo"]').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: PNG_SINTETIS })
  await expect(page.getByRole('img', { name: 'Logo saat ini' })).toHaveAttribute('src', /\/api\/v1\/profil-rs\/berkas\/logo\?v=/)

  // Halaman masuk (tanpa sesi) memakai profil dari GET /profil-rs.
  const tamu = await browser.newContext({ baseURL: new URL(info.project.use.baseURL ?? '').origin, storageState: TANPA_SESI })
  const masuk = await tamu.newPage()
  await masuk.goto('/masuk')
  await expect(masuk.locator('.kartu-rs')).toContainText(NAMA_RS)
  const logo = masuk.locator('.kartu-rs img')
  await expect(logo).toHaveAttribute('src', /\/api\/v1\/profil-rs\/berkas\/logo\?v=/)
  await expect.poll(() => logo.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth)).toBe(1)
  await tamu.close()
})

test('1440 px: penugasan ber-cakupan unit tampil dengan label unit; pemegangnya melihat pemilih unit kerja di header', async ({ page, browser }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await pilihAkun(page, 'contoh.perawat', 'Contoh Perawat')
  for (const [peran, kode] of [
    ['Contoh Petugas Admisi', 'RUANG-MELATI'],
    ['Contoh Dokter', 'RUANG-MAWAR'],
  ] as const) {
    await page.getByRole('button', { name: 'Tugaskan peran' }).click()
    const tugas = page.getByRole('dialog', { name: 'Tugaskan peran ke Contoh Perawat' })
    await tugas.getByLabel('Peran').selectOption({ label: peran })
    await tugas.getByRole('radio', { name: 'Unit kerja' }).check()
    await tugas.getByRole('button', { name: 'Tugaskan' }).click()
    await expect(tugas.getByText('Pilih unit kerja.')).toBeVisible()
    const unit = tugas.getByRole('combobox', { name: 'Unit kerja' })
    await unit.fill(kode)
    await tugas.getByRole('option', { name: new RegExp(kode) }).click()
    await expect(unit).toHaveValue(new RegExp(`^${kode} · `))
    await tugas.getByLabel('Dasar pemberian').fill('Surat tugas E2E/unit')
    await tugas.getByRole('button', { name: 'Tugaskan' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Peran ditugaskan.' })).toBeVisible()
    await expect(page.getByRole('listitem').filter({ hasText: peran }).filter({ hasText: `Unit kerja · ${kode} · ` })).toBeVisible()
  }

  const perawat = await halamanAkun(browser, new URL(info.project.use.baseURL ?? '').origin, 'contoh.perawat')
  await perawat.page.goto('/')
  const pemilih = perawat.page.getByRole('combobox', { name: 'Unit kerja' })
  await expect(pemilih.getByRole('option')).toHaveText(['Ruang Mawar (contoh)', 'Ruang Melati (contoh)'])
  await pemilih.selectOption({ label: 'Ruang Melati (contoh)' })
  await expect(pemilih).toHaveValue(/.+/)
  await perawat.tutup()

  // Bersihkan: akhiri kedua penugasan agar run berikutnya mulai dari keadaan seeder.
  for (const kode of ['RUANG-MELATI', 'RUANG-MAWAR']) {
    await page.getByRole('listitem').filter({ hasText: `Unit kerja · ${kode} · ` }).getByRole('button', { name: 'Akhiri' }).click()
    const akhiri = page.getByRole('alertdialog', { name: /Akhiri penugasan/ })
    await akhiri.getByLabel('Alasan mengakhiri').fill('Uji e2e cakupan unit selesai')
    await akhiri.getByRole('button', { name: 'Akhiri' }).click()
    await expect(akhiri).toHaveCount(0)
    await expect(page.getByRole('listitem').filter({ hasText: `Unit kerja · ${kode} · ` }).getByRole('button', { name: 'Akhiri' })).toHaveCount(0)
  }
})

// ── Tiket 1.2d: persetujuan dan permintaan akses ──
// Pengajuan oleh contoh.superadmin, keputusan oleh contoh.superadmin.dua (kontrol ganda), pengaju tidak bisa menyetujui
// sendiri (MD-4), tarik pengajuan, dan permintaan akses dari layar Akses ditolak.

test('1440 px: Akses ditolak → Minta akses (contoh.dokter) → superadmin melihat permintaannya lalu menolak dengan alasan', async ({ page, browser }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  const alasan = `Membantu audit peran ${Date.now().toString(36)}`
  const dokter = await halamanAkun(browser, new URL(info.project.use.baseURL ?? '').origin, 'contoh.dokter')
  await dokter.page.goto('/pengaturan/peran')
  await expect(dokter.page.getByRole('heading', { level: 1, name: 'Akses ditolak' })).toBeVisible()
  await dokter.page.getByRole('button', { name: 'Minta akses' }).click()
  await dokter.page.getByLabel('Untuk apa akses ini dibutuhkan?').fill(alasan)
  await dokter.page.getByRole('button', { name: 'Kirim permintaan' }).click()
  const terkirim = dokter.page.getByRole('status').filter({ hasText: /Permintaan PMA-\d{4}-\d+ terkirim/ })
  await expect(terkirim).toBeVisible()
  const nomor = (await terkirim.textContent())!.match(/PMA-\d{4}-\d+/)![0]
  // Muat ulang: permintaan yang masih menunggu ditampilkan, bukan formulir baru.
  await dokter.page.reload()
  await expect(dokter.page.getByText(`Permintaan Anda ${nomor}`)).toBeVisible()
  await expect(dokter.page.getByRole('button', { name: 'Minta akses' })).toHaveCount(0)
  await dokter.tutup()

  // 1.7: tugas PERMINTAAN_AKSES di kotak kerja superadmin (pemegang pengaturan.akun.perbaiki), riwayatnya, dan
  // notifikasinya di lonceng; tautannya membuka Persetujuan akses → Permintaan.
  const judulTugas = `Permintaan akses ${nomor} menunggu tindak lanjut`
  await page.goto('/')
  const kotak = page.getByRole('region', { name: 'Kotak kerja' })
  await expect(kotak.getByRole('tab', { name: /^Tugas saya \(\d+\)$/ })).toBeVisible()
  const tugas = kotak.getByRole('listitem').filter({ hasText: judulTugas })
  await expect(tugas).toContainText('Permintaan akses menunggu')
  await expect(tugas).toContainText(/Tenggat .* lagi/)
  await tugas.getByRole('button', { name: `Riwayat: ${judulTugas}` }).click()
  const riwayat = page.getByRole('dialog', { name: judulTugas })
  await expect(riwayat.getByText('Dibuat', { exact: true })).toBeVisible()
  await riwayat.getByRole('button', { name: 'Tutup' }).last().click()
  await page.getByRole('button', { name: /^Notifikasi, \d+ belum dibaca$/ }).click()
  await page.getByRole('region', { name: 'Notifikasi' }).getByRole('button', { name: new RegExp(judulTugas) }).click()
  await expect(page).toHaveURL(/\/pengaturan\/persetujuan\?tab=permintaan/)

  await page.goto('/pengaturan/persetujuan?tab=permintaan')
  const baris = page.getByRole('region', { name: 'Daftar permintaan akses' }).getByRole('row').filter({ hasText: nomor })
  await expect(baris).toContainText('Pengaturan Sistem → Peran & izin')
  await expect(baris).toContainText(alasan)
  await baris.getByRole('button', { name: `Tolak ${nomor}` }).click()
  const tolak = page.getByRole('alertdialog', { name: `Tolak permintaan ${nomor}?` })
  await tolak.getByLabel('Alasan penolakan').fill('Akses peran hanya untuk tim TI')
  await tolak.getByRole('button', { name: 'Tolak permintaan' }).click()
  await expect(page.getByText(`Permintaan ${nomor} ditolak.`)).toBeVisible()
  await expect(baris).toHaveCount(0)

  // Kejadian penyelesai (permintaan ditolak) menutup tugas: pindah ke Selesai bulan ini.
  await page.goto('/')
  await expect(page.getByRole('region', { name: 'Kotak kerja' }).getByRole('listitem').filter({ hasText: judulTugas })).toHaveCount(0)
  await page.getByRole('region', { name: 'Kotak kerja' }).getByRole('tab', { name: /^Selesai bulan ini/ }).click()
  await expect(page.getByRole('region', { name: 'Kotak kerja' }).getByRole('listitem').filter({ hasText: judulTugas })).toBeVisible()
})

test('1440 px: izin sensitif ke peran → menunggu persetujuan Superadmin; pengaju tidak melihat Setujui (MD-4) dan bisa menarik', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  const kode = `${KODE_DASAR}_sens`
  const nama = `${NAMA_DASAR} Sensitif`
  await page.goto('/pengaturan/peran')
  await page.getByRole('button', { name: 'Buat peran' }).click()
  const buat = page.getByRole('dialog', { name: 'Buat peran' })
  await buat.getByLabel('Kode peran').fill(kode)
  await buat.getByLabel('Nama peran').fill(nama)
  await buat.getByLabel('Dasar perubahan').fill('SK Direktur E2E/4')
  await buat.getByRole('button', { name: 'Buat peran' }).click()
  await expect(page.getByRole('heading', { level: 2, name: nama })).toBeVisible()

  await page.getByRole('searchbox', { name: 'Cari menu di matriks' }).fill('Akun & akses')
  await page.getByRole('combobox', { name: 'Tingkat Pengaturan Sistem → Akun & akses' }).selectOption('perbaiki')
  await page.getByRole('button', { name: 'Simpan perubahan' }).click()
  const simpan = page.getByRole('alertdialog', { name: `Simpan perubahan izin ${nama}?` })
  await expect(simpan).toContainText('2 izin sensitif tidak langsung berlaku: masing-masing menunggu persetujuan Superadmin')
  await simpan.getByLabel('Dasar perubahan').fill('SK Direktur E2E/5')
  await simpan.getByRole('button', { name: 'Simpan perubahan' }).click()
  await expect(page.getByRole('status').filter({ hasText: /2 izin sensitif menunggu persetujuan Superadmin \(PSA-\d{4}-\d+, PSA-\d{4}-\d+\)/ })).toBeVisible()
  // Izin menunggu tercentang dan terkunci; tingkat tidak bisa diturunkan selama menunggu.
  const perbaiki = page.getByRole('checkbox', { name: 'Perbaiki Pengaturan Sistem → Akun & akses (sensitif)' })
  await expect(perbaiki).toBeChecked()
  await expect(perbaiki).toBeDisabled()
  await expect(page.getByRole('combobox', { name: 'Tingkat Pengaturan Sistem → Akun & akses' })).toBeDisabled()
  await expect(page.getByRole('row', { name: /Akun & akses/ }).locator('.pil').filter({ hasText: 'Menunggu persetujuan Superadmin' })).toBeVisible()

  // Yang saya ajukan: tanpa tombol Setujui/Tolak; tarik keduanya.
  await page.goto('/pengaturan/persetujuan?tab=saya_ajukan')
  const daftar = page.getByRole('region', { name: 'Yang saya ajukan' })
  for (const aksi of ['Input', 'Perbaiki']) {
    await daftar.getByRole('button', { name: new RegExp(`Akun & akses → ${aksi} ke peran ${nama}`) }).click()
    const rincian = page.getByRole('region', { name: new RegExp(`Akun & akses → ${aksi} ke peran ${nama}`) })
    await expect(rincian).toContainText('Pengaju tidak bisa menyetujui pengajuannya sendiri.')
    await expect(rincian.getByRole('button', { name: 'Setujui' })).toHaveCount(0)
    await rincian.getByRole('button', { name: 'Tarik pengajuan' }).click()
    const tarik = page.getByRole('alertdialog', { name: /Tarik pengajuan PSA-/ })
    await tarik.getByLabel('Alasan menarik').fill('Uji e2e selesai')
    await tarik.getByRole('button', { name: 'Tarik pengajuan' }).click()
    await expect(rincian.getByText('Dibatalkan', { exact: true })).toBeVisible()
  }

  await page.goto('/pengaturan/peran')
  await page.getByRole('searchbox', { name: 'Cari peran' }).fill(kode)
  await page.getByRole('button', { name: new RegExp(nama) }).click()
  await page.getByRole('searchbox', { name: 'Cari menu di matriks' }).fill('Akun & akses')
  await expect(page.getByRole('checkbox', { name: 'Perbaiki Pengaturan Sistem → Akun & akses (sensitif)' })).not.toBeChecked()
})

test('1440 px: tugaskan peran Superadmin → 202 menunggu superadmin lain, tampil di rincian akun, ditarik pengaju', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await pilihAkun(page, 'contoh.data.induk', 'Contoh Admin Data Induk')
  await page.getByRole('button', { name: 'Tugaskan peran' }).click()
  const tugas = page.getByRole('dialog', { name: 'Tugaskan peran ke Contoh Admin Data Induk' })
  await tugas.getByLabel('Peran').selectOption({ label: 'Superadmin · wajib MFA · butuh persetujuan superadmin lain' })
  await tugas.getByLabel('Dasar pemberian').fill('SK Direktur E2E/6')
  await tugas.getByRole('button', { name: 'Tugaskan' }).click()
  await expect(page.getByRole('status').filter({ hasText: /Penugasan Superadmin menunggu persetujuan Superadmin \(PSA-\d{4}-\d+\); belum berlaku/ })).toBeVisible()
  const menunggu = page.getByRole('region', { name: /Menunggu persetujuan/ })
  await expect(menunggu).toContainText('Tugaskan peran Superadmin ke Contoh Admin Data Induk')
  // Belum berlaku: tidak ada di daftar penugasan berlaku.
  await expect(page.getByRole('listitem').filter({ hasText: 'Superadmin' }).getByRole('button', { name: 'Akhiri' })).toHaveCount(0)
  await menunggu.getByRole('button', { name: 'Tarik pengajuan' }).click()
  const tarik = page.getByRole('alertdialog', { name: /Tarik pengajuan PSA-/ })
  await tarik.getByLabel('Alasan menarik').fill('Uji e2e selesai')
  await tarik.getByRole('button', { name: 'Tarik pengajuan' }).click()
  await expect(menunggu).toHaveCount(0)
})

test('1440 px: kontrol ganda — superadmin A menambah izin sensitif, superadmin B menyetujui → berlaku di peran', async ({ page, browser }, info) => {
  test.skip(!RAHASIA_SUPERADMIN_DUA, 'Set E2E_SUPERADMIN_DUA_MFA_RAHASIA sesuai AkunSintetisSeeder.')
  await page.setViewportSize({ width: 1440, height: 900 })
  const kode = `${KODE_DASAR}_dua`
  const nama = `${NAMA_DASAR} Kontrol Ganda`
  await page.goto('/pengaturan/peran')
  await page.getByRole('button', { name: 'Buat peran' }).click()
  const buat = page.getByRole('dialog', { name: 'Buat peran' })
  await buat.getByLabel('Kode peran').fill(kode)
  await buat.getByLabel('Nama peran').fill(nama)
  await buat.getByLabel('Dasar perubahan').fill('SK Direktur E2E/7')
  await buat.getByRole('button', { name: 'Buat peran' }).click()
  await expect(page.getByRole('heading', { level: 2, name: nama })).toBeVisible()
  await page.getByRole('searchbox', { name: 'Cari menu di matriks' }).fill('Akun & akses')
  await page.getByRole('combobox', { name: 'Tingkat Pengaturan Sistem → Akun & akses' }).selectOption('perbaiki')
  await page.getByRole('button', { name: 'Simpan perubahan' }).click()
  const simpan = page.getByRole('alertdialog', { name: `Simpan perubahan izin ${nama}?` })
  await simpan.getByLabel('Dasar perubahan').fill('SK Direktur E2E/8')
  await simpan.getByRole('button', { name: 'Simpan perubahan' }).click()
  await expect(page.getByRole('status').filter({ hasText: /2 izin sensitif menunggu persetujuan Superadmin/ })).toBeVisible()

  const b = await halamanAkun(browser, new URL(info.project.use.baseURL ?? '').origin, 'contoh.superadmin.dua', RAHASIA_SUPERADMIN_DUA)
  await b.page.setViewportSize({ width: 1440, height: 900 })
  await b.page.goto('/pengaturan/persetujuan')
  const antrean = b.page.getByRole('region', { name: 'Perlu saya putuskan' })
  for (const aksi of ['Input', 'Perbaiki']) {
    await antrean.getByRole('button', { name: new RegExp(`Akun & akses → ${aksi} ke peran ${nama}`) }).click()
    const rincian = b.page.getByRole('region', { name: new RegExp(`Akun & akses → ${aksi} ke peran ${nama}`) })
    await expect(rincian).toContainText('Contoh Superadmin')
    await rincian.getByRole('button', { name: 'Setujui' }).click()
    const dialog = b.page.getByRole('dialog', { name: /Setujui PSA-/ })
    await expect(dialog).toContainText('Perubahan langsung berlaku')
    await dialog.getByLabel('Catatan (opsional)').fill('Sesuai SK Direktur')
    await dialog.getByRole('button', { name: 'Setujui' }).click()
    await expect(rincian.getByText('Sudah berlaku')).toBeVisible()

    // 1.6: superadmin B (TTE aktif, TtdSintetisSeeder) menandatangani keputusannya. Yang pertama dengan PIN; yang kedua
    // tanpa PIN karena sesi tanda tangan 15 menit masih berlaku.
    if (PIN_TTD) {
      await rincian.getByRole('button', { name: 'Tandatangani keputusan' }).click()
      const ttd = b.page.getByRole('dialog', { name: 'Tanda tangan elektronik (T1)' })
      await expect(ttd).toContainText('Penanda tangan: Contoh Superadmin Dua')
      await ttd.getByRole('checkbox').check()
      if (aksi === 'Input') await ttd.getByLabel('PIN tanda tangan').fill(PIN_TTD)
      else await expect(ttd.getByText(/PIN tidak perlu dimasukkan lagi/)).toBeVisible()
      await ttd.getByRole('button', { name: 'Tanda tangani' }).click()
      await expect(ttd).toHaveCount(0)
      await expect(rincian.getByText(/Ditandatangani Contoh Superadmin Dua, .* · terverifikasi/)).toBeVisible()
      await expect(rincian.getByRole('button', { name: 'Tandatangani keputusan' })).toHaveCount(0)
    }
  }
  await b.tutup()

  // Superadmin A: izin kini berlaku (tercentang, tidak lagi terkunci "menunggu").
  await page.reload()
  await page.getByRole('searchbox', { name: 'Cari menu di matriks' }).fill('Akun & akses')
  const perbaiki = page.getByRole('checkbox', { name: 'Perbaiki Pengaturan Sistem → Akun & akses (sensitif)' })
  await expect(perbaiki).toBeChecked()
  await expect(perbaiki).toBeEnabled()
})

test('1440 px: kontrol ganda — penugasan dan pengakhiran peran Superadmin berlaku setelah disetujui superadmin lain', async ({ page, browser }, info) => {
  test.skip(!RAHASIA_SUPERADMIN_DUA, 'Set E2E_SUPERADMIN_DUA_MFA_RAHASIA sesuai AkunSintetisSeeder.')
  await page.setViewportSize({ width: 1440, height: 900 })
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const setujuiSemua = async (pola: RegExp) => {
    const b = await halamanAkun(browser, asal, 'contoh.superadmin.dua', RAHASIA_SUPERADMIN_DUA)
    await b.page.setViewportSize({ width: 1440, height: 900 })
    await b.page.goto('/pengaturan/persetujuan')
    await b.page.getByRole('region', { name: 'Perlu saya putuskan' }).getByRole('button', { name: pola }).first().click()
    await b.page.getByRole('region', { name: pola }).getByRole('button', { name: 'Setujui' }).click()
    await b.page.getByRole('dialog', { name: /Setujui PSA-/ }).getByRole('button', { name: 'Setujui' }).click()
    await expect(b.page.getByRole('region', { name: pola }).getByText('Sudah berlaku')).toBeVisible()
    await b.tutup()
  }

  await pilihAkun(page, 'contoh.tanpa.peran', 'Contoh Akun Tanpa Peran')
  await page.getByRole('button', { name: 'Tugaskan peran' }).click()
  const tugas = page.getByRole('dialog', { name: 'Tugaskan peran ke Contoh Akun Tanpa Peran' })
  await tugas.getByLabel('Peran').selectOption({ label: 'Superadmin · wajib MFA · butuh persetujuan superadmin lain' })
  await tugas.getByLabel('Dasar pemberian').fill('SK Direktur E2E/9')
  await tugas.getByRole('button', { name: 'Tugaskan' }).click()
  await expect(page.getByRole('status').filter({ hasText: /menunggu persetujuan Superadmin/ })).toBeVisible()
  await setujuiSemua(/Tugaskan peran Superadmin ke Contoh Akun Tanpa Peran/)

  await page.reload()
  await pilihAkun(page, 'contoh.tanpa.peran', 'Contoh Akun Tanpa Peran')
  const penugasan = page.getByRole('listitem').filter({ hasText: 'Superadmin' }).filter({ hasText: 'SK Direktur E2E/9' })
  await expect(penugasan.getByRole('button', { name: 'Akhiri' })).toBeVisible()

  // Tiga superadmin aktif → pengakhiran juga butuh persetujuan superadmin lain (202), lalu berlaku.
  await penugasan.getByRole('button', { name: 'Akhiri' }).click()
  const akhiri = page.getByRole('alertdialog', { name: /Akhiri penugasan Superadmin/ })
  await akhiri.getByLabel('Alasan mengakhiri').fill('Uji e2e kontrol ganda selesai')
  await akhiri.getByRole('button', { name: 'Akhiri' }).click()
  await expect(page.getByRole('status').filter({ hasText: /Pengakhiran Superadmin menunggu persetujuan Superadmin/ })).toBeVisible()
  await setujuiSemua(/Akhiri penugasan peran Superadmin untuk Contoh Akun Tanpa Peran/)

  await page.reload()
  await pilihAkun(page, 'contoh.tanpa.peran', 'Contoh Akun Tanpa Peran')
  await expect(page.getByText('Belum ada peran yang berlaku.')).toBeVisible()
})


// ── Catatan tinjauan #78: panel halaman masuk mengikuti warna paket RS ──
test('1440 px: panel halaman masuk memakai turunan warna paket RS (kontras terjaga), lalu kembali ke warna semula', async ({ page, browser }, info) => {
  const asal = new URL(info.project.use.baseURL ?? '').origin
  await page.goto('/pengaturan/profil-rs')
  const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const header = { Accept: 'application/json', Origin: asal, Referer: `${asal}/`, 'X-XSRF-TOKEN': xsrf }
  type Warna = { brand: string; accent: string }
  type Profil = { versi: string; nama: string; paket_identitas: { warna: Warna }; kontak_admin: { unit: string; kontak: string | null }; rinci: Record<string, unknown> }
  const ambil = async () => ((await (await page.request.get('/api/v1/profil-rs', { headers: header })).json()) as { data: Profil }).data
  const ubahWarna = async (warna: Warna) => {
    const p = await ambil()
    const r = await page.request.put('/api/v1/profil-rs', {
      headers: { ...header, 'If-Match': `"${p.versi}"` },
      data: { nama: p.nama, kode_kemenkes: p.rinci.kode_kemenkes, kelas: p.rinci.kelas, status_blu: p.rinci.status_blu, kode_ppk_bpjs: p.rinci.kode_ppk_bpjs,
        alamat: p.rinci.alamat, warna, kontak_admin: p.kontak_admin, dasar_perubahan: 'Uji panel merek e2e' },
    })
    expect(r.ok(), `${r.status()} ${await r.text()}`).toBeTruthy()
  }
  const semula = (await ambil()).paket_identitas.warna
  await ubahWarna({ brand: '#7A1F5C', accent: '#9BE3C7' })
  try {
    const tamu = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI, viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' })
    const masuk = await tamu.newPage()
    await masuk.goto('/masuk')
    const token = (nama: string) => masuk.evaluate((t) => getComputedStyle(document.documentElement).getPropertyValue(t).trim(), nama)
    await expect.poll(() => masuk.evaluate(() => document.documentElement.style.getPropertyValue('--panel-merek'))).not.toBe('')
    expect((await token('--panel-merek')).toUpperCase()).not.toBe('#135851')
    // axe menandai kontras di atas gradien sebagai "incomplete": kontras dihitung dari token panel yang terpasang
    // (tiga titik gradien dan sorot 45 % di atas tiap titik).
    const rgb = (hex: string) => [1, 3, 5].map((i) => Number.parseInt(hex.slice(i, i + 2), 16))
    const lum = (hex: string) => {
      const [r, g, b] = rgb(hex).map((c) => (c / 255 <= 0.03928 ? c / 255 / 12.92 : ((c / 255 + 0.055) / 1.055) ** 2.4)) as [number, number, number]
      return 0.2126 * r + 0.7152 * g + 0.0722 * b
    }
    const kontras = (a: string, b: string) => (Math.max(lum(a), lum(b)) + 0.05) / (Math.min(lum(a), lum(b)) + 0.05)
    const campur = (a: string, b: string, t: number) =>
      `#${rgb(a).map((x, i) => Math.round(x + (rgb(b)[i]! - x) * t).toString(16).padStart(2, '0')).join('')}`
    const titik = await Promise.all(['--panel-merek', '--panel-merek-tengah', '--panel-merek-akhir'].map(token))
    const sorot = await token('--panel-merek-sorot')
    const teks = ['#FFFFFF', await token('--brand-100'), await token('--penekanan-panel')]
    for (const latar of [...titik, ...titik.map((x) => campur(x, sorot, 0.45))]) {
      for (const t of teks) expect(kontras(t, latar), `${t} di ${latar}`).toBeGreaterThanOrEqual(4.5)
    }
    const axe = await new AxeBuilder({ page: masuk }).analyze()
    expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
    await tamu.close()
  } finally {
    await ubahWarna(semula)
  }
})

// ── Tiket 1.15: halaman masuk dapat diatur (teks, foto, motif) ──
test('halaman masuk dapat diatur: teks dan foto baru tampil di /masuk tanpa build ulang, tag HTML tetap teks; tab di 4 lebar + axe', async ({ page, browser }, info) => {
  const asal = new URL(info.project.use.baseURL ?? '').origin
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/pengaturan/profil-rs')
  const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const header = { Accept: 'application/json', Origin: asal, Referer: `${asal}/`, 'X-XSRF-TOKEN': xsrf }
  type Teks = Record<string, unknown> & { bawaan: boolean }
  type Profil = { versi: string; nama: string | null; paket_identitas: { warna: unknown }; kontak_admin: unknown; rinci: Record<string, unknown>; halaman_masuk?: Teks }
  const ambil = async () => ((await (await page.request.get('/api/v1/profil-rs', { headers: header })).json()) as { data: Profil }).data
  const semula = await ambil()
  test.skip(!semula.halaman_masuk, 'Backend belum mengirim halaman_masuk (sebelum 1.15-be).')
  test.skip(!semula.nama, 'Identitas RS belum diisi (uji profil RS di atas mengisinya).')

  // Foto panel sintetis (gradien, bukan foto nyata) dibuat di peramban.
  const kanvas = await browser.newPage({ viewport: { width: 800, height: 500 } })
  await kanvas.setContent('<body style="margin:0;height:500px;background:linear-gradient(135deg,#9cc3d5,#b7c4a8)"></body>')
  const foto = await kanvas.screenshot({ type: 'png' })
  await kanvas.close()

  try {
    await page.getByRole('tab', { name: 'Halaman masuk' }).click()
    const form = page.getByRole('form', { name: 'Panel halaman masuk' })
    await form.getByLabel(/^Judul\s*\*?$/).fill('<script>alert(1)</script> Judul uji e2e')
    await form.getByLabel('Bagian sorot judul (opsional)').fill('sorot e2e.')
    await form.getByLabel('Keterangan foto (opsional)').fill('Ilustrasi fiktif e2e')
    await expect(page.getByRole('img', { name: 'Pratinjau panel halaman masuk' })).toContainText('Judul uji e2e sorot e2e.')
    await form.getByLabel('Dasar perubahan').fill('Memo E2E/halaman-masuk')
    await form.getByRole('button', { name: 'Simpan halaman masuk' }).click()
    await expect(page.getByText('Halaman masuk disimpan')).toBeVisible()
    await page.locator('input[data-berkas="foto"]').setInputFiles({ name: 'foto.png', mimeType: 'image/png', buffer: foto })
    await expect(page.getByRole('img', { name: 'Foto panel saat ini' })).toHaveAttribute('src', /\/api\/v1\/profil-rs\/berkas\/foto\?v=/)

    for (const lebar of [1440, 1024, 768, 390]) {
      await page.setViewportSize({ width: lebar, height: 900 })
      await tanpaGulirSamping(page)
      const axe = await new AxeBuilder({ page }).analyze()
      expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id), `${lebar} px`).toEqual([])
    }

    const tamu = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI, viewport: { width: 1440, height: 900 } })
    const masuk = await tamu.newPage()
    await masuk.goto('/masuk')
    const judul = masuk.locator('.judul-merek')
    await expect(judul).toHaveText('<script>alert(1)</script> Judul uji e2e sorot e2e.')
    await expect(judul.locator('script')).toHaveCount(0)
    await expect(masuk.locator('.foto figcaption')).toHaveText('Ilustrasi fiktif e2e')
    await expect.poll(() => masuk.locator('.foto img').evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth / el.naturalHeight)).toBeCloseTo(1.6, 1)
    await tamu.close()
  } finally {
    // Teks kembali seperti semula (foto tidak bisa dikosongkan di fase 1; foto sintetis tetap terpasang).
    const p = await ambil()
    const { bawaan, ...teks } = semula.halaman_masuk!
    const r = await page.request.put('/api/v1/profil-rs', {
      headers: { ...header, 'If-Match': `"${p.versi}"` },
      data: { nama: p.nama, kode_kemenkes: p.rinci.kode_kemenkes, kelas: p.rinci.kelas, status_blu: p.rinci.status_blu, kode_ppk_bpjs: p.rinci.kode_ppk_bpjs,
        alamat: p.rinci.alamat, warna: p.paket_identitas.warna, kontak_admin: p.kontak_admin, halaman_masuk: bawaan ? null : teks, dasar_perubahan: 'Pulihkan halaman masuk e2e' },
    })
    expect(r.ok(), `${r.status()} ${await r.text()}`).toBeTruthy()
  }
})

// ── Tiket 1.8d: nonaktif/aktif akun dengan alasan ──
test('1440 px: nonaktifkan akun dengan alasan lalu aktifkan kembali (contoh.terkunci); akun sendiri tanpa tombol', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/pengaturan/akun')
  await pilihAkun(page, 'contoh.terkunci', 'Contoh Akun Uji Kunci')
  const rincian = page.getByRole('region', { name: 'Contoh Akun Uji Kunci' })
  await rincian.getByRole('button', { name: 'Nonaktifkan akun' }).click()
  const nonaktif = page.getByRole('alertdialog', { name: 'Nonaktifkan contoh.terkunci?' })
  await nonaktif.getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(nonaktif.getByText('Isi alasan, minimal 5 karakter.')).toBeVisible()
  await nonaktif.getByLabel('Alasan').fill('Uji e2e nonaktif akun (sintetis)')
  await nonaktif.getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Akun contoh.terkunci dinonaktifkan; sesinya diakhiri.' })).toBeVisible()
  await expect(rincian.getByText('Nonaktif', { exact: true })).toBeVisible()

  await rincian.getByRole('button', { name: 'Aktifkan akun' }).click()
  const aktif = page.getByRole('dialog', { name: 'Aktifkan contoh.terkunci?' })
  await aktif.getByLabel('Alasan').fill('Uji e2e selesai (sintetis)')
  await aktif.getByRole('button', { name: 'Aktifkan' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Akun contoh.terkunci diaktifkan.' })).toBeVisible()
  await expect(rincian.getByRole('button', { name: 'Nonaktifkan akun' })).toBeVisible()

  await pilihAkun(page, 'contoh.superadmin', 'Contoh Superadmin')
  await expect(page.getByRole('button', { name: /^(Nonaktifkan|Aktifkan) akun$/ })).toHaveCount(0)
})


// ── Tiket 1.5: label sensitivitas (lapis 4) ──
/** Setujui pengajuan lewat API sebagai akun lain (penyetuju tidak butuh izin halaman Persetujuan akses). */
async function setujuiLewatApi(browser: Browser, asal: string, nama: string, rahasia: string, persetujuanId: string) {
  const k = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
  await masukLewatApi(k.request, asal, nama, rahasia)
  const xsrf = decodeURIComponent((await k.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const r = await k.request.post(`/api/v1/persetujuan-akses/${persetujuanId}/setujui`, {
    headers: { Accept: 'application/json', Origin: asal, Referer: `${asal}/`, 'X-XSRF-TOKEN': xsrf },
    data: { catatan: null },
  })
  expect(r.ok(), `${r.status()} ${await r.text()}`).toBeTruthy()
  await k.close()
}
async function pengajuanTerakhir(page: Page, asal: string): Promise<{ id: string; jenis: string }> {
  const r = await page.request.get('/api/v1/persetujuan-akses?tab=saya_ajukan&batas=1', { headers: { Accept: 'application/json', Origin: asal, Referer: `${asal}/` } })
  expect(r.ok()).toBeTruthy()
  return ((await r.json()) as { data: { id: string; jenis: string }[] }).data[0]!
}

test('1440 px: izin label — penyetuju label disetujui superadmin lain, izin label menunggu pejabat PDP lalu berlaku dan dicabut; tab Label lolos axe', async ({ page, browser }, info) => {
  test.skip(!RAHASIA_SUPERADMIN_DUA || !RAHASIA_SDM, 'Set E2E_SUPERADMIN_DUA_MFA_RAHASIA dan E2E_SDM_MFA_RAHASIA sesuai AkunSintetisSeeder.')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  await page.setViewportSize({ width: 1440, height: 900 })

  // Penyetuju label HIV = peran Contoh Admin SDM (pejabat PDP sintetis); berlaku sesudah superadmin lain setuju (K13).
  await page.goto('/pengaturan/persetujuan?tab=penyetuju-label')
  await page.getByRole('button', { name: 'Tetapkan penyetuju label HIV' }).click()
  const penyetuju = page.getByRole('dialog', { name: 'Penyetuju label HIV' })
  await penyetuju.getByLabel('Peran penyetuju').selectOption({ label: 'Contoh Admin SDM' })
  await penyetuju.getByLabel('Dasar perubahan').fill('SK PDP E2E/1')
  await penyetuju.getByRole('button', { name: 'Ajukan' }).click()
  await expect(page.getByRole('status').filter({ hasText: /Penyetuju label HIV menunggu persetujuan Superadmin \(PSA-/ })).toBeVisible()
  await setujuiLewatApi(browser, asal, 'contoh.superadmin.dua', RAHASIA_SUPERADMIN_DUA, (await pengajuanTerakhir(page, asal)).id)
  await page.reload()
  await expect(page.getByRole('row', { name: /HIV/ })).toContainText('Contoh Admin SDM')

  // Izin label untuk contoh.admisi: selalu menunggu pejabat PDP, berbatas waktu.
  await pilihAkun(page, 'contoh.admisi', 'Contoh Petugas Admisi')
  await bukaTab(page, 'Label')
  await expect(page.getByText('Akun ini tidak punya izin label yang berlaku.')).toBeVisible()
  const axe = await new AxeBuilder({ page }).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
  await page.getByRole('button', { name: 'Ajukan izin label' }).click()
  const ajukan = page.getByRole('alertdialog', { name: 'Ajukan izin label untuk Contoh Petugas Admisi' })
  await ajukan.getByLabel('Label sensitivitas').selectOption('hiv')
  await expect(ajukan).toContainText('Disetujui oleh pemegang peran Contoh Admin SDM')
  const sampai = new Date(Date.now() + 30 * 86_400_000)
  await ajukan.getByLabel('Berlaku sampai').fill(`${sampai.getFullYear()}-${String(sampai.getMonth() + 1).padStart(2, '0')}-${String(sampai.getDate()).padStart(2, '0')}`)
  await ajukan.getByLabel('Dasar pemberian').fill('ST E2E/7')
  await ajukan.getByLabel('Alasan').fill('Konselor VCT sintetis e2e')
  await ajukan.getByRole('button', { name: 'Ajukan' }).click()
  await expect(page.getByRole('status').filter({ hasText: /Izin label HIV untuk Contoh Petugas Admisi menunggu persetujuan Contoh Admin SDM/ })).toBeVisible()
  await expect(page.getByRole('list', { name: 'Menunggu persetujuan' })).toContainText('HIV')

  const izin = await pengajuanTerakhir(page, asal)
  expect(izin.jenis).toBe('izin_label')
  await setujuiLewatApi(browser, asal, 'contoh.sdm', RAHASIA_SDM, izin.id)
  await page.reload()
  await pilihAkun(page, 'contoh.admisi', 'Contoh Petugas Admisi')
  await bukaTab(page, 'Label')
  const berlaku = page.getByRole('list', { name: 'Izin label yang berlaku' })
  await expect(berlaku).toContainText('HIV')

  await berlaku.getByRole('button', { name: 'Cabut' }).click()
  const cabut = page.getByRole('alertdialog', { name: 'Cabut izin label HIV?' })
  await cabut.getByLabel('Alasan mencabut').fill('Uji e2e pencabutan (sintetis)')
  await cabut.getByRole('button', { name: 'Cabut' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Izin label HIV untuk Contoh Petugas Admisi dicabut.' })).toBeVisible()
  await expect(page.getByRole('list', { name: 'Izin label yang berlaku' })).toHaveCount(0)
})
