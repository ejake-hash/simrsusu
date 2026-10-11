import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * SDM → Data pegawai (tiket 1.8d) ke backend asli dengan akun sintetis contoh.sdm (Contoh Admin SDM: sdm.pegawai.*,
 * sdm.kredensial.*, pengaturan.akun.lihat/input; wajib MFA, rahasia E2E_SDM_MFA_RAHASIA). Tanpa izin bagian identitas:
 * NIK hanya tersamar. Pegawai dan akun baru memakai akhiran unik per run; semua data sintetis.
 */
type Sesi = Awaited<ReturnType<BrowserContext['storageState']>>
let sesi: Sesi | undefined
const RAHASIA_SDM = process.env.E2E_SDM_MFA_RAHASIA ?? ''
const AKHIRAN = Date.now().toString(36)
const NAMA = `Pegawai Uji ${AKHIRAN.toUpperCase()}`
const NAMA_PENGGUNA = `uji.${AKHIRAN}`
const TANPA_SESI = { cookies: [], origins: [] }

test.describe.configure({ mode: 'serial' })
test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'lebar layar diatur per uji')
  if (!SANDI || !RAHASIA_SDM) throw new Error('Set E2E_SANDI dan E2E_SDM_MFA_RAHASIA sesuai AkunSintetisSeeder.')
})

// Batas laju API 120/menit per akun berlaku di e2e; semua uji berkas ini memakai contoh.sdm (pola organisasi.spec.ts).
const BATAS_PER_MENIT = 70
const waktuPermintaan: number[] = []
async function jagaBatasLaju() {
  const sekarang = Date.now()
  while (waktuPermintaan.length && sekarang - waktuPermintaan[0]! > 61_000) waktuPermintaan.shift()
  if (waktuPermintaan.length <= BATAS_PER_MENIT) return
  const jeda = 61_000 - (sekarang - waktuPermintaan[0]!)
  test.info().setTimeout(test.info().timeout + jeda)
  await new Promise((r) => setTimeout(r, jeda))
}
test.beforeEach(async () => jagaBatasLaju())

/** Satu kali masuk per berkas: kode TOTP tidak bisa dipakai dua kali dalam periodenya. */
async function halaman(browser: Browser, asal: string, lebar = 1440, tinggi = 900): Promise<Page> {
  if (!sesi) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    await masukLewatApi(masuk.request, asal, 'contoh.sdm', RAHASIA_SDM)
    sesi = await masuk.storageState()
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi, viewport: { width: lebar, height: tinggi } })
  konteks.on('request', (r) => {
    if (r.url().includes('/api/v1/')) waktuPermintaan.push(Date.now())
  })
  return konteks.newPage()
}
const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const dialog = (page: Page) => page.getByRole('dialog').or(page.getByRole('alertdialog'))
const rincian = (page: Page) => page.getByRole('region', { name: NAMA })
const hariIniRs = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
/** PDF minimal yang sah (jenis berkas diperiksa server dari isinya). */
const PDF = Buffer.from(
  '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n',
)

async function seriusAtauKritis(page: Page): Promise<string[]> {
  const hasil = await new AxeBuilder({ page }).analyze()
  return hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').flatMap((v) => v.nodes.map((n) => `${v.id}: ${n.target.join(' ')}`))
}
async function bukaPegawai(page: Page) {
  await page.goto('/sdm/pegawai')
  await page.getByRole('searchbox', { name: 'Cari pegawai' }).fill(NAMA)
  await page.getByRole('region', { name: 'Daftar pegawai' }).getByText(NAMA).click()
  await expect(page.getByRole('heading', { level: 2, name: NAMA })).toBeVisible()
}

let sandiAwal = ''
/** Sesi akun baru sesudah ganti sandi (uji keluar memakai sesi berjalan ini). */
let sesiAkunBaru: Sesi | undefined

test('1440 px: tambah pegawai, NIK tidak bisa diisi tanpa izin identitas; izin praktik + dokumen, unduh, RKK, batalkan', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/sdm/pegawai')
  await expect(page.getByRole('heading', { level: 1, name: 'Data pegawai' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Daftar pegawai' })).toContainText('Contoh Admin SDM')

  await page.getByRole('button', { name: 'Tambah pegawai' }).click()
  await expect(dialog(page).getByLabel(/^NIK/)).toHaveCount(0)
  await dialog(page).getByLabel('Nama lengkap').fill(NAMA)
  await dialog(page).getByLabel('NIP (opsional)').fill(`NIP-${AKHIRAN}`)
  await dialog(page).getByLabel('Profesi').selectOption('bidan')
  await dialog(page).getByRole('combobox', { name: 'Unit kerja (opsional)' }).fill('IRNA')
  await dialog(page).getByRole('option', { name: /IRNA/ }).first().click()
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e 1.8d')
  await dialog(page).getByRole('button', { name: 'Tambah pegawai' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 2, name: NAMA })).toBeVisible()
  await expect(rincian(page)).toContainText('Belum punya akun.')

  // STR seumur hidup.
  await rincian(page).getByRole('button', { name: 'Catat izin' }).click()
  await dialog(page).getByLabel('Jenis izin').selectOption('str')
  await dialog(page).getByLabel('Nomor').fill(`STR-${AKHIRAN}`)
  await dialog(page).getByLabel('Tanggal terbit').fill('2020-01-02')
  await dialog(page).getByLabel(/STR seumur hidup/).check()
  await dialog(page).getByRole('button', { name: 'Catat izin' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(rincian(page).getByRole('listitem').filter({ hasText: `STR-${AKHIRAN}` })).toContainText('seumur hidup')

  // SIP dengan dokumen PDF, lalu diunduh dengan nama generik.
  await jagaBatasLaju()
  await rincian(page).getByRole('button', { name: 'Catat izin' }).click()
  await dialog(page).getByLabel('Jenis izin').selectOption('sip')
  await dialog(page).getByLabel('Nomor').fill(`SIP-${AKHIRAN}`)
  await dialog(page).getByLabel('Tanggal terbit').fill('2026-01-02')
  await dialog(page).getByLabel('Berlaku sampai').fill('2031-01-01')
  await dialog(page).getByLabel('Dokumen (opsional)').setInputFiles({ name: 'sip.pdf', mimeType: 'application/pdf', buffer: PDF })
  await dialog(page).getByRole('button', { name: 'Catat izin' }).click()
  await expect(dialog(page)).toHaveCount(0)
  const sip = rincian(page).getByRole('listitem').filter({ hasText: `SIP-${AKHIRAN}` })
  await expect(sip).toContainText('Berlaku')
  const unduhan = page.waitForEvent('download')
  await sip.getByRole('button', { name: `Unduh dokumen SIP SIP-${AKHIRAN}` }).click()
  expect((await unduhan).suggestedFilename()).toMatch(/^sip-[0-9a-f-]+\.pdf$/)

  // RKK.
  await jagaBatasLaju()
  await rincian(page).getByRole('button', { name: 'Catat RKK' }).click()
  await dialog(page).getByLabel('Kategori').fill('Asuhan kebidanan dasar (sintetis)')
  await dialog(page).getByLabel('Kode tindakan').fill('73.59, 75.34')
  await dialog(page).getByLabel('Berlaku dari').fill('2026-10-01')
  await dialog(page).getByLabel('Berlaku sampai').fill('2027-09-30')
  await dialog(page).getByLabel('Komite').fill('Komite Tenaga Kesehatan Lain')
  await dialog(page).getByRole('button', { name: 'Catat RKK' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(rincian(page)).toContainText('Asuhan kebidanan dasar (sintetis)')

  // Batalkan SIP: tetap tampil sebagai riwayat.
  await sip.getByRole('button', { name: `Batalkan SIP SIP-${AKHIRAN}` }).click()
  const batal = page.getByRole('alertdialog', { name: `Batalkan SIP SIP-${AKHIRAN}?` })
  await batal.getByLabel('Alasan pembatalan').fill('Salah ketik nomor (uji e2e)')
  await batal.getByRole('button', { name: 'Batalkan catatan' }).click()
  await expect(batal).toHaveCount(0)
  await expect(sip).toContainText('Dibatalkan')
  await expect(sip).toContainText('Salah ketik nomor (uji e2e)')
  await expect(await seriusAtauKritis(page)).toEqual([])
  await page.context().close()
})

test('buat akun dari pegawai: sandi awal sekali tampil; masuk pertama dipaksa ganti sandi, sandi memuat nama pengguna ditolak', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const page = await halaman(browser, asal)
  await bukaPegawai(page)
  await rincian(page).getByRole('button', { name: 'Buat akun' }).click()
  await dialog(page).getByLabel('Nama pengguna').fill(NAMA_PENGGUNA)
  await dialog(page).getByLabel('Dasar pemberian').fill('Surat permintaan akun uji e2e')
  await dialog(page).getByRole('button', { name: 'Buat akun' }).click()
  await expect(dialog(page).getByRole('heading', { name: 'Akun dibuat' })).toBeVisible()
  await expect(dialog(page).locator('code.sandi')).toHaveCount(0)
  await expect(dialog(page).locator('.sandi')).toContainText('Disembunyikan')
  await dialog(page).getByRole('button', { name: 'Tampilkan' }).click()
  sandiAwal = (await dialog(page).locator('code.sandi').textContent()) ?? ''
  expect(sandiAwal.length).toBeGreaterThanOrEqual(16)
  await dialog(page).getByRole('button', { name: 'Selesai' }).click()
  await expect(page.getByText(sandiAwal)).toHaveCount(0)
  await expect(rincian(page)).toContainText(NAMA_PENGGUNA)
  await page.context().close()

  // Pemilik akun masuk lewat halaman masuk dengan sandi awal → layar ganti sandi.
  const baru = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
  const p = await baru.newPage()
  await p.goto('/sdm/pegawai')
  await p.getByLabel('Nama pengguna').fill(NAMA_PENGGUNA)
  await p.getByLabel('Kata sandi', { exact: true }).fill(sandiAwal)
  await p.getByRole('button', { name: 'Masuk', exact: true }).click()
  await expect(p).toHaveURL(/\/ganti-sandi/)
  await expect(p.getByRole('heading', { level: 1, name: 'Buat sandi Anda sendiri' })).toBeVisible()
  await p.getByLabel('Sandi awal', { exact: true }).fill(sandiAwal)
  await p.getByLabel('Sandi baru', { exact: true }).fill(`${NAMA_PENGGUNA}-panjang-sekali`)
  await p.getByLabel('Ulangi sandi baru', { exact: true }).fill(`${NAMA_PENGGUNA}-panjang-sekali`)
  const ditolak = p.waitForResponse((r) => r.url().endsWith('/api/v1/saya/sandi'))
  await p.getByRole('button', { name: 'Simpan sandi baru' }).click()
  expect((await ditolak).status()).toBe(422)
  // Pesan dari rincian server di kolom sandi baru (bukan petunjuk statis kolom itu).
  await expect(p.getByText('Sandi tidak boleh memuat nama pengguna, nama rumah sakit, atau nama aplikasi.')).toBeVisible()
  await expect(p).toHaveURL(/\/ganti-sandi/)

  const sandiBaru = `Lembah-Hijau-${AKHIRAN}-Sintetis!`
  await p.getByLabel('Sandi awal', { exact: true }).fill(sandiAwal)
  await p.getByLabel('Sandi baru', { exact: true }).fill(sandiBaru)
  await p.getByLabel('Ulangi sandi baru', { exact: true }).fill(sandiBaru)
  await p.getByRole('button', { name: 'Simpan sandi baru' }).click()
  // Kembali ke rute asal; akun baru tanpa peran, jadi /sdm/pegawai bukan halaman yang boleh dibuka.
  await expect(p).not.toHaveURL(/\/ganti-sandi/)
  await expect(await seriusAtauKritis(p)).toEqual([])
  sandiAwal = ''
  sesiAkunBaru = await baru.storageState()
  await baru.close()
})


test('pegawai dicatat keluar hari ini → akun nonaktif dan sesi berjalan diputus di permintaan berikutnya', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  test.skip(!sesiAkunBaru, 'butuh akun dari uji sebelumnya')
  const milikPegawai = await browser.newContext({ baseURL: asal, storageState: sesiAkunBaru })
  const p = await milikPegawai.newPage()
  await p.goto('/')
  await expect(p.getByRole('heading', { level: 1, name: new RegExp(`^Selamat (pagi|siang|sore|malam), ${NAMA}$`) })).toBeVisible()

  const page = await halaman(browser, asal)
  await bukaPegawai(page)
  await rincian(page).getByRole('button', { name: 'Catat keluar' }).click()
  const keluar = page.getByRole('alertdialog', { name: `Catat keluar: ${NAMA}` })
  await keluar.getByLabel('Tanggal keluar').fill(hariIniRs())
  await expect(keluar.getByText(/akun langsung dinonaktifkan/)).toBeVisible()
  await keluar.getByLabel('Alasan keluar').fill('Selesai kontrak (uji e2e)')
  await keluar.getByLabel('Dasar perubahan').fill('SK uji e2e 1.8d keluar')
  await keluar.getByRole('button', { name: 'Catat keluar' }).click()
  await expect(keluar).toHaveCount(0)
  await expect(rincian(page).getByText('Keluar', { exact: true })).toBeVisible()
  await expect(rincian(page)).toContainText('Akunnya nonaktif.')
  await expect(rincian(page).getByRole('button', { name: 'Buat akun' })).toHaveCount(0)
  await page.context().close()

  await p.reload()
  await expect(p).toHaveURL(/\/masuk/)
  await milikPegawai.close()
})

for (const [lebar, tinggi] of [
  [1024, 768],
  [390, 844],
] as const) {
  test(`${lebar} px: daftar dan rincian pegawai tanpa gulir samping halaman`, async ({ browser }, info) => {
    const page = await halaman(browser, asalDari(info.project.use.baseURL), lebar, tinggi)
    await page.goto('/sdm/pegawai?pegawai=')
    await expect(page.getByRole('heading', { level: 1, name: 'Data pegawai' })).toBeVisible()
    await page.getByRole('region', { name: 'Daftar pegawai' }).getByText('Contoh Admin SDM').first().click()
    await expect(page.getByRole('heading', { level: 2, name: 'Contoh Admin SDM' })).toBeVisible()
    await tanpaGulirSamping(page)
    await page.context().close()
  })
}
