import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { masukLewatApi, SANDI, tanpaGulirSamping } from './bantu'

/**
 * Master Data → Organisasi, Unit, Ruang & Tempat Tidur (tiket 1.8a) ke backend asli dengan akun sintetis contoh.data.induk (pemegang
 * master_data, bukan superadmin) dan data OrganisasiSintetisSeeder: IRJ (POLI-PD, POLI-ANAK), IRNA (RUANG-MELATI,
 * RUANG-MAWAR), IGD, FARMASI (DEPO-RJ); KSM-IPD/ANAK/BEDAH. Unit baru memakai kode unik per run.
 */
type Sesi = Awaited<ReturnType<BrowserContext['storageState']>>
let sesi: Sesi | undefined
const AKHIRAN = Date.now().toString(36).toUpperCase()

test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'lebar layar diatur per uji')
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
})

/** Satu kali masuk per berkas (rate limit masuk 10/menit per nama pengguna). */
async function halaman(browser: Browser, asal: string, lebar = 1440, tinggi = 900): Promise<Page> {
  if (!sesi) {
    const masuk = await browser.newContext({ baseURL: asal })
    await masukLewatApi(masuk.request, asal, 'contoh.data.induk')
    sesi = await masuk.storageState()
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesi, viewport: { width: lebar, height: tinggi } })
  return konteks.newPage()
}
const asalDari = (baseURL: string | undefined) => new URL(baseURL ?? '').origin
const pohon = (page: Page) => page.getByRole('tree', { name: 'Struktur unit organisasi' })
const simpul = (page: Page, nama: string) => pohon(page).getByRole('treeitem', { name: new RegExp(`^${nama}`) })
/** Klik label barisnya: titik tengah treeitem yang terbuka bisa jatuh di baris anak. */
const klikSimpul = (page: Page, nama: string) => pohon(page).getByText(nama, { exact: true }).click()
const dialog = (page: Page) => page.getByRole('dialog').or(page.getByRole('alertdialog'))

async function seriusAtauKritis(page: Page): Promise<string[]> {
  const hasil = await new AxeBuilder({ page }).analyze()
  return hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').flatMap((v) => v.nodes.map((n) => `${v.id}: ${n.target.join(' ')}`))
}

test('pohon unit muat-lambat, rincian, tambah sub-unit, dan nonaktif ditolak selagi punya anak aktif', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/master-data/organisasi')
  await expect(page.getByRole('heading', { level: 1, name: 'Organisasi, Unit, Ruang & Tempat Tidur' })).toBeVisible()
  await expect(simpul(page, 'Instalasi Rawat Inap \\(contoh\\)')).toBeVisible()
  await expect(simpul(page, 'Ruang Melati')).toHaveCount(0)

  // Keyboard: Enter memilih, panah kanan membuka dan memuat anaknya.
  await simpul(page, 'Instalasi Rawat Inap \\(contoh\\)').focus()
  await page.keyboard.press('Enter')
  await page.keyboard.press('ArrowRight')
  await expect(simpul(page, 'Ruang Melati \\(contoh\\)')).toBeVisible()
  await expect(page).toHaveURL(/\?unit=/)
  const rincian = page.getByRole('region', { name: 'Instalasi Rawat Inap (contoh)' })
  await expect(rincian.getByText('Sub-unit langsung')).toBeVisible()

  // Tambah sub-unit di IRNA.
  await rincian.getByRole('button', { name: 'Sub-unit' }).click()
  await expect(dialog(page).getByRole('heading', { name: 'Tambah sub-unit di IRNA' })).toBeVisible()
  await expect(dialog(page).getByLabel('Unit induk')).toHaveValue('IRNA · Instalasi Rawat Inap (contoh)')
  await dialog(page).getByLabel('Kode').fill(`ruang-e2e-${AKHIRAN}`)
  await expect(dialog(page).getByLabel('Kode')).toHaveValue(`RUANG-E2E-${AKHIRAN}`)
  await dialog(page).getByLabel('Jenis').selectOption('ruang')
  await dialog(page).getByLabel('Nama unit').fill(`Ruang Uji E2E ${AKHIRAN}`)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await expect(await seriusAtauKritis(page)).toEqual([])
  await dialog(page).getByRole('button', { name: 'Tambah unit' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 2, name: `Ruang Uji E2E ${AKHIRAN}` })).toBeVisible()
  await expect(simpul(page, `Ruang Uji E2E ${AKHIRAN}`)).toHaveAttribute('aria-selected', 'true')

  // Kode ganda → pesan di kolom kode.
  await page.getByRole('button', { name: 'Sub-unit' }).click()
  await dialog(page).getByLabel('Kode').fill('IRNA')
  await dialog(page).getByLabel('Jenis').selectOption('ruang')
  await dialog(page).getByLabel('Nama unit').fill('Duplikat')
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await dialog(page).getByRole('button', { name: 'Tambah unit' }).click()
  await expect(dialog(page).getByText(/sudah dipakai/)).toBeVisible()
  await dialog(page).getByRole('button', { name: 'Batal' }).click()

  // IRNA masih punya anak aktif → 409 dengan rincian.
  await klikSimpul(page, 'Instalasi Rawat Inap (contoh)')
  await expect(page.getByRole('heading', { level: 2, name: 'Instalasi Rawat Inap (contoh)' })).toBeVisible()
  await page.getByRole('button', { name: 'Nonaktifkan' }).click()
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await dialog(page).getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(dialog(page).getByText(/unit anak masih aktif/)).toBeVisible()
  await dialog(page).getByRole('button', { name: 'Batal' }).click()

  // Unit uji dinonaktifkan agar run berikutnya tidak menumpuk unit aktif.
  await page.getByRole('searchbox', { name: 'Cari unit' }).fill(`RUANG-E2E-${AKHIRAN}`)
  await page.getByRole('list', { name: 'Hasil pencarian unit' }).getByRole('button').first().click()
  await expect(page.getByRole('heading', { level: 2, name: `Ruang Uji E2E ${AKHIRAN}` })).toBeVisible()
  await page.getByRole('button', { name: 'Nonaktifkan' }).click()
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Direktur uji e2e')
  await dialog(page).getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(page.getByRole('region', { name: `Ruang Uji E2E ${AKHIRAN}` }).getByText('Nonaktif', { exact: true })).toBeVisible()
  await page.context().close()
})

test('tab KSM: daftar dari seeder, cari, ubah dengan dasar perubahan', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/master-data/organisasi?tab=ksm')
  const tabel = page.getByRole('region', { name: 'Daftar KSM' })
  await expect(tabel.getByText('KSM-IPD')).toBeVisible()
  await page.getByRole('searchbox', { name: 'Cari KSM' }).fill('bedah')
  await expect(tabel.getByRole('row')).toHaveCount(2)
  await tabel.getByRole('button', { name: /^Ubah KSM Bedah/ }).click()
  const nama = `KSM Bedah (contoh) ${AKHIRAN}`
  await dialog(page).getByLabel('Nama KSM').fill(nama)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Komite Medik uji e2e')
  await dialog(page).getByRole('button', { name: 'Simpan' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(tabel.getByText(nama)).toBeVisible()
  // Kembalikan nama agar run berikutnya tetap menemukan "bedah".
  await tabel.getByRole('button', { name: `Ubah ${nama}` }).click()
  await dialog(page).getByLabel('Nama KSM').fill('KSM Bedah (contoh)')
  await dialog(page).getByLabel('Dasar perubahan').fill('SK Komite Medik uji e2e')
  await dialog(page).getByRole('button', { name: 'Simpan' }).click()
  await expect(dialog(page)).toHaveCount(0)
  await expect(await seriusAtauKritis(page)).toEqual([])
  await page.context().close()
})

for (const [lebar, tinggi] of [
  [1024, 768],
  [390, 844],
] as const) {
  test(`${lebar} px: organisasi tanpa gulir samping dan tanpa pelanggaran axe serius/kritis`, async ({ browser }, info) => {
    const page = await halaman(browser, asalDari(info.project.use.baseURL), lebar, tinggi)
    await page.goto('/master-data/organisasi')
    await klikSimpul(page, 'Instalasi Rawat Jalan (contoh)')
    await expect(page.getByRole('heading', { level: 2, name: 'Instalasi Rawat Jalan (contoh)' })).toBeInViewport()
    await tanpaGulirSamping(page)
    await expect(await seriusAtauKritis(page)).toEqual([])
    await page.context().close()
  })
}

test('akun tanpa master_data: Akses ditolak (1.2d) dan daftar unit ditolak server', async ({ browser }, info) => {
  const asal = asalDari(info.project.use.baseURL)
  const masuk = await browser.newContext({ baseURL: asal, storageState: { cookies: [], origins: [] } })
  await masukLewatApi(masuk.request, asal, 'contoh.dokter')
  const page = await masuk.newPage()
  await page.goto('/master-data/organisasi')
  await expect(page.getByRole('heading', { level: 1, name: 'Akses ditolak' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Minta akses' })).toBeVisible()
  expect((await masuk.request.get('/api/v1/unit-organisasi?akar=true', { headers: { Accept: 'application/json', Origin: asal, Referer: `${asal}/` } })).status()).toBe(403)
  await masuk.close()
})

// ── Tiket 1.8b: lokasi fisik, kamar, TT, bangsal, kelas rawat, impor CSV ──
// Kode unik per run (kode lokasi 1–10 huruf besar/angka), karena kode TT tidak pernah dipakai ulang.
const SEG = `E${Date.now().toString(36).toUpperCase().slice(-6)}`

async function pilihOpsi(page: Page, label: string, ketik: string, opsi: RegExp) {
  const isian = dialog(page).getByRole('combobox', { name: label })
  await isian.fill(ketik)
  await dialog(page).getByRole('option', { name: opsi }).first().click()
}

test('1440 px: kelas rawat, bangsal, gedung → lantai → ruang, jadikan kamar, TT melebihi kapasitas, tutup TT, kode TT tidak dipakai ulang', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  await page.goto('/master-data/organisasi?tab=kelas')
  await page.getByRole('button', { name: 'Tambah kelas' }).click()
  await dialog(page).getByRole('textbox', { name: 'Kode', exact: true }).fill(`K${SEG}`)
  await dialog(page).getByLabel('Nama kelas').fill(`Kelas Uji ${SEG}`)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e 1.8b')
  await dialog(page).getByRole('button', { name: 'Tambah kelas' }).click()
  await expect(dialog(page)).toHaveCount(0)

  await page.getByRole('tab', { name: 'Bangsal' }).click()
  await page.getByRole('button', { name: 'Tambah bangsal' }).click()
  await dialog(page).getByRole('textbox', { name: 'Kode', exact: true }).fill(`B${SEG}`)
  await dialog(page).getByLabel('Nama bangsal').fill(`Bangsal Uji ${SEG}`)
  await pilihOpsi(page, 'Unit pengelola', 'IRNA', /IRNA/)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e 1.8b')
  await dialog(page).getByRole('button', { name: 'Tambah bangsal' }).click()
  await expect(dialog(page)).toHaveCount(0)

  await page.getByRole('tab', { name: 'Lokasi & tempat tidur' }).click()
  const tambahLokasi = async (tombol: string, jenis: string | null, kode: string, nama: string, fungsi?: string) => {
    await page.getByRole('button', { name: tombol }).click()
    if (jenis) await dialog(page).getByLabel('Jenis').selectOption(jenis)
    await dialog(page).getByRole('textbox', { name: 'Kode', exact: true }).fill(kode)
    if (fungsi) await dialog(page).getByLabel('Fungsi ruang').selectOption(fungsi)
    await dialog(page).getByRole('textbox', { name: 'Nama', exact: true }).fill(nama)
    await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e 1.8b')
    await dialog(page).getByRole('button', { name: 'Tambah', exact: true }).click()
    await expect(dialog(page)).toHaveCount(0)
    await expect(page.getByRole('heading', { level: 2, name: nama })).toBeVisible()
  }
  await tambahLokasi('Gedung/situs', 'gedung', SEG, `Gedung Uji ${SEG}`)
  await tambahLokasi('Tambah di bawah', 'lantai', '1', 'Lantai 1')
  await tambahLokasi('Tambah di bawah', null, '101', `Ruang Uji ${SEG}`, 'rawat_inap')
  await expect(page.getByRole('region', { name: `Ruang Uji ${SEG}` })).toContainText(`${SEG}-1-101`)

  await page.getByRole('button', { name: 'Jadikan kamar' }).click()
  await dialog(page).getByLabel('Bangsal').selectOption({ label: `B${SEG} · Bangsal Uji ${SEG}` })
  await dialog(page).getByLabel('Kapasitas maksimum TT').fill('1')
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e 1.8b')
  await dialog(page).getByRole('button', { name: 'Jadikan kamar' }).click()
  await expect(dialog(page)).toHaveCount(0)

  const tambahTt = async (kode: string) => {
    await page.getByRole('button', { name: 'Tambah TT' }).click()
    await dialog(page).getByLabel('Kode TT').fill(kode)
    await dialog(page).getByLabel('Kelas rawat').selectOption({ label: `K${SEG} · Kelas Uji ${SEG}` })
    await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e 1.8b')
    await dialog(page).getByRole('button', { name: 'Tambah TT' }).click()
  }
  await tambahTt('1')
  await expect(page.getByText(`TT ${SEG}-1-101-1 disimpan.`)).toBeVisible()
  await tambahTt('2')
  await expect(page.getByText(`TT ${SEG}-1-101-2 disimpan; jumlah TT kini melebihi kapasitas maksimum kamar.`)).toBeVisible()
  await expect(page.getByText('Melebihi kapasitas')).toBeVisible()

  // Tutup TT 2 mulai besok: hari ini masih dihitung (akan ditutup).
  await page.getByRole('button', { name: `Tutup TT ${SEG}-1-101-2` }).click()
  const tutup = page.getByRole('alertdialog', { name: `Tutup TT ${SEG}-1-101-2?` })
  await expect(tutup).toContainText('TT masih dihitung sampai')
  await tutup.getByLabel('Alasan penutupan').fill('Uji penutupan e2e')
  await tutup.getByLabel('Dasar perubahan').fill('SK uji e2e 1.8b')
  await tutup.getByRole('button', { name: 'Tutup TT' }).click()
  await expect(page.getByText(`TT ${SEG}-1-101-2 dijadwalkan tutup`)).toBeVisible()
  await expect(page.getByRole('listitem').filter({ hasText: `${SEG}-1-101-2` }).getByText('Akan ditutup')).toBeVisible()

  // Kode TT yang sudah ditutup/dijadwalkan tidak bisa dipakai untuk TT baru.
  await tambahTt('2')
  await expect(dialog(page).getByText(/pernah dipakai|sudah dipakai/)).toBeVisible()
  await dialog(page).getByRole('button', { name: 'Batal' }).click()

  // Ringkasan bangsal: 2 dihitung hari ini (1 akan ditutup); kamar melebihi kapasitas tampil sebagai peringatan.
  await page.getByRole('tab', { name: 'Bangsal' }).click()
  await page.getByRole('searchbox', { name: 'Cari bangsal' }).fill(`B${SEG}`)
  const baris = page.getByRole('region', { name: 'Daftar bangsal' }).getByRole('row').filter({ hasText: `Bangsal Uji ${SEG}` })
  await expect(baris).toContainText(`Kelas Uji ${SEG}: 2 dihitung · 1 akan ditutup`)
  await expect(page.getByRole('region', { name: /kamar melebihi kapasitas maksimum/ })).toContainText(`${SEG}-1-101`)
  await expect(await seriusAtauKritis(page)).toEqual([])
  await page.context().close()
})

test('impor CSV: pratinjau lalu terapkan (gedung → lantai → ruang+kamar → 4 TT); berkas dengan duplikat dan induk tak dikenal ditolak per baris', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL))
  const g = `I${SEG.slice(1)}`
  await page.goto('/master-data/organisasi?tab=kelas')
  await page.getByRole('button', { name: 'Tambah kelas' }).click()
  await dialog(page).getByRole('textbox', { name: 'Kode', exact: true }).fill(`KI${SEG}`)
  await dialog(page).getByLabel('Nama kelas').fill(`Kelas Impor ${SEG}`)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e impor')
  await dialog(page).getByRole('button', { name: 'Tambah kelas' }).click()
  await page.getByRole('tab', { name: 'Bangsal' }).click()
  await page.getByRole('button', { name: 'Tambah bangsal' }).click()
  await dialog(page).getByRole('textbox', { name: 'Kode', exact: true }).fill(`BI${SEG}`)
  await dialog(page).getByLabel('Nama bangsal').fill(`Bangsal Impor ${SEG}`)
  await pilihOpsi(page, 'Unit pengelola', 'IRNA', /IRNA/)
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e impor')
  await dialog(page).getByRole('button', { name: 'Tambah bangsal' }).click()
  await expect(dialog(page)).toHaveCount(0)

  const judul = 'jenis,kode,induk,nama,fungsi,unit,bangsal,kelas_rawat,kapasitas_maksimum,ekstra'
  const benar = [judul, `gedung,${g},,Gedung Impor ${SEG},,,,,,`, `lantai,2,${g},Lantai 2,,,,,,`, `ruang,201,${g}-2,Ruang Impor,rawat_inap,,BI${SEG},,4,`,
    ...['1', '2', '3', '4'].map((k) => `tempat_tidur,${k},${g}-2-201,,,,,KI${SEG},,tidak`)].join('\n')
  await page.getByRole('tab', { name: 'Lokasi & tempat tidur' }).click()
  await page.getByRole('button', { name: 'Impor CSV' }).click()
  await dialog(page).getByLabel('Berkas CSV').setInputFiles({ name: 'impor.csv', mimeType: 'text/csv', buffer: Buffer.from(benar) })
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e impor')
  await dialog(page).getByRole('button', { name: 'Periksa berkas' }).click()
  await expect(dialog(page).getByText('Semua 7 baris benar dan siap diterapkan.')).toBeVisible()
  await dialog(page).getByRole('button', { name: 'Terapkan' }).click()
  await expect(page.getByText('Impor diterapkan: 7 baris (4 TT, 1 kamar).')).toBeVisible()
  await page.getByRole('searchbox', { name: 'Cari lokasi' }).fill(`${g}-2-201-4`)
  await expect(page.getByRole('list', { name: 'Hasil pencarian lokasi' })).toContainText(`${g}-2-201-4`)

  // Duplikat di berkas dan induk tak dikenal: ditolak per baris, tidak ada yang disimpan.
  const salah = [judul, `gedung,X${SEG.slice(1)},,Gedung Salah,,,,,,`, `gedung,X${SEG.slice(1)},,Gedung Salah Ganda,,,,,,`, `lantai,1,TIDAKADA,Lantai,,,,,,`].join('\n')
  await page.getByRole('button', { name: 'Impor CSV' }).click()
  await dialog(page).getByLabel('Berkas CSV').setInputFiles({ name: 'salah.csv', mimeType: 'text/csv', buffer: Buffer.from(salah) })
  await dialog(page).getByLabel('Dasar perubahan').fill('SK uji e2e impor')
  await dialog(page).getByRole('button', { name: 'Periksa berkas' }).click()
  const tabel = dialog(page).getByRole('region', { name: 'Baris yang salah' })
  // Judul + baris salah (duplikat dan induk tak dikenal).
  await expect(tabel.getByRole('row')).toHaveCount(4)
  await expect(tabel).toContainText('induk')
  await expect(dialog(page).getByRole('button', { name: 'Terapkan' })).toBeDisabled()
  await dialog(page).getByRole('button', { name: 'Batal' }).click()
  await page.context().close()
})

test('1024 px: pohon lokasi dan panel rinci berdampingan tanpa gulir samping halaman', async ({ browser }, info) => {
  const page = await halaman(browser, asalDari(info.project.use.baseURL), 1024, 768)
  await page.goto('/master-data/organisasi?tab=lokasi')
  await expect(page.getByRole('heading', { level: 2, name: 'Lokasi fisik' })).toBeVisible()
  const pohon = await page.getByRole('region', { name: 'Lokasi fisik' }).boundingBox()
  const kosong = await page.getByRole('heading', { level: 2, name: 'Pilih lokasi' }).boundingBox()
  expect(pohon && kosong && kosong.x > pohon.x + pohon.width - 1).toBe(true)
  await tanpaGulirSamping(page)
  await expect(await seriusAtauKritis(page)).toEqual([])
  await page.context().close()
})
