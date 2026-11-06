import AxeBuilder from '@axe-core/playwright'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import { masukLewatApi, SANDI } from './bantu'

/**
 * Pendaftaran pasien baru → kunjungan poli dengan akun contoh.admisi (izin pendaftaran + lihat poli/jadwal/penjamin).
 * Pendaftaran butuh instalasi siap melayani, yang tidak bisa dicapai data sintetis lewat wizard; `lokal:kunjungan-contoh`
 * menyiapkan poli, dokter, dan jadwal sintetis lalu menandai siap, dan `--batal` mengembalikannya sesudah uji. Karena
 * penanda itu mengubah perilaku layar lain, proyek ini berjalan paling akhir (playwright.config.ts).
 * Hanya kolom wajib yang diisi: data sosial dan kelurahan memakai kode yang belum dari data referensi.
 */
const BACKEND = fileURLToPath(new URL('../../backend/', import.meta.url))
const artisan = (...arg: string[]) => execFileSync('php', ['artisan', 'lokal:kunjungan-contoh', ...arg], { cwd: BACKEND, stdio: 'pipe' })

test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  artisan()
})
test.afterAll(() => {
  artisan('--batal')
})

test('petugas admisi mencari pasien, mendaftarkan pasien baru, lalu mendaftarkan kunjungan poli', async ({ browser }, info) => {
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const masuk = await browser.newContext({ baseURL: asal })
  await masukLewatApi(masuk.request, asal, 'contoh.admisi')
  // Tanpa animasi: axe memeriksa keadaan akhir, bukan toast yang masih memudar masuk.
  const konteks = await browser.newContext({ baseURL: asal, storageState: await masuk.storageState(), viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' })
  await masuk.close()
  const page = await konteks.newPage()

  // Nama dan tanggal lahir unik per jalan agar tidak memicu peringatan pasien ganda.
  const akhiran = Date.now().toString(36).replace(/\d/g, (d) => 'abcdefghij'[Number(d)]!)
  const nama = `Uji Kunjungan ${akhiran}`
  const lahir = new Date(Date.UTC(1940, 0, 1) + (Date.now() % 23_000) * 86_400_000).toISOString().slice(0, 10)
  const [tahun, bulan, hari] = lahir.split('-')

  await page.goto('/pendaftaran/pasien')
  await page.getByRole('main').getByRole('searchbox', { name: 'Cari pasien' }).fill(`${nama} ${hari}-${bulan}-${tahun}`)
  await expect(page.getByText('Tidak ada pasien yang cocok.', { exact: false })).toBeVisible()

  await page.getByRole('button', { name: 'Pasien baru' }).click()
  const dialog = page.getByRole('dialog', { name: 'Daftarkan pasien baru' })
  await dialog.getByLabel('Nama lengkap').fill(nama)
  await dialog.getByRole('textbox', { name: 'Tanggal lahir' }).fill(lahir)
  await dialog.getByRole('combobox', { name: 'Jenis kelamin' }).selectOption('perempuan')
  await page.getByRole('button', { name: 'Daftarkan pasien' }).click()
  const toastPasien = page.getByText(/^Pasien terdaftar dengan No\. RM \d+\.$/)
  await expect(toastPasien).toBeVisible()
  const noRm = (await toastPasien.textContent())!.match(/\d+/)![0]
  await expect(page.getByRole('region', { name: 'Hasil pencarian pasien' })).toContainText(nama)

  await page.getByRole('link', { name: 'Pendaftaran kunjungan poli' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Pendaftaran kunjungan poli' })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: 'Poli Penyakit Dalam (contoh)' })
  await page.getByRole('region', { name: 'Jadwal praktik poliklinik' }).getByRole('row', { name: /Contoh Dokter Poli/ }).click()
  await page.getByRole('main').getByRole('searchbox', { name: 'Cari pasien' }).fill(noRm)
  await page.getByRole('button', { name: new RegExp(nama) }).click()
  await page.getByRole('radio', { name: 'Umum / non-JKN' }).check()
  await page.getByRole('button', { name: 'Daftarkan kunjungan' }).click()

  await expect(page.getByText(new RegExp(`^Kunjungan \\S+ terdaftar untuk ${nama}\\.$`))).toBeVisible()
  await expect(page.getByRole('region', { name: 'Papan kunjungan poliklinik' })).toContainText(nama)

  const hasil = await new AxeBuilder({ page }).analyze()
  expect(hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])
  await konteks.close()
})
