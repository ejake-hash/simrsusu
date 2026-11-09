import { expect, test } from '@playwright/test'
import { artisan, halamanAdmisi, halamanAkun, headerTulis, pelanggaranAxe, php, SANDI, tungguJatahPenuh } from './bantu'

/**
 * 2.23-fe: Pendaftaran IGD ke backend asli @1440 px + axe. Akun `perawat` (peran contoh_perawat: seluruh menu IGD, lihat
 * pasien dan penjamin). Pasien SINTETIS "Uji IGD …" didaftarkan admisi lewat API (perawat tidak boleh membuat pasien),
 * lalu perawat mendaftarkannya ke IGD dan mencoba lagi (409 kunjungan_igd_aktif). Registrasi butuh instalasi siap
 * melayani (`lokal:kunjungan-contoh`), jadi berjalan sesudah anjungan (playwright.config.ts). beforeAll dan afterAll
 * membatalkan kunjungan IGD aktif pasien uji agar jalan ulang tidak kena 409.
 */
const akhiran = Date.now().toString(36).replace(/\d/g, (d) => 'abcdefghij'[Number(d)]!)
const nama = `Uji IGD ${akhiran}`

/** Batalkan kunjungan IGD aktif pasien uji (sisa jalan sebelumnya yang gagal, atau jalan ini) agar tidak kena 409. */
const bersihkanIgdUji = () =>
  php('tinker', '--execute', "$u = DB::table('akses.pengguna')->value('id'); DB::table('kunjungan.kunjungan')->where('jenis_kunjungan', 'igd')->whereIn('status', ['terdaftar', 'dilayani'])->whereIn('pasien_id', DB::table('pasien.pasien')->where('nama', 'like', 'Uji IGD %')->pluck('id'))->update(['status' => 'batal', 'alasan_batal' => 'Bersih uji e2e', 'dibatalkan_oleh' => $u, 'dibatalkan_pada' => now()]);")

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  artisan()
  bersihkanIgdUji()
})
test.afterAll(() => {
  try {
    bersihkanIgdUji()
  } finally {
    artisan('--batal')
  }
})

test('perawat mendaftarkan pasien ke IGD; daftar IGD aktif; pendaftaran ulang ditolak dengan penanda', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin

  // Pasien sintetis lewat API admisi; tanggal lahir acak agar tidak memicu peringatan pasien ganda.
  const { konteks, page: admisi } = await halamanAdmisi(browser, asal)
  await tungguJatahPenuh(konteks.request, asal)
  await admisi.goto('/')
  const lahir = new Date(Date.UTC(1940, 0, 1) + (Date.now() % 23_000) * 86_400_000).toISOString().slice(0, 10)
  const r = await admisi.request.post('/api/v1/pasien', { data: { nama, tanggal_lahir: lahir, jenis_kelamin: 'perempuan' }, headers: await headerTulis(admisi, asal) })
  expect(r.status(), await r.text()).toBe(201)
  const noRm = ((await r.json()) as { data: { no_rm: string } }).data.no_rm
  await konteks.close()

  const page = await halamanAkun(browser, asal, 'perawat')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/igd/pendaftaran')
  await expect(page.getByRole('heading', { level: 1, name: 'Pendaftaran IGD' })).toBeVisible()
  const daftarkan = page.getByRole('button', { name: 'Daftarkan ke IGD' })
  await expect(daftarkan).toBeDisabled()

  await page.getByRole('main').getByRole('searchbox', { name: 'Cari pasien' }).fill(noRm)
  await page.getByRole('button', { name: new RegExp(nama) }).click()
  // Pasien tanpa No. kartu BPJS: cara bayar non-JKN terpilih otomatis.
  await expect(page.getByRole('radio', { name: 'Umum / non-JKN' })).toBeChecked()
  await page.getByRole('textbox', { name: /Keluhan utama/ }).fill('Nyeri dada sejak pagi (uji sintetis)')
  expect(await pelanggaranAxe(page)).toEqual([])
  await daftarkan.click()

  await expect(page.getByText(new RegExp(`^${nama} terdaftar di IGD: IGD-\\d{8}-\\d{4}, tiba \\d{2}\\.\\d{2} WIB\\.$`))).toBeVisible()
  const tabel = page.getByRole('region', { name: 'Tabel pasien di IGD' })
  const baris = tabel.getByRole('row', { name: new RegExp(nama) })
  await expect(baris).toContainText('Terdaftar')
  await expect(baris).toContainText(`No. RM`)
  await expect(baris).toContainText(/\d+ menit/)
  await expect(baris).toContainText(/IGD-\d{8}-\d{4}/)
  expect(await pelanggaranAxe(page)).toEqual([])

  // Pasien yang sama masih di IGD → 409 kunjungan_igd_aktif: peringatan dan baris aktifnya ditandai.
  await page.getByRole('main').getByRole('searchbox', { name: 'Cari pasien' }).fill(noRm)
  await page.getByRole('button', { name: new RegExp(nama) }).click()
  await daftarkan.click()
  await expect(page.getByText('Pasien ini masih tercatat di IGD', { exact: false })).toBeVisible()
  await expect(tabel.getByRole('row', { name: /Kunjungan aktif pasien ini/ })).toContainText(nama)
  await expect(tabel.getByRole('row', { name: new RegExp(nama) })).toHaveCount(1)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})

test('akun tanpa menu IGD melihat Akses ditolak di /igd/pendaftaran', async ({ browser }, info) => {
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page } = await halamanAdmisi(browser, asal)
  await page.goto('/igd/pendaftaran')
  await expect(page.getByRole('heading', { level: 1, name: 'Akses ditolak' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Daftarkan ke IGD' })).toHaveCount(0)
  await konteks.close()
})
