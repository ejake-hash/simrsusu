import { expect, test, type Page } from '@playwright/test'
import { halamanAkun, headerTulis, pelanggaranAxe, SANDI } from './bantu'

/**
 * Master Data → Buku tarif + usulan tarif (tiket 2.2) ke backend asli. contoh.data.induk mengusulkan tarif baru lewat
 * layar (harga per kelas, berlaku hari ini), contoh.usulan.verifikator memverifikasi, contoh.usulan.penyetuju
 * menyetujui (tiga orang berbeda, dijaga server), lalu tarif tampil di buku tarif dengan harganya. Kelas rawat dan
 * kode tarif unik per jalan.
 */
const SEG = Date.now().toString(36).toUpperCase().slice(-6)
const KELAS = `KT${SEG}`
const KODE = `TRF${SEG}`
const NAMA = `Konsultasi Contoh ${SEG}`
const HARI_INI = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date())
let usulanId = ''

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const dialog = (page: Page) => page.getByRole('dialog').or(page.getByRole('alertdialog'))
const rincian = (page: Page) => page.locator('section.rincian')

test('pengusul mengajukan tarif baru dari buku tarif', async ({ browser, baseURL }) => {
  const asal = asalDari(baseURL)
  const page = await halamanAkun(browser, asal, 'contoh.data.induk')
  await page.goto('/master-data/tarif')
  const kelas = await page.request.post('/api/v1/kelas-rawat', {
    headers: await headerTulis(page, asal),
    data: { kode: KELAS, nama: `Kelas Tarif ${SEG}`, dasar_perubahan: 'SK uji e2e 2.2' },
  })
  expect(kelas.ok(), `${kelas.status()} ${await kelas.text()}`).toBeTruthy()
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Buku tarif', level: 1 })).toBeVisible()

  await page.getByRole('button', { name: 'Usulkan perubahan tarif' }).click()
  const d = dialog(page)
  await d.getByLabel('Jenis perubahan').selectOption('tambah')
  await d.getByLabel('Kode tarif').fill(KODE)
  await d.getByLabel('Nama tarif').fill(NAMA)
  await d.getByLabel('Kelompok').fill('Rawat jalan')
  const harga = d.getByRole('group', { name: 'Harga baris 1' })
  await harga.getByLabel('Kelas rawat').selectOption({ label: `${KELAS} · Kelas Tarif ${SEG}` })
  await harga.getByLabel('Jasa sarana').fill('50000')
  await harga.getByLabel('Jasa pelayanan').fill('70000')
  await harga.getByLabel('BHP').fill('5000')
  await d.getByLabel('Berlaku mulai').fill(HARI_INI)
  await d.getByLabel('Dasar').fill('SK tarif uji e2e')
  await d.getByLabel('Alasan').fill(`Tarif baru ${SEG}`)
  expect(await pelanggaranAxe(page)).toEqual([])
  await d.getByRole('button', { name: 'Ajukan usulan' }).click()
  await expect(page.getByText(/diajukan; menunggu verifikasi\./)).toBeVisible()
  await expect(page).toHaveURL(/\/master-data\/usulan\?usulan=/)
  usulanId = new URL(page.url()).searchParams.get('usulan') ?? ''
  expect(usulanId).not.toBe('')
  await page.context().close()
})

test('diverifikasi dan disetujui orang lain, lalu tarif berlaku di buku tarif', async ({ browser, baseURL }) => {
  const asal = asalDari(baseURL)
  for (const [akun, aksi, pesan] of [
    ['contoh.usulan.verifikator', 'Verifikasi', /diverifikasi; diteruskan ke penyetuju\./],
    ['contoh.usulan.penyetuju', 'Setujui', /disetujui dan langsung berlaku\./],
  ] as const) {
    const page = await halamanAkun(browser, asal, akun)
    await page.goto(`/master-data/usulan?usulan=${usulanId}`)
    await rincian(page).getByRole('button', { name: aksi, exact: true }).click()
    await dialog(page).getByRole('button', { name: aksi, exact: true }).click()
    await expect(page.getByText(pesan)).toBeVisible()
    await page.context().close()
  }

  const page = await halamanAkun(browser, asal, 'contoh.data.induk')
  await page.goto('/master-data/tarif')
  await page.getByRole('searchbox', { name: 'Cari kode atau nama tarif' }).fill(KODE)
  const baris = page.getByRole('row', { name: new RegExp(KODE) })
  await expect(baris).toContainText(NAMA)
  await expect(baris).toContainText(/125\.000/)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})
