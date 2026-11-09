import { expect, test } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, halamanAkun, headerTulis, masukLewatApi, pastikanPasienBpjs, pelanggaranAxe, php, SANDI, tungguJatahPenuh } from './bantu'

/**
 * Tiket 2.21-fe: CPPT berlabel 'psikiatri' ditulis dan disahkan perawat (PIN T1 sintetis) → `dokter.darurat` (MFA TOTP, SIP berlaku,
 * tanpa izin label) melihat "Terbatas", membuka akses darurat dengan alasan, lalu isinya terbaca → `rekam.medis` meninjau di
 * Rekam Medis → Tinjauan akses darurat (menunggu → sudah ditinjau). Berjalan sesudah rawat-jalan.spec (data contoh yang sama);
 * hanya 1440 px + axe. Pengulangan: akses darurat lama akun itu diakhiri (UPDATE berakhir_pada, bukan DELETE) agar akses baru dibuat.
 */
const kartu = '9990000000001'
const nama = 'Peserta Sintetis BPJS'
const poli = 'Poli Penyakit Dalam (contoh)'
const PIN = process.env.E2E_TTD_PIN ?? ''
const RAHASIA = process.env.E2E_DOKTER_DARURAT_MFA_RAHASIA ?? ''
const isiRahasia = `Isi terbatas uji ${Date.now() % 100000}`
const alasan = `Pasien datang tidak sadar, riwayat psikiatri dibutuhkan segera ${Date.now() % 100000}`
let nomor = ''
let kunjunganId = ''

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI || !PIN || !RAHASIA) throw new Error('Set E2E_SANDI, E2E_TTD_PIN, dan E2E_DOKTER_DARURAT_MFA_RAHASIA sesuai seeding.')
  bersihkanPesertaSintetis()
  artisan()
  php('tinker', '--execute', "DB::table('akses.akses_darurat')->whereIn('pengguna_id', DB::table('akses.pengguna')->where('nama_pengguna', 'dokter.darurat')->pluck('id'))->where('berakhir_pada', '>', now())->update(['berakhir_pada' => DB::raw(\"mulai_pada + interval '1 second'\")]);")
})
test.afterAll(() => {
  bersihkanPesertaSintetis()
  artisan('--batal')
})

test('perawat menulis dan mengesahkan CPPT berlabel psikiatri', async ({ browser }, info) => {
  test.setTimeout(150_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page: admisi } = await halamanAdmisi(browser, asal)
  await tungguJatahPenuh(konteks.request, asal)
  await pastikanPasienBpjs(admisi, nama, kartu)
  await daftarkanKunjunganJkn(admisi, nama, kartu)
  await konteks.close()

  const page = await halamanAkun(browser, asal, 'perawat')
  await page.goto('/rawat-jalan/cppt')
  const worklist = page.waitForResponse((r) => r.url().includes('/rawat-jalan/worklist') && r.ok())
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  const daftar = ((await (await worklist).json()) as { data: { kunjungan_id: string; nomor_tampil: string; pasien: { nama: string } }[] }).data
  const baris = daftar.find((b) => b.pasien.nama === nama)!
  expect(baris).toBeTruthy()
  nomor = baris.nomor_tampil
  kunjunganId = baris.kunjungan_id

  const header = await headerTulis(page, asal)
  const tulis = await page.request.post(`/api/v1/kunjungan/${kunjunganId}/cppt`, { headers: header, data: { subjektif: isiRahasia, objektif: null, asesmen: null, plan: null, label: 'psikiatri' } })
  expect(tulis.ok(), await tulis.text()).toBeTruthy()
  const cppt = ((await tulis.json()) as { data: { id: string } }).data
  const sah = await page.request.post(`/api/v1/cppt/${cppt.id}/sahkan`, {
    headers: { ...header, 'If-Match': tulis.headers().etag ?? '' },
    data: { pin: PIN, pernyataan_disetujui: true },
  })
  expect(sah.ok(), await sah.text()).toBeTruthy()
  // Dokter pemeriksa pasien ini diganti akun uji agar kunjungan tampil di worklist-nya (worklist dokter dibatasi dokter jadwal).
  php('tinker', '--execute', `DB::table('kunjungan.antrean')->where('kunjungan_id', '${kunjunganId}')->update(['pegawai_id' => DB::table('akses.pengguna')->where('nama_pengguna', 'dokter.darurat')->value('pegawai_id')]);`)
  await page.close()
})

test('dokter tanpa izin label melihat Terbatas, membuka akses darurat, lalu isinya terbaca', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const masuk = await browser.newContext({ baseURL: asal })
  await masukLewatApi(masuk.request, asal, 'dokter.darurat', RAHASIA)
  const konteks = await browser.newContext({ baseURL: asal, storageState: await masuk.storageState(), viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' })
  await masuk.close()
  const page = await konteks.newPage()

  await page.goto('/rawat-jalan/cppt')
  await expect(page.getByRole('heading', { name: 'CPPT episode', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  await page.getByRole('button', { name: `Buka CPPT ${nomor}` }).click()
  const cppt = page.getByRole('region', { name: 'CPPT', exact: true })
  await expect(cppt).toContainText(nama)
  await expect(cppt.getByRole('note', { name: /Terbatas/ }).first()).toBeVisible()
  await expect(cppt).not.toContainText(isiRahasia)
  expect(await pelanggaranAxe(page)).toEqual([])

  await cppt.getByRole('button', { name: 'Buka akses darurat' }).first().click()
  const dialog = page.getByRole('alertdialog', { name: 'Buka akses darurat' })
  await expect(dialog).toContainText('tercatat atas nama Anda')
  await dialog.getByRole('textbox', { name: /Uraian keadaan darurat/ }).fill('singkat')
  await dialog.getByRole('button', { name: 'Buka akses darurat' }).click()
  await expect(dialog).toContainText('minimal 10 karakter')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('combobox', { name: /Kategori alasan/ }).selectOption('pelayanan_lanjutan')
  await dialog.getByRole('textbox', { name: /Uraian keadaan darurat/ }).fill(alasan)
  await dialog.getByRole('button', { name: 'Buka akses darurat' }).click()
  await expect(dialog).toBeHidden()

  await expect(page.getByText(/Akses darurat terbuka/)).toBeVisible()
  await expect(cppt).toContainText(/Akses darurat aktif sampai/)
  // Tawaran hilang sesudah akses terbuka; fokus pindah ke pesan aktif, bukan jatuh ke body.
  await expect(cppt.locator('.pesan-aktif')).toBeFocused()
  await expect(cppt).toContainText(isiRahasia)
  expect(await pelanggaranAxe(page)).toEqual([])
  await konteks.close()
})

test('petugas rekam medis meninjau akses darurat: menunggu → sudah ditinjau', async ({ browser }, info) => {
  test.setTimeout(90_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'rekam.medis')
  await page.goto('/rekam-medis/akses-darurat')
  await expect(page.getByRole('heading', { name: 'Tinjauan akses darurat', level: 1 })).toBeVisible()
  const tabel = page.getByRole('region', { name: 'Daftar akses darurat' })
  const baris = tabel.getByRole('row', { name: new RegExp(alasan) })
  await expect(baris).toContainText(nama)
  await expect(baris).toContainText('Contoh Dokter Akses Darurat')
  await expect(baris).toContainText('Aktif')
  await expect(baris).toContainText('No. RM')
  expect(await pelanggaranAxe(page)).toEqual([])

  await baris.getByRole('button', { name: /Tinjau akses darurat/ }).click()
  const dialog = page.getByRole('dialog', { name: 'Tinjau akses darurat' })
  await dialog.getByRole('button', { name: 'Simpan tinjauan' }).click()
  await expect(dialog).toContainText('minimal 5 karakter')
  await dialog.getByRole('combobox', { name: /Hasil tinjauan/ }).selectOption('wajar')
  await dialog.getByRole('textbox', { name: /Catatan tinjauan/ }).fill('Sesuai keadaan darurat yang diuraikan.')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Simpan tinjauan' }).click()
  await expect(page.getByText('Tinjauan disimpan.')).toBeVisible()
  await expect(baris).toBeHidden()

  await page.getByRole('combobox', { name: 'Status tinjauan' }).selectOption('ditinjau')
  const ditinjau = tabel.getByRole('row', { name: new RegExp(alasan) })
  await expect(ditinjau).toContainText('Wajar')
  await expect(ditinjau).toContainText('Sesuai keadaan darurat')
  await expect(ditinjau.getByRole('button', { name: /Tinjau/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})
