import AxeBuilder from '@axe-core/playwright'
import { expect, test, type APIRequestContext, type Browser, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Master Data → Usulan perubahan (tiket 1.8c) ke backend asli dengan tiga akun sintetis berbeda (AkunSintetisSeeder:
 * contoh.usulan.pengusul|verifikator|penyetuju, semua memegang semua aksi usulan; pemisahan tugas dijaga server).
 * Objek awal (gedung → lantai → ruang, kode unik per run) diimpor contoh.data.induk lewat API /lokasi/impor.
 * Alur: ajukan ubah nama ruang → dikembalikan dengan catatan → diperbaiki dan dikirim ulang → diverifikasi →
 * pengusul menyetujui sendiri ditolak 403 → penyetuju menyetujui → langsung berlaku (berlaku_dari = hari ini).
 */
const TANPA_SESI = { cookies: [], origins: [] }
const SEG = `U${Date.now().toString(36).toUpperCase().slice(-6)}`
const RUANG = `${SEG}-1-101`
/** Hari ini di zona RS (uji berjalan dengan timezoneId Asia/Jakarta). */
const HARI_INI = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date())
let usulanId = ''
let nomor = ''

test.describe.configure({ mode: 'serial' })
test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'alur berurutan antar-akun, cukup sekali')
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

/** Satu kali masuk per akun per berkas (rate limit masuk 10/menit per nama pengguna). */
const sesi = new Map<string, Awaited<ReturnType<APIRequestContext['storageState']>>>()
async function halaman(browser: Browser, asal: string, nama: string, lebar = 1440): Promise<Page> {
  if (!sesi.has(nama)) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: TANPA_SESI })
    await masukLewatApi(masuk.request, asal, nama)
    sesi.set(nama, await masuk.storageState())
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi.get(nama), viewport: { width: lebar, height: 900 } })
  return konteks.newPage()
}
const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const dialog = (page: Page) => page.getByRole('dialog').or(page.getByRole('alertdialog'))
/** Header tulis API (cookie Sanctum stateful + XSRF) dari konteks halaman yang sudah masuk. */
async function header(page: Page, asal: string) {
  const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  return { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', Origin: asal, Referer: `${asal}/` }
}
async function seriusAtauKritis(page: Page): Promise<string[]> {
  const hasil = await new AxeBuilder({ page }).analyze()
  return hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').flatMap((v) => v.nodes.map((n) => `${v.id}: ${n.target.join(' ')}`))
}
/** Rincian usulan uji ini; judul = ringkasan server ("Ubah 1 ruang"), jadi penandanya nomor usulan. */
const rincian = (page: Page) => page.locator('section.rincian')
async function bukaUsulan(page: Page) {
  await page.goto(`/master-data/usulan?usulan=${usulanId}`)
  await expect(rincian(page).getByRole('heading', { name: 'Ubah 1 ruang' })).toBeVisible()
  await expect(rincian(page).getByText(nomor, { exact: true })).toBeVisible()
}

test.beforeAll(async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const page = await halaman(browser, asal, 'contoh.data.induk')
  await page.goto('/')
  const csv = ['jenis,kode,induk,nama,fungsi,unit,bangsal,kelas_rawat,kapasitas_maksimum,ekstra', `gedung,${SEG},,Gedung Usulan ${SEG},,,,,,`,
    `lantai,1,${SEG},Lantai 1,,,,,,`, `ruang,101,${SEG}-1,Ruang Arsip ${SEG},administrasi,,,,,`].join('\n')
  const r = await page.request.post('/api/v1/lokasi/impor', {
    headers: await header(page, asal),
    multipart: { berkas: { name: 'usulan.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) }, mode: 'terapkan', dasar_perubahan: 'Data uji e2e usulan' },
  })
  expect(r.ok(), `${r.status()} ${await r.text()}`).toBeTruthy()
  await page.context().close()
})

test('pengusul mengajukan ubah ruang lewat layar; tidak ada tombol verifikasi/setujui untuk usulannya sendiri', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 'contoh.usulan.pengusul')
  await page.goto('/master-data/usulan')
  await expect(page.getByRole('heading', { name: 'Usulan perubahan data induk' })).toBeVisible()
  await page.getByRole('button', { name: 'Ajukan usulan' }).click()
  await dialog(page).getByLabel('Jenis perubahan').selectOption({ label: 'Ubah ruang' })
  await dialog(page).getByRole('combobox', { name: 'Ruang' }).fill(RUANG)
  await dialog(page).getByRole('option', { name: new RegExp(RUANG) }).first().click()
  const nama = dialog(page).getByLabel('Nama ruang')
  await expect(nama).toHaveValue(`Ruang Arsip ${SEG}`)
  await nama.fill(`Ruang Rekam Medis ${SEG}`)
  await dialog(page).getByLabel('Berlaku mulai').fill(HARI_INI)
  await dialog(page).getByLabel('Dasar').fill('Memo uji e2e')
  await dialog(page).getByLabel('Alasan').fill(`Penyesuaian nama ruang ${SEG}`)
  await dialog(page).getByRole('button', { name: 'Ajukan usulan', exact: true }).click()
  await expect(page.getByText(/diajukan; menunggu verifikasi\./)).toBeVisible()
  await expect(page).toHaveURL(/\?usulan=/)
  usulanId = new URL(page.url()).searchParams.get('usulan') ?? ''
  expect(usulanId).not.toBe('')

  const r = rincian(page)
  await expect(r.getByRole('heading', { name: 'Ubah 1 ruang' })).toBeVisible()
  nomor = (await r.locator('.nomor code').textContent())?.trim() ?? ''
  expect(nomor).not.toBe('')
  await expect(r.getByText('Menunggu verifikasi').first()).toBeVisible()
  await expect(r.getByRole('cell', { name: `Ruang Arsip ${SEG}` })).toBeVisible()
  await expect(r.getByRole('cell', { name: `Ruang Rekam Medis ${SEG}` })).toBeVisible()
  await expect(r.getByRole('button', { name: 'Verifikasi', exact: true })).toHaveCount(0)
  await expect(r.getByRole('button', { name: 'Setujui', exact: true })).toHaveCount(0)
  await expect(r.getByText('Pengusul, pemverifikasi, dan penyetuju harus tiga orang berbeda')).toBeVisible()
  expect(await seriusAtauKritis(page)).toEqual([])
})

test('pemverifikasi mengembalikan dengan catatan wajib; pengusul memperbaiki dan mengirim ulang (putaran 2)', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const verifikator = await halaman(browser, asal, 'contoh.usulan.verifikator')
  await verifikator.goto('/master-data/usulan')
  await verifikator.getByRole('radio', { name: 'Perlu saya' }).check()
  await expect(verifikator.getByRole('row', { name: new RegExp(`^${nomor} .*Menunggu verifikasi$`) })).toBeVisible()
  await bukaUsulan(verifikator)
  await rincian(verifikator).getByRole('button', { name: 'Kembalikan', exact: true }).click()
  // Catatan pengembalian wajib (ditolak di klien sebelum dikirim).
  await dialog(verifikator).getByRole('button', { name: 'Kembalikan', exact: true }).click()
  await expect(dialog(verifikator).getByLabel('Catatan pengembalian')).toHaveAttribute('aria-invalid', 'true')
  await dialog(verifikator).getByLabel('Catatan pengembalian').fill('Lengkapi nomor memo')
  await dialog(verifikator).getByRole('button', { name: 'Kembalikan', exact: true }).click()
  await expect(verifikator.getByText(/dikembalikan ke pengusul\./)).toBeVisible()

  const pengusul = await halaman(browser, asal, 'contoh.usulan.pengusul', 390)
  await bukaUsulan(pengusul)
  await expect(pengusul.getByText('“Lengkapi nomor memo”')).toBeVisible()
  await tanpaGulirSamping(pengusul)
  await rincian(pengusul).getByRole('button', { name: 'Perbaiki dan kirim ulang' }).click()
  await expect(dialog(pengusul).getByLabel('Nama ruang')).toHaveValue(`Ruang Rekam Medis ${SEG}`)
  await dialog(pengusul).getByLabel('Dasar').fill('Memo 12/2026 uji e2e')
  await dialog(pengusul).getByRole('button', { name: 'Kirim ulang' }).click()
  await expect(pengusul.getByText(/dikirim ulang \(putaran 2\) untuk verifikasi\./)).toBeVisible()
  await tanpaGulirSamping(pengusul)
})

test('diverifikasi; pengusul menyetujui sendiri → 403; penyetuju menyetujui → langsung berlaku', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const verifikator = await halaman(browser, asal, 'contoh.usulan.verifikator')
  await bukaUsulan(verifikator)
  await expect(rincian(verifikator).getByText(/putaran 2/).first()).toBeVisible()
  await rincian(verifikator).getByRole('button', { name: 'Verifikasi', exact: true }).click()
  await dialog(verifikator).getByRole('button', { name: 'Verifikasi', exact: true }).click()
  await expect(verifikator.getByText(/diverifikasi; diteruskan ke penyetuju\./)).toBeVisible()
  // Pemverifikasi tidak boleh juga menyetujui (tiga orang berbeda).
  await expect(rincian(verifikator).getByRole('button', { name: 'Setujui', exact: true })).toHaveCount(0)

  const pengusul = await halaman(browser, asal, 'contoh.usulan.pengusul')
  await bukaUsulan(pengusul)
  await expect(rincian(pengusul).getByRole('button', { name: 'Setujui', exact: true })).toHaveCount(0)
  const tolak = await pengusul.request.post(`/api/v1/usulan-data-induk/${usulanId}/setujui`, { headers: await header(pengusul, asal), data: { catatan: null } })
  expect(tolak.status()).toBe(403)

  const penyetuju = await halaman(browser, asal, 'contoh.usulan.penyetuju')
  await bukaUsulan(penyetuju)
  await rincian(penyetuju).getByRole('button', { name: 'Setujui', exact: true }).click()
  await dialog(penyetuju).getByRole('button', { name: 'Setujui', exact: true }).click()
  await expect(penyetuju.getByText(/disetujui dan langsung berlaku\./)).toBeVisible()
  await expect(rincian(penyetuju).locator('.kartu-kepala .pil')).toHaveText('Berlaku')
  await expect(rincian(penyetuju).getByRole('button', { name: /^(Verifikasi|Setujui|Kembalikan)$/ })).toHaveCount(0)
})
