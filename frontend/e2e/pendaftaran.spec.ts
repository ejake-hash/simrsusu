import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, circuitBreaker, daftarkanKunjunganJkn, halamanAdmisi, masukLewatApi, pastikanPasienBpjs, php, SANDI, simulatorVclaim } from './bantu'

/**
 * Pendaftaran pasien baru → kunjungan poli dengan akun contoh.admisi (izin pendaftaran + lihat poli/jadwal/penjamin).
 * Pendaftaran butuh instalasi siap melayani, yang tidak bisa dicapai data sintetis lewat wizard; `lokal:kunjungan-contoh`
 * menyiapkan poli, dokter, dan jadwal sintetis lalu menandai siap, dan `--batal` mengembalikannya sesudah uji. Karena
 * penanda itu mengubah perilaku layar lain, proyek ini berjalan paling akhir (playwright.config.ts).
 * Hanya kolom wajib yang diisi: data sosial dan kelurahan memakai kode yang belum dari data referensi.
 */
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  artisan()
})
test.afterAll(() => {
  simulatorVclaim('normal')
  circuitBreaker('tertutup')
  bersihkanPesertaSintetis()
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

  const hasilPasien = await new AxeBuilder({ page }).analyze()
  expect(hasilPasien.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])

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

test('SEP: rujukan kedaluwarsa ditolak, hampir habis ditandai, galat BPJS tampil apa adanya, BPJS tak terjangkau → simpan dulu', async ({ browser }, info) => {
  test.setTimeout(90_000)
  const { konteks, page } = await halamanAdmisi(browser, new URL(info.project.use.baseURL ?? '').origin)
  const akhiran = Date.now().toString(36).replace(/\d/g, (d) => 'abcdefghij'[Number(d)]!)
  const kartuSintetis = '9990000000001' // satu-satunya peserta yang diterima simulator VClaim
  const kartuLain = `9991${String(Date.now() % 1e9).padStart(9, '0')}`
  const namaSintetis = 'Peserta Sintetis BPJS'
  const namaLain = `Uji SEP ${akhiran}`

  // Kunjungan 1: rujukan kedaluwarsa ditolak, hampir habis ditandai lalu SEP terbit.
  await pastikanPasienBpjs(page, namaSintetis, kartuSintetis)
  let panel = await daftarkanKunjunganJkn(page, namaSintetis, kartuSintetis)
  await panel.getByRole('textbox', { name: 'No. kartu BPJS atau NIK' }).fill(kartuSintetis)
  await panel.getByRole('button', { name: 'Periksa kepesertaan' }).click()
  await expect(panel.getByText('Peserta aktif')).toBeVisible()

  await panel.getByRole('textbox', { name: /Nomor rujukan/ }).fill('SIMLEWAT001')
  await panel.getByRole('button', { name: 'Periksa rujukan' }).click()
  await expect(panel.getByText('SEP tidak dapat diterbitkan', { exact: false })).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Terbitkan SEP', exact: true })).toBeDisabled()

  await panel.getByRole('textbox', { name: /Nomor rujukan/ }).fill('SIMHABIS001')
  await panel.getByRole('button', { name: 'Periksa rujukan' }).click()
  await expect(panel.getByText('Sisa masa berlaku rujukan 5 hari', { exact: false })).toBeVisible()
  await panel.getByRole('button', { name: 'Terbitkan SEP', exact: true }).click()
  await page.getByRole('dialog', { name: 'Terbitkan SEP' }).getByRole('button', { name: 'Terbitkan SEP' }).click()
  await expect(panel.getByText(/^SEP terbit\. No\. SEP /)).toBeVisible()
  await expect(panel.getByText('(sisa 5 hari)', { exact: false })).toBeVisible()
  const axe = await new AxeBuilder({ page }).analyze()
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([])

  // Kunjungan 2 (pasien lain): BPJS menolak → kode dan pesan apa adanya, kunjungan tetap di papan.
  await pastikanPasienBpjs(page, namaLain, kartuLain)
  panel = await daftarkanKunjunganJkn(page, namaLain, kartuLain)
  await panel.getByRole('textbox', { name: /Nomor rujukan/ }).fill('SIMFKTP001')
  simulatorVclaim('tolak')
  await panel.getByRole('button', { name: 'Terbitkan SEP', exact: true }).click()
  await page.getByRole('dialog', { name: 'Terbitkan SEP' }).getByRole('button', { name: 'Terbitkan SEP' }).click()
  await expect(panel.getByRole('alert')).toContainText('Kode BPJS 401: Unauthorized')
  await expect(page.getByRole('region', { name: 'Papan kunjungan poliklinik' })).toContainText(namaLain)
  simulatorVclaim('normal')

  // Circuit breaker terbuka → tawaran "simpan, terbitkan SEP nanti"; tugas masuk kotak kerja.
  circuitBreaker('terbuka')
  await panel.getByRole('button', { name: 'Terbitkan SEP', exact: true }).click()
  await page.getByRole('dialog', { name: 'Terbitkan SEP' }).getByRole('button', { name: 'Terbitkan SEP' }).click()
  await panel.getByRole('button', { name: 'Simpan, terbitkan SEP nanti' }).click()
  await expect(panel.getByText('SEP tersimpan dan menunggu dikirim ke BPJS', { exact: false })).toBeVisible()
  expect(php('tinker', '--execute', `echo DB::table('inti.tugas')->where('status', 'terbuka')->whereIn('objek_id', DB::table('integrasi.sep')->whereIn('pasien_id', DB::table('pasien.pasien')->where('nama', '${namaLain}')->pluck('id'))->pluck('id'))->count();`)).toMatch(/[1-9]/)
  circuitBreaker('tertutup')

  await konteks.close()
})
