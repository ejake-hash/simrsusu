import { expect, test } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, halamanAkun, pastikanPasienBpjs, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * Tiket 2.13-fe: pasien terdaftar (admisi) → muncul di worklist tahap perawat → perawat memanggil → asesmen draf dengan
 * alergi → selesai → tahap dokter; lalu perbaikan beralasan dengan sisa waktu. Akun perawat (peran contoh_perawat),
 * data contoh yang sama dengan pendaftaran.spec.ts, jadi berjalan sesudah proyek fase2 (playwright.config.ts). Seri: tiap
 * langkah memakai nomor dari langkah sebelumnya. Hanya 1440 px + axe; empat lebar di workflow malam.
 * Tiket 2.14-fe: CPPT kunjungan yang sama ditulis, disahkan dengan PIN T1 perawat sendiri (TtdSintetisSeeder,
 * E2E_TTD_PIN), diperbaiki dengan alasan dan tanda tangan ulang, lalu disaring per profesi dan episode.
 * Tiket 2.15-fe: dokter jadwal poli contoh (akun `dokter.poli`) membuka Pemeriksaan kunjungan yang sama, selesai ditolak tanpa diagnosis utama,
 * mencatat diagnosis dari paket ICD-10 SINTETIS lokal (ZZC*, lokal:kunjungan-contoh), lalu menyelesaikan kunjungan.
 */
const kartu = '9990000000001'
const nama = 'Peserta Sintetis BPJS'
const poli = 'Poli Penyakit Dalam (contoh)'
const PIN = process.env.E2E_TTD_PIN ?? ''
let nomor = ''
// Alergi menempel pada pasien (dipakai ulang antarjalan), jadi zatnya unik per jalan.
const zat = `Zat uji ${Date.now() % 100000}`

test.describe.configure({ mode: 'serial' })
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  bersihkanPesertaSintetis()
  artisan()
})
test.afterAll(() => {
  bersihkanPesertaSintetis()
  artisan('--batal')
})

test('pasien terdaftar masuk worklist, dipanggil, asesmen draf + alergi, selesai → tahap dokter', async ({ browser }, info) => {
  test.setTimeout(150_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page: admisi } = await halamanAdmisi(browser, asal)
  // admisi baru dipakai beruntun oleh proyek sebelumnya; 429 di /saya membuat SPA kembali ke halaman masuk.
  await tungguJatahPenuh(konteks.request, asal)
  await pastikanPasienBpjs(admisi, nama, kartu)
  await daftarkanKunjunganJkn(admisi, nama, kartu)
  await konteks.close()

  const page = await halamanAkun(browser, asal, 'perawat')
  await page.goto('/rawat-jalan/worklist')
  await expect(page.getByRole('heading', { name: 'Worklist poliklinik', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  const tabel = page.getByRole('region', { name: 'Worklist poliklinik' })
  const baris = tabel.getByRole('row', { name: new RegExp(nama) })
  await expect(baris).toBeVisible()
  await expect(baris).toContainText('Menunggu perawat')
  await expect(baris).toContainText('No. RM')
  nomor = (await baris.getByRole('cell').first().textContent())!.trim()
  expect(nomor).toMatch(/^PD-\d{3,}$/)
  expect(await pelanggaranAxe(page)).toEqual([])

  await baris.getByRole('button', { name: `Panggil ${nomor}` }).click()
  await expect(page.getByText(`Nomor ${nomor} dipanggil.`)).toBeVisible()
  await expect(baris).toContainText('Dipanggil')

  await baris.getByRole('button', { name: `Mulai asesmen ${nomor}` }).click()
  const dialog = page.getByRole('dialog', { name: 'Asesmen awal keperawatan' })
  await expect(dialog.getByRole('textbox', { name: /Keluhan utama/ })).toBeVisible()
  await expect(dialog).toContainText(nama)
  await dialog.getByRole('textbox', { name: /Keluhan utama/ }).fill('Demam tiga hari')
  await dialog.getByRole('spinbutton', { name: /Sistolik/ }).fill('120')
  await dialog.getByRole('spinbutton', { name: /Diastolik/ }).fill('80')
  // Kurang nadi, napas, suhu: server menolak selesai dengan rincian, draf tetap tersimpan.
  await dialog.getByRole('button', { name: 'Selesaikan asesmen' }).click()
  await expect(dialog.getByRole('alert')).toContainText('wajib')
  await dialog.getByRole('spinbutton', { name: /Nadi/ }).fill('80')
  await dialog.getByRole('spinbutton', { name: /Napas/ }).fill('18')
  await dialog.getByRole('spinbutton', { name: /Suhu/ }).fill('37.2')
  await dialog.getByRole('spinbutton', { name: /Berat badan/ }).fill('70')
  await dialog.getByRole('spinbutton', { name: /Tinggi badan/ }).fill('170')
  await dialog.getByRole('button', { name: 'Simpan draf' }).click()
  await expect(page.getByText('Asesmen disimpan.')).toBeVisible()
  await expect(dialog).toContainText('24,22')

  // Alergi: dicatat sekali (409 bila sama), tampil di daftar.
  await dialog.getByRole('textbox', { name: /^Zat/ }).fill(zat)
  await dialog.getByRole('combobox', { name: /^Jenis/ }).selectOption('obat')
  await dialog.getByRole('button', { name: 'Catat alergi' }).click()
  await expect(dialog.getByRole('list').getByText(zat)).toBeVisible()
  await dialog.getByRole('textbox', { name: /^Zat/ }).fill(zat.toLowerCase())
  await dialog.getByRole('combobox', { name: /^Jenis/ }).selectOption('obat')
  await dialog.getByRole('button', { name: 'Catat alergi' }).click()
  await expect(dialog).toContainText('sudah tercatat')
  expect(await pelanggaranAxe(page)).toEqual([])

  await dialog.getByRole('button', { name: 'Selesaikan asesmen' }).click()
  await expect(page.getByText('Asesmen selesai. Pasien menunggu dokter.')).toBeVisible()
  await expect(dialog).toBeHidden()
  await expect(baris).toContainText('Menunggu dokter')
  await expect(baris).toContainText('Ada alergi')

  // Perbaikan: sisa waktu tampil, alasan wajib.
  await baris.getByRole('button', { name: `Lihat asesmen ${nomor}` }).click()
  await expect(dialog).toContainText(/Perbaikan masih bisa dalam [12] hari/)
  await expect(dialog.getByRole('button', { name: 'Simpan perbaikan' })).toBeDisabled()
  await dialog.getByRole('textbox', { name: /Catatan perawat/ }).fill('Pasien membawa hasil lab lama')
  await dialog.getByRole('textbox', { name: /Alasan perubahan/ }).fill('Melengkapi catatan')
  await dialog.getByRole('button', { name: 'Simpan perbaikan' }).click()
  await expect(page.getByText('Asesmen disimpan.')).toBeVisible()
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('sub-menu Asesmen perawat menampilkan pasien yang menunggu perawat', async ({ browser }, info) => {
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'perawat')
  await page.goto('/rawat-jalan/asesmen-perawat')
  await expect(page.getByRole('heading', { name: 'Asesmen perawat', level: 1 })).toBeVisible()
  await expect(page.getByRole('combobox', { name: 'Tahap' })).toHaveValue('perawat')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('CPPT: tulis SOAP polos, sahkan dengan PIN sendiri, perbaiki beralasan, saring profesi dan episode', async ({ browser }, info) => {
  test.setTimeout(120_000)
  if (!PIN) throw new Error('Set E2E_TTD_PIN sama dengan AKUN_SINTETIS_TTD_PIN saat seeding.')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'perawat')
  await page.goto('/rawat-jalan/cppt')
  await expect(page.getByRole('heading', { name: 'CPPT episode', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  await page.getByRole('button', { name: `Buka CPPT ${nomor}` }).click()
  const cppt = page.getByRole('region', { name: 'CPPT', exact: true })
  await expect(cppt).toContainText(nama)
  await expect(cppt).toContainText('No. RM')

  // Kalimat unik per jalan: pasien sintetis dipakai ulang antarjalan, linimasa lintas kunjungan memuat catatan lama.
  const subjektif = `Keluhan uji ${Date.now() % 100000}`
  await cppt.getByRole('button', { name: 'Tulis CPPT' }).click()
  await cppt.getByRole('textbox', { name: /Subjektif/ }).fill(subjektif)
  await cppt.getByRole('textbox', { name: /Asesmen/ }).fill('Observasi febris')
  await cppt.getByRole('button', { name: 'Simpan draf' }).click()
  await expect(page.getByText(/CPPT disimpan sebagai draf/)).toBeVisible()
  // Dikunci ke id entri: dalam mode ubah isi pindah ke textarea, jadi saringan teks tidak lagi menemukannya.
  const judul = await cppt.getByRole('article').filter({ hasText: subjektif }).getAttribute('aria-labelledby')
  const entri = cppt.locator(`article[aria-labelledby="${judul}"]`)
  await expect(entri).toContainText('Draf hanya terlihat oleh Anda')
  expect(await pelanggaranAxe(page)).toEqual([])

  const tandatangani = async () => {
    const ttd = page.getByRole('dialog', { name: 'Tanda tangan elektronik (T1)' })
    await expect(ttd).toContainText('Contoh Perawat')
    await ttd.getByRole('checkbox').first().check()
    const pin = ttd.getByLabel('PIN tanda tangan')
    if (await pin.isVisible()) await pin.fill(PIN)
    await ttd.getByRole('button', { name: 'Tanda tangani' }).click()
    await expect(ttd).toBeHidden()
  }
  await entri.getByRole('button', { name: 'Sahkan dengan PIN' }).click()
  await tandatangani()
  await expect(page.getByText(/CPPT disahkan/)).toBeVisible()
  await expect(entri).toContainText(/Perbaikan masih bisa dalam [12] hari/)

  await entri.getByRole('button', { name: 'Perbaiki' }).click()
  await entri.getByRole('textbox', { name: /Plan/ }).fill('Kontrol tiga hari')
  const simpan = entri.getByRole('button', { name: 'Simpan perbaikan dan tandatangani' })
  await expect(simpan).toBeDisabled()
  await entri.getByRole('textbox', { name: /Alasan perubahan/ }).fill('Melengkapi rencana')
  await simpan.click()
  await tandatangani()
  await expect(page.getByText(/Perbaikan CPPT disimpan/)).toBeVisible()
  await expect(entri).toContainText('Diperbaiki 1×')
  await expect(entri).toContainText('Kontrol tiga hari')

  await cppt.getByRole('combobox', { name: 'Profesi' }).selectOption('dokter')
  await expect(entri).toBeHidden()
  await cppt.getByRole('combobox', { name: 'Profesi' }).selectOption('perawat')
  await expect(entri).toBeVisible()
  await cppt.getByRole('combobox', { name: 'Lingkup' }).selectOption('episode')
  await expect(entri).toBeVisible()
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('dokter memeriksa: selesai ditolak tanpa diagnosis utama, catat ICD-10 sintetis, selesaikan kunjungan', async ({ browser }) => {
  test.setTimeout(90_000)
  const page = await halamanAkun(browser, new URL(test.info().project.use.baseURL ?? '').origin, 'dokter.poli')
  await page.goto('/rawat-jalan/pemeriksaan')
  await expect(page.getByRole('heading', { name: 'Pemeriksaan', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  const baris = page.getByRole('region', { name: 'Worklist poliklinik' }).getByRole('row', { name: new RegExp(nama) })
  await expect(baris).toContainText('Menunggu dokter')
  await baris.getByRole('button', { name: `Periksa ${nomor}` }).click()
  const panel = page.getByRole('region', { name: 'Pemeriksaan dokter' })
  await expect(panel).toContainText(nama)
  await expect(panel).toContainText('Belum ada diagnosis')
  expect(await pelanggaranAxe(page)).toEqual([])

  await panel.getByRole('button', { name: /Selesaikan kunjungan/ }).click()
  await expect(panel).toContainText('diagnosis utama belum dicatat')

  await panel.getByRole('button', { name: 'Tambah diagnosis' }).click()
  await page.getByRole('combobox', { name: 'Cari kode atau nama diagnosis ICD-10' }).fill('ZZC')
  await page.getByRole('option', { name: /Diagnosis contoh sintetis satu/ }).click()
  await expect(page.getByText('Diagnosis ZZC01 dicatat sebagai utama.')).toBeVisible()
  await expect(panel.getByRole('listitem').filter({ hasText: 'ZZC01' })).toContainText('Utama')

  await panel.getByRole('button', { name: /Selesaikan kunjungan/ }).click()
  await expect(page.getByText(`Kunjungan ${nama} selesai.`)).toBeVisible()
  // Saringan bawaan "menunggu dokter": pasien selesai pindah ke tahap Selesai.
  await page.getByRole('combobox', { name: 'Tahap' }).selectOption('selesai')
  await expect(baris).toContainText('Selesai')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})
