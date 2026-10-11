import AxeBuilder from '@axe-core/playwright'
import { expect, test, type APIRequestContext, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Beranda (tiket 1.2e) ke backend asli dengan akun sintetis AkunSintetisSeeder. Peran contoh: contoh.dokter →
 * IGD, Rawat Jalan, Rekam Medis; contoh.admisi → Pendaftaran (semuanya Segera hadir di APP_ENV=local);
 * contoh.tanpa.peran tanpa penugasan.
 * Kartu modul aktif (Pengaturan) diuji di pengaturan.spec.ts dengan sesi superadmin.
 */
type Sesi = Awaited<ReturnType<BrowserContext['storageState']>>
const sesi = new Map<string, Sesi>()

test.beforeAll(({}, info) => {
  // Lebar layar diatur per uji, jadi cukup satu proyek.
  test.skip(info.project.name !== 'desktop', 'lebar layar diatur per uji')
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

/** Satu kali masuk per akun per berkas (rate limit masuk 10/menit per nama pengguna). */
async function halaman(browser: Browser, asal: string, nama: string, lebar = 1440, tinggi = 900): Promise<Page> {
  let s = sesi.get(nama)
  if (!s) {
    const masuk = await browser.newContext({ baseURL: asal })
    await masukLewatApi(masuk.request, asal, nama)
    s = await masuk.storageState()
    sesi.set(nama, s)
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: s, viewport: { width: lebar, height: tinggi } })
  return konteks.newPage()
}

const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const modulSaya = (page: Page) => page.getByRole('region', { name: 'Modul saya' })
const judulKartu = (page: Page) => modulSaya(page).getByRole('article').getByRole('heading', { level: 3 })

async function bukaBeranda(page: Page, nama: string) {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^Selamat (pagi|siang|sore|malam), ${nama}$`) })).toBeVisible()
}

test('kisi modul mengikuti peran: contoh.dokter dan contoh.admisi berbeda; kartu Segera hadir membuka SegeraHadirPage', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const dokter = await halaman(browser, asal, 'contoh.dokter')
  await bukaBeranda(dokter, 'Contoh Dokter')
  await expect(dokter.getByText('· Contoh Dokter')).toBeVisible()
  await expect(judulKartu(dokter)).toHaveText(['IGD', 'Rawat Jalan', 'Rekam Medis'])
  await expect(modulSaya(dokter).getByRole('article', { name: 'IGD' }).getByText(/^Segera hadir · fase \d$/)).toBeVisible()
  // Sub-menu bisa diklik langsung dari kartu.
  await modulSaya(dokter).getByRole('article', { name: 'IGD' }).getByRole('link', { name: 'Papan pasien IGD' }).click()
  await expect(dokter).toHaveURL(/\/igd\/papan$/)
  await expect(dokter.getByRole('main').getByText('Segera hadir', { exact: true })).toBeVisible()

  const admisi = await halaman(browser, asal, 'contoh.admisi')
  await bukaBeranda(admisi, 'Contoh Petugas Admisi')
  await expect(judulKartu(admisi)).toHaveText(['Pendaftaran'])
  await modulSaya(admisi).getByRole('link', { name: 'Pendaftaran', exact: true }).click()
  await expect(admisi.getByRole('heading', { level: 1, name: 'Pendaftaran' })).toBeVisible()
  await expect(admisi.getByText(/tersedia pada fase \d/)).toBeVisible()
})

test('akun tanpa penugasan hanya melihat Beranda dengan keterangan siapa yang dihubungi', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 'contoh.tanpa.peran')
  await bukaBeranda(page, 'Contoh Akun Tanpa Peran')
  await expect(modulSaya(page).getByText(/Belum ada modul yang diberikan, hubungi \S/)).toBeVisible()
  await expect(modulSaya(page).getByRole('article')).toHaveCount(0)
  await expect(page.getByRole('navigation', { name: 'Menu utama' }).getByRole('link')).toHaveText(['Beranda'])
})

test('masuk dua kali: kali kedua menampilkan waktu masuk sebelumnya, bukan waktu sekarang', async ({ browser, playwright }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const saya = async (r: APIRequestContext) =>
    (await (await r.get('/api/v1/saya', { headers: { Accept: 'application/json', Referer: `${asal}/` } })).json()).data as {
      login_terakhir_pada: string
      masuk_sebelumnya_pada: string | null
      masuk_sebelumnya_dari_jaringan_rs: boolean | null
    }

  // Akun yang tidak dipakai spec lain di proyek ini: masuk paralel akun yang sama akan mengubah "masuk terakhir".
  const pertama = await playwright.request.newContext({ baseURL: asal })
  await masukLewatApi(pertama, asal, 'contoh.tanpa.peran')
  const masukPertama = (await saya(pertama)).login_terakhir_pada
  await pertama.dispose()
  // Waktu masuk disimpan per detik; jeda agar masuk kedua pasti berbeda.
  await new Promise((r) => setTimeout(r, 1100))

  const kedua = await browser.newContext({ baseURL: asal })
  await masukLewatApi(kedua.request, asal, 'contoh.tanpa.peran')
  const isi = await saya(kedua.request)
  expect(isi.masuk_sebelumnya_pada).toBe(masukPertama)
  expect(isi.login_terakhir_pada).not.toBe(masukPertama)

  const page = await kedua.newPage()
  await bukaBeranda(page, 'Contoh Akun Tanpa Peran')
  const keamanan = page.getByRole('region', { name: 'Keamanan akun' })
  const waktu = new Date(masukPertama)
  const tanggal = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' }).format(waktu)
  const jam = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' }).format(waktu).replace(':', '.')
  await expect(keamanan.getByText(`${tanggal}, ${jam}`, { exact: false })).toBeVisible()
  await expect(keamanan.getByText(isi.masuk_sebelumnya_dari_jaringan_rs ? 'dari jaringan RS' : 'dari luar jaringan RS', { exact: false })).toBeVisible()
  await kedua.close()
})

test('Ctrl/Cmd+K hanya menemukan menu yang diizinkan dan dapat dipakai penuh dengan keyboard', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 'contoh.dokter')
  await bukaBeranda(page, 'Contoh Dokter')

  await page.keyboard.press('ControlOrMeta+k')
  const dialog = page.getByRole('dialog', { name: /^Cari menu/ })
  const kolom = dialog.getByRole('combobox', { name: 'Nama menu atau sub-menu' })
  await expect(kolom).toBeFocused()

  // Pengaturan tidak diberikan ke contoh.dokter, jadi tidak pernah muncul.
  await page.keyboard.type('akun')
  await expect(dialog.getByRole('status')).toContainText('Tidak ada menu yang cocok')
  await expect(dialog.getByRole('option')).toHaveCount(0)

  await kolom.fill('')
  await page.keyboard.type('papan')
  await expect(dialog.getByRole('option')).toHaveText([/Papan pasien IGD/])
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/\/igd\/papan$/)
  await expect(dialog).toBeHidden()

  // Dari tombol header: Esc menutup dan fokus kembali ke tombol.
  const tombol = page.getByRole('button', { name: 'Cari menu' }).first()
  await tombol.click()
  await expect(kolom).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(tombol).toBeFocused()
})

for (const [lebar, tinggi] of [
  [1440, 900],
  [1024, 768],
  [768, 1024],
  [390, 844],
] as const) {
  test(`${lebar} px: Beranda tanpa gulir samping dan tanpa pelanggaran axe serius/kritis`, async ({ browser }, info) => {
    const page = await halaman(browser, asalDari(info.project.use.baseURL), 'contoh.dokter', lebar, tinggi)
    await bukaBeranda(page, 'Contoh Dokter')
    await expect(page.getByRole('region', { name: 'Kotak kerja' })).toBeVisible()
    await expect(page.getByRole('region', { name: 'Keamanan akun' })).toBeVisible()
    if (lebar < 768) await expect(modulSaya(page)).toHaveCount(0)
    else await expect(modulSaya(page)).toBeVisible()
    await tanpaGulirSamping(page)

    const hasil = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
    const berat = hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
    expect(berat.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([])
  })
}
