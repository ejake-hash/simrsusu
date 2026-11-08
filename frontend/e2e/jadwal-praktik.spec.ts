import { expect, test } from '@playwright/test'
import { artisan, halamanAkun, pelanggaranAxe, php, SANDI } from './bantu'

/**
 * Master Data → Jadwal praktik + cuti (tiket 2.4) ke backend asli sebagai data.induk, di poli `lokal:kunjungan-contoh`.
 * Dokternya Contoh Dokter (SIP sintetis di sini): dokter poli contoh sudah berjadwal 24 jam tiap hari, jadi selalu tumpang tindih.
 */
const ZONA = 'Asia/Jakarta'
const tanggal = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: ZONA }).format(d)
const HARI_INI = tanggal(new Date())
const CUTI = tanggal(new Date(Date.now() + 14 * 86_400_000))
const HARI = new Intl.DateTimeFormat('id-ID', { timeZone: ZONA, weekday: 'long' }).format(new Date(`${CUTI}T12:00:00+07:00`))

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  artisan()
  // SIP sintetis untuk Contoh Dokter; jadwal sisa jalan sebelumnya (bila gagal di tengah) dinonaktifkan agar tidak tumpang tindih.
  php('tinker', '--execute', "$p = DB::table('akses.pengguna')->where('nama_pengguna', 'dokter')->value('pegawai_id'); App\\Modules\\Sdm\\Models\\IzinPraktik::query()->updateOrCreate(['pegawai_id' => $p, 'jenis' => 'sip', 'nomor' => 'SIP/CONTOH/E2E'], ['terbit' => '2020-01-01', 'berlaku_sampai' => '2040-01-01', 'status' => 'aktif']); App\\Modules\\MasterData\\Models\\JadwalPraktik::query()->where('pegawai_id', $p)->update(['status' => 'nonaktif']);")
})
test.afterAll(() => {
  artisan('--batal')
})

test('tambah jadwal, cuti membuat slot tidak tersedia, batal cuti, nonaktifkan', async ({ browser, baseURL }) => {
  const page = await halamanAkun(browser, new URL(baseURL ?? '').origin, 'data.induk')
  await page.goto('/master-data/jadwal-praktik')
  await expect(page.getByRole('heading', { name: 'Jadwal praktik dan kuota poli', level: 1 })).toBeVisible()

  await page.getByRole('button', { name: 'Tambah jadwal' }).click()
  const jadwal = page.getByRole('dialog', { name: 'Tambah jadwal praktik' })
  await jadwal.getByRole('combobox', { name: 'Dokter' }).fill('Contoh Dokter')
  await jadwal.getByRole('option', { name: /^Contoh Dokter SINTETIS-/ }).click()
  await jadwal.getByLabel('Poliklinik').selectOption({ label: 'POLI-PD · Poli Penyakit Dalam (contoh)' })
  await jadwal.getByLabel('Hari').selectOption({ label: HARI })
  await jadwal.getByLabel('Jam mulai').fill('08:00')
  await jadwal.getByLabel('Jam selesai').fill('12:00')
  await jadwal.getByLabel('Kuota JKN').fill('10')
  await jadwal.getByLabel('Kuota non-JKN').fill('5')
  await jadwal.getByLabel('Berlaku dari').fill(HARI_INI)
  await jadwal.getByLabel('Dasar perubahan').fill('Surat tugas uji e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await jadwal.getByRole('button', { name: 'Tambah jadwal' }).click()
  await expect(jadwal).toHaveCount(0)

  await page.getByRole('textbox', { name: 'Tanggal' }).fill(CUTI)
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: 'Poli Penyakit Dalam (contoh)' })
  const ubah = page.getByRole('button', { name: `Ubah jadwal Contoh Dokter ${HARI}`, exact: true })
  const baris = page.getByRole('row').filter({ has: ubah })
  await expect(baris).toContainText('08:00–12:00')
  await expect(baris).toContainText('Tersedia')

  await page.getByRole('button', { name: 'Catat cuti atau libur' }).click()
  const cuti = page.getByRole('dialog', { name: 'Catat cuti atau libur' })
  await cuti.getByLabel('Jenis').selectOption('cuti')
  await cuti.getByRole('combobox', { name: 'Dokter' }).fill('Contoh Dokter')
  await cuti.getByRole('option', { name: /^Contoh Dokter SINTETIS-/ }).click()
  await cuti.getByLabel('Dari tanggal').fill(CUTI)
  await cuti.getByLabel('Sampai tanggal').fill(CUTI)
  await cuti.getByLabel('Alasan').fill('Surat cuti uji e2e')
  await cuti.getByRole('button', { name: 'Catat' }).click()
  await expect(cuti).toHaveCount(0)
  await expect(baris).toContainText('Dokter cuti')

  await baris.getByRole('button', { name: `Batalkan cuti ${CUTI}` }).click()
  const batal = page.getByRole('dialog', { name: 'Batalkan pengecualian' })
  await batal.getByLabel('Alasan pembatalan').fill('Cuti dibatalkan (uji e2e)')
  await batal.getByRole('button', { name: 'Batalkan pengecualian' }).click()
  await expect(batal).toHaveCount(0)
  await expect(baris).toContainText('Tersedia')
  expect(await pelanggaranAxe(page)).toEqual([])

  await baris.getByRole('button', { name: `Nonaktifkan jadwal Contoh Dokter ${HARI}`, exact: true }).click()
  const nonaktif = page.getByRole('dialog', { name: 'Nonaktifkan jadwal praktik' })
  await nonaktif.getByLabel('Dasar perubahan').fill('Selesai uji e2e')
  await nonaktif.getByRole('button', { name: 'Nonaktifkan' }).click()
  await expect(nonaktif).toHaveCount(0)
  await expect(baris).toHaveCount(0)
  await page.context().close()
})
