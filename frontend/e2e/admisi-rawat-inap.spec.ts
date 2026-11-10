import { expect, test, type Browser, type Page } from '@playwright/test'
import { artisan, bersihkanPesertaSintetis, daftarkanKunjunganJkn, halamanAdmisi, halamanAkun, pastikanPasienBpjs, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * 2.28-fe: SPRI dari Pemeriksaan poli → antrean Admisi rawat inap → alokasi TT → TT "Dipesan" di peta TT, ke backend asli
 * @1440 px + axe. 2.29-fe: batal permintaan (TT kembali kosong siap), lalu SPRI kedua → alokasi → tempatkan → TT "Terisi"
 * dengan "Hari ke-1" di peta, lalu pindah ruang dari peta ke TT kosong siap kedua (TT lama "Dibersihkan"). Akun
 * `dokter.poli` (SIP sintetis dari `lokal:kunjungan-contoh`; izin sel bawaan poliklinik → rawat inap
 * rawat_jalan.pemeriksaan.sahkan, rawat inap → rawat inap rawat_inap.pindah_ruang.input, dan lihat peta TT) dan `admisi`
 * (pendaftaran.admisi.lihat/input, rawat_inap.penempatan.input, lihat peta TT; tanpa izin sensitif). Data SINTETIS: peserta
 * BPJS contoh, poli contoh, TT CTHG-2-202-3 dan CTHG-2-202-4 (kelas contoh). Agar bisa diulang: beforeAll dan afterAll
 * memanggil `bersihkanPesertaSintetis` (kunjungan pasien contoh batal, penempatan aktifnya diakhiri lewat DB), lalu beforeAll
 * memulihkan status TT contoh lewat `lokal:kunjungan-contoh` dan afterAll menjalankan `--batal`. Seri: langkah berikut memakai
 * nomor kunjungan langkah sebelumnya. Spec ini proyek terakhir di playwright.config.ts.
 */
const kartu = '9990000000001'
const nama = 'Peserta Sintetis BPJS'
const poli = 'Poli Penyakit Dalam (contoh)'
const kodeTt = 'CTHG-2-202-3'
const kodeTujuan = 'CTHG-2-202-4'
let nomor = ''
let nomorKunjungan = ''

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

/** Dokter poli membuka kunjungan `nomor` di Pemeriksaan dan menyimpan SPRI kelas contoh, bangsal Melati. */
async function buatSpri(browser: Browser, asal: string, axe: boolean): Promise<Page> {
  const page = await halamanAkun(browser, asal, 'dokter.poli')
  await page.goto('/rawat-jalan/pemeriksaan')
  await expect(page.getByRole('heading', { name: 'Pemeriksaan', level: 1 })).toBeVisible()
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: poli })
  await page.getByRole('combobox', { name: 'Tahap' }).selectOption('')
  const baris = page.getByRole('region', { name: 'Worklist poliklinik' }).getByRole('row', { name: new RegExp(nama) })
  await expect(baris).toBeVisible()
  if (!nomor) nomor = (await baris.getByRole('cell').first().textContent())!.trim()
  await baris.getByRole('button', { name: `Periksa ${nomor}` }).click()

  const spri = page.getByRole('region', { name: 'Pemeriksaan dokter' }).getByRole('region', { name: 'Rawat inap (SPRI)' })
  await spri.getByRole('button', { name: 'Buat SPRI' }).click()
  await spri.getByRole('combobox', { name: /Kelas rawat diminta/ }).selectOption({ label: 'Kelas contoh' })
  await spri.getByRole('combobox', { name: /Bangsal pilihan/ }).selectOption({ label: 'Bangsal Melati (contoh)' })
  await spri.getByRole('textbox', { name: /Alasan rawat/ }).fill('Observasi sintetis e2e')
  if (axe) expect(await pelanggaranAxe(page)).toEqual([])
  await spri.getByRole('button', { name: 'Simpan SPRI' }).click()
  await expect(page.getByText(`SPRI ${nama} tersimpan. Permintaan kamar masuk antrean admisi.`)).toBeVisible()
  return page
}

async function bukaAdmisi(browser: Browser, asal: string): Promise<Page> {
  const page = await halamanAkun(browser, asal, 'admisi')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/pendaftaran/admisi')
  await expect(page.getByRole('heading', { name: 'Admisi rawat inap', level: 1 })).toBeVisible()
  return page
}

/** Baris permintaan aktif kunjungan ini dengan status tertentu (permintaan batal tidak tampil di antrean). */
const barisPermintaan = (page: Page, status: string) => page.getByRole('row').filter({ hasText: nomorKunjungan }).filter({ hasText: status })

async function alokasikan(page: Page, axe: boolean) {
  const baris = barisPermintaan(page, 'Menunggu TT')
  await baris.getByRole('button', { name: `Alokasikan TT untuk ${nama} ${nomorKunjungan}` }).click()
  const dialog = page.getByRole('dialog', { name: `Alokasikan TT untuk ${nama}` })
  await expect(dialog).toContainText('Kelas diminta Kelas contoh')
  await dialog.getByRole('radio', { name: new RegExp(kodeTt) }).check()
  if (axe) expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: `Alokasikan ${kodeTt}` }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText(`TT ${kodeTt} dipesan untuk ${nama}.`).first()).toBeVisible()
}

const petaTt = async (page: Page, kode = kodeTt) => {
  await page.goto('/rawat-inap/peta-tempat-tidur')
  return page.getByRole('group', { name: `TT ${kode}`, exact: true })
}

test('dokter poli membuat SPRI dari pemeriksaan: ringkasan SPRI tampil', async ({ browser }, info) => {
  test.setTimeout(150_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const { konteks, page: admisi } = await halamanAdmisi(browser, asal)
  await tungguJatahPenuh(konteks.request, asal)
  await pastikanPasienBpjs(admisi, nama, kartu)
  await daftarkanKunjunganJkn(admisi, nama, kartu)
  await konteks.close()

  const page = await buatSpri(browser, asal, true)
  const ringkasan = page.getByRole('region', { name: 'Rawat inap (SPRI)' }).getByRole('status')
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
  const page = await bukaAdmisi(browser, asal)
  // `nomor` di worklist = nomor antrean poli; antrean admisi menyebut nomor kunjungan, jadi baris dikunci ke nomor itu.
  const menunggu = page.getByRole('row').filter({ hasText: nama }).filter({ hasText: 'Poliklinik' }).filter({ hasText: 'Menunggu TT' }).first()
  await expect(menunggu).toContainText('Kelas contoh')
  await expect(menunggu).toContainText('Bangsal pilihan: Bangsal Melati (contoh)')
  nomorKunjungan = (await menunggu.locator('code').first().textContent())!.trim()
  await expect(barisPermintaan(page, 'Menunggu TT')).toHaveCount(1)
  expect(await pelanggaranAxe(page)).toEqual([])

  await alokasikan(page, true)
  const baris = barisPermintaan(page, 'TT dipesan')
  await expect(baris).toContainText(`${kodeTt} · Bangsal Melati (contoh)`)
  await expect(baris.getByRole('button', { name: /^Alokasikan TT/ })).toHaveCount(0)
  await expect(baris.getByRole('button', { name: `Tempatkan ${nama} ${nomorKunjungan}` })).toBeVisible()
  expect(await pelanggaranAxe(page)).toEqual([])

  await expect(await petaTt(page)).toContainText('Dipesan')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('admisi membatalkan permintaan dengan alasan: baris keluar antrean, TT kembali Kosong siap', async ({ browser }, info) => {
  test.setTimeout(120_000)
  expect(nomorKunjungan).not.toBe('')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await bukaAdmisi(browser, asal)
  const baris = barisPermintaan(page, 'TT dipesan')
  await baris.getByRole('button', { name: `Batalkan permintaan kamar ${nama} ${nomorKunjungan}` }).click()
  const dialog = page.getByRole('dialog', { name: `Batalkan permintaan kamar ${nama}?` })
  await expect(dialog).toContainText(`TT ${kodeTt} yang dipesan kembali kosong siap.`)
  await expect(dialog.getByRole('button', { name: 'Batalkan permintaan' })).toBeDisabled()
  await dialog.getByRole('textbox', { name: /Alasan pembatalan/ }).fill('Uji batal sintetis e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Batalkan permintaan' }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText(`Permintaan kamar ${nama} dibatalkan. TT ${kodeTt} kembali kosong siap.`)).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: nomorKunjungan })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])

  await expect(await petaTt(page)).toContainText('Kosong siap')
  await page.close()
})

test('SPRI kedua → alokasi → tempatkan: TT Terisi dengan Hari ke-1 di peta', async ({ browser }, info) => {
  test.setTimeout(180_000)
  expect(nomorKunjungan).not.toBe('')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const dokter = await buatSpri(browser, asal, false)
  await dokter.close()

  const page = await bukaAdmisi(browser, asal)
  await alokasikan(page, false)
  const baris = barisPermintaan(page, 'TT dipesan')
  await baris.getByRole('button', { name: `Tempatkan ${nama} ${nomorKunjungan}` }).click()
  const dialog = page.getByRole('dialog', { name: `Tempatkan ${nama}?` })
  await expect(dialog).toContainText(new RegExp(`${nama}, No\\. RM .+, lahir `))
  await expect(dialog).toContainText(`${kodeTt} · Bangsal Melati (contoh)`)
  await dialog.getByRole('textbox', { name: /Serah terima saat tiba/ }).fill('Kondisi stabil, uji sintetis e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: `Tempatkan di ${kodeTt}` }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText(new RegExp(`${nama} ditempatkan di TT ${kodeTt} \\(RI-\\d{8}-\\d{4}, hari rawat ke-1\\)\\.`))).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: nomorKunjungan })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])

  const tt = await petaTt(page)
  await expect(tt).toContainText('Terisi')
  await expect(tt).toContainText('Hari ke-1')
  // Admisi tidak memegang izin pindah ruang: peta tanpa aksi Pindah ruang.
  await expect(tt.getByRole('button', { name: /Pindah ruang/ })).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})

test('dokter memindahkan pasien dari peta TT: TT lama Dibersihkan, TT baru Terisi Hari ke-1', async ({ browser }, info) => {
  test.setTimeout(120_000)
  expect(nomorKunjungan).not.toBe('')
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'dokter.poli')
  await tungguJatahPenuh(page.context().request, asal)
  const lama = await petaTt(page)
  await expect(lama).toContainText('Terisi')
  await lama.getByRole('button', { name: `Pindah ruang pasien di ${kodeTt}` }).click()
  const dialog = page.getByRole('dialog', { name: `Pindah ruang pasien di ${kodeTt}` })
  // Dua penanda identitas (keputusan pemilik 11 Nov 2026) hanya di dialog, tidak di kartu peta.
  await expect(dialog).toContainText(new RegExp(`${nama}, No\\. RM .+, lahir `))
  await expect(lama).not.toContainText(nama)
  await expect(dialog).toContainText(`${kodeTt} · Bangsal Melati (contoh) · Kelas contoh · hari rawat ke-1`)
  // TT asal tidak ditawarkan sebagai tujuan.
  await expect(dialog.getByRole('radio', { name: new RegExp(kodeTt) })).toHaveCount(0)
  await dialog.getByRole('radio', { name: new RegExp(kodeTujuan) }).check()
  await dialog.getByRole('textbox', { name: /Alasan pindah/ }).fill('Pindah kamar uji sintetis e2e')
  await dialog.getByRole('textbox', { name: /Serah terima antar-ruang/ }).fill('Kondisi stabil, uji sintetis e2e')
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: `Pindahkan ke ${kodeTujuan}` }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText(new RegExp(`${nama} \\(No\\. RM .+\\) dipindah dari TT ${kodeTt} ke TT ${kodeTujuan}\\.`))).toBeVisible()
  await expect(lama).toContainText('Dibersihkan')
  const baru = page.getByRole('group', { name: `TT ${kodeTujuan}`, exact: true })
  await expect(baru).toContainText('Terisi')
  await expect(baru).toContainText('Hari ke-1')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.close()
})
