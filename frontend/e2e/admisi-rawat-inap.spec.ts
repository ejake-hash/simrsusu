import { expect, test } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, halamanAkun, pastikanPasienBpjs, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * 2.28-fe: SPRI dari Pemeriksaan poli → antrean Admisi rawat inap → alokasi TT → TT "Dipesan" di peta TT, ke backend asli
 * @1440 px + axe. Akun `dokter.poli` (SIP sintetis dari `lokal:kunjungan-contoh`, izin sel bawaan poliklinik → rawat inap
 * rawat_jalan.pemeriksaan.sahkan) dan `admisi` (menu Pendaftaran: pendaftaran.admisi.lihat/input; lihat peta TT untuk memilih
 * TT; tanpa izin sensitif). Data SINTETIS: peserta BPJS contoh, poli contoh, TT CTHG-2-202-3 (kelas contoh) yang statusnya
 * dikembalikan ke kosong siap tiap `lokal:kunjungan-contoh` dijalankan, jadi uji bisa diulang. Seri: langkah berikut memakai
 * nomor kunjungan langkah sebelumnya.
 */
const kartu = '9990000000001'
const nama = 'Peserta Sintetis BPJS'
const poli = 'Poli Penyakit Dalam (contoh)'
const kodeTt = 'CTHG-2-202-3'
let nomor = ''

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

test('dokter poli membuat SPRI dari pemeriksaan: ringkasan SPRI tampil', async ({ browser }, info) => {
  test.setTimeout(150_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page: admisi } = await halamanAdmisi(browser, asal)
  await tungguJatahPenuh(konteks.request, asal)
  await pastikanPasienBpjs(admisi, nama, kartu)
  await daftarkanKunjunganJkn(admisi, nama, kartu)
  await konteks.close()

  const page = await halamanAkun(browser, asal, 'dokter.poli')
  await page.goto('/rawat-jalan/pemeriksaan')
  await expect(page.getByRole('heading', { name: 'Pemeriksaan', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  await page.getByRole('combobox', { name: 'Tahap' }).selectOption('')
  const baris = page.getByRole('region', { name: 'Worklist poliklinik' }).getByRole('row', { name: new RegExp(nama) })
  await expect(baris).toBeVisible()
  nomor = (await baris.getByRole('cell').first().textContent())!.trim()
  await baris.getByRole('button', { name: `Periksa ${nomor}` }).click()

  const panel = page.getByRole('region', { name: 'Pemeriksaan dokter' })
  const spri = panel.getByRole('region', { name: 'Rawat inap (SPRI)' })
  await spri.getByRole('button', { name: 'Buat SPRI' }).click()
  await spri.getByRole('combobox', { name: /Kelas rawat diminta/ }).selectOption({ label: 'Kelas contoh' })
  await spri.getByRole('combobox', { name: /Bangsal pilihan/ }).selectOption({ label: 'Bangsal Melati (contoh)' })
  await spri.getByRole('textbox', { name: /Alasan rawat/ }).fill('Observasi sintetis e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await spri.getByRole('button', { name: 'Simpan SPRI' }).click()
  await expect(page.getByText(`SPRI ${nama} tersimpan. Permintaan kamar masuk antrean admisi.`)).toBeVisible()
  const ringkasan = spri.getByRole('status')
  await expect(ringkasan).toContainText(/SPRI tersimpan pukul \d{2}\.\d{2} WIB/)
  await expect(ringkasan).toContainText('Menunggu TT')
  await expect(ringkasan).toContainText('Kelas contoh')
  await expect(ringkasan).toContainText('Bangsal Melati (contoh)')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('admisi melihat antrean, mengalokasikan TT kosong siap, TT jadi Dipesan di peta', async ({ browser }, info) => {
  test.setTimeout(120_000)
  expect(nomor).not.toBe('')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'admisi')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/pendaftaran/admisi')
  await expect(page.getByRole('heading', { name: 'Admisi rawat inap', level: 1 })).toBeVisible()
  // `nomor` di worklist = nomor antrean poli; antrean admisi menyebut nomor kunjungan, jadi baris dikunci ke nomor itu.
  const menunggu = page.getByRole('row').filter({ hasText: nama }).filter({ hasText: 'Poliklinik' }).filter({ hasText: 'Menunggu TT' }).first()
  await expect(menunggu).toContainText('Kelas contoh')
  await expect(menunggu).toContainText('Bangsal pilihan: Bangsal Melati (contoh)')
  const nomorKunjungan = (await menunggu.locator('code').first().textContent())!.trim()
  const baris = page.getByRole('row').filter({ hasText: nomorKunjungan })
  await expect(baris).toContainText('Menunggu TT')
  expect(await pelanggaranAxe(page)).toEqual([])

  await baris.getByRole('button', { name: `Alokasikan TT untuk ${nama} ${nomorKunjungan}` }).click()
  const dialog = page.getByRole('dialog', { name: `Alokasikan TT untuk ${nama}` })
  await expect(dialog).toContainText('Kelas diminta Kelas contoh')
  await dialog.getByRole('radio', { name: new RegExp(kodeTt) }).check()
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: `Alokasikan ${kodeTt}` }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText(`TT ${kodeTt} dipesan untuk ${nama}.`)).toBeVisible()
  await expect(baris).toContainText('TT dipesan')
  await expect(baris).toContainText(`${kodeTt} · Bangsal Melati (contoh)`)
  await expect(baris.getByRole('button')).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])

  await page.goto('/rawat-inap/peta-tempat-tidur')
  const tt = page.getByRole('group', { name: `TT ${kodeTt}`, exact: true })
  await expect(tt).toContainText('Dipesan')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})
