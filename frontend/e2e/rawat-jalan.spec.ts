import { expect, test } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, halamanAkun, pastikanPasienBpjs, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * Tiket 2.13-fe: pasien terdaftar (admisi) → muncul di worklist tahap perawat → perawat memanggil → asesmen draf dengan
 * alergi → selesai → tahap dokter; lalu perbaikan beralasan dengan sisa waktu. Akun perawat (peran contoh_perawat),
 * data contoh yang sama dengan pendaftaran.spec.ts, jadi berjalan sesudah proyek fase2 (playwright.config.ts). Seri: tiap
 * langkah memakai nomor dari langkah sebelumnya. Hanya 1440 px + axe; empat lebar di workflow malam.
 */
const kartu = '9990000000001'
const nama = 'Peserta Sintetis BPJS'
const poli = 'Poli Penyakit Dalam (contoh)'
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
  await expect(dialog).toContainText(/Perbaikan masih bisa dalam 1 hari/)
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
