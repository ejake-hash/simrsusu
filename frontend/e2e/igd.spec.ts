import { expect, test } from '@playwright/test'
import { artisan, halamanAdmisi, halamanAkun, headerTulis, pelanggaranAxe, php, SANDI, tungguJatahPenuh } from './bantu'

/**
 * 2.23-fe: Pendaftaran IGD ke backend asli @1440 px + axe. 2.24: Triase dan ulang triase pasien yang sama. Akun `perawat` (peran contoh_perawat: seluruh menu IGD, lihat
 * pasien dan penjamin). Pasien SINTETIS "Uji IGD …" didaftarkan admisi lewat API (perawat tidak boleh membuat pasien),
 * lalu perawat mendaftarkannya ke IGD dan mencoba lagi (409 kunjungan_igd_aktif). Registrasi butuh instalasi siap
 * melayani (`lokal:kunjungan-contoh`), jadi berjalan sesudah anjungan (playwright.config.ts). beforeAll dan afterAll
 * membatalkan kunjungan IGD aktif pasien uji agar jalan ulang tidak kena 409. 2.26: disposisi oleh `dokter.poli` (dokter ber-SIP
 * sintetis; peran contoh memegang igd.disposisi.* dan igd.pendaftaran.lihat).
 */
const akhiran = Date.now().toString(36).replace(/\d/g, (d) => 'abcdefghij'[Number(d)]!)
const nama = `Uji IGD ${akhiran}`
/** Pasien uji dari tes pertama; didaftarkan ulang ke IGD sesudah disposisi pulang (2.26). */
let pasienId = ''

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
  const { no_rm: noRm, id } = ((await r.json()) as { data: { no_rm: string; id: string } }).data
  pasienId = id
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

// 2.24: pasien uji dari tes sebelumnya (serial) masih di IGD. Instrumen triase SINTETIS `CONTOH-TRIASE` dari lokal:kunjungan-contoh.
test('perawat mentriase pasien IGD lalu ulang triase; riwayat dua baris, yang terbaru berlaku', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'perawat')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/igd/triase')
  await expect(page.getByRole('heading', { level: 1, name: 'Triase' })).toBeVisible()

  const tabel = page.getByRole('region', { name: 'Tabel pasien di IGD untuk triase' })
  const baris = tabel.getByRole('row', { name: new RegExp(nama) })
  await expect(baris).toContainText('Belum ditriase')
  await baris.click()
  await expect(page.getByRole('heading', { level: 2, name: `Triase ${nama}` })).toBeVisible()
  await expect(page.getByText('Pasien ini belum ditriase.')).toBeVisible()

  // Kategori = pilihan berteks + ikon; instrumen dan skala dari data induk (contoh sintetis).
  await page.getByRole('radio', { name: 'Kuning' }).check()
  await page.getByRole('combobox', { name: /Instrumen triase/ }).selectOption({ label: 'Instrumen triase contoh' })
  await page.getByRole('combobox', { name: /Skala/ }).selectOption({ label: 'Tingkat contoh 2' })
  await page.getByRole('textbox', { name: /Keluhan saat triase/ }).fill('Sesak napas (uji sintetis)')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.getByRole('button', { name: 'Simpan triase' }).click()
  await expect(page.getByText(new RegExp(`^Triase Kuning tersimpan untuk ${nama}, pukul \\d{2}\\.\\d{2} WIB\\.$`))).toBeVisible()
  const riwayat = page.getByRole('list', { name: 'Riwayat triase, terbaru di atas' }).getByRole('listitem')
  await expect(riwayat).toHaveCount(1)

  // Ulang triase: baris baru di atas, baris lama tetap ada tanpa label Berlaku.
  await page.getByRole('radio', { name: 'Merah' }).check()
  await page.getByRole('combobox', { name: /Skala/ }).selectOption({ label: 'Tingkat contoh 1' })
  await page.getByRole('button', { name: 'Simpan ulang triase' }).click()
  await expect(riwayat).toHaveCount(2)
  await expect(riwayat.first()).toContainText('Merah')
  await expect(riwayat.first()).toContainText('Berlaku')
  await expect(riwayat.nth(1)).toContainText('Kuning')
  await expect(riwayat.nth(1)).toContainText('Sesak napas (uji sintetis)')
  await expect(riwayat.nth(1)).not.toContainText('Berlaku')
  await expect(baris).toContainText('Merah')
  expect(await pelanggaranAxe(page)).toEqual([])

  // Pendaftaran IGD ikut menampilkan triase berlaku.
  await page.goto('/igd/pendaftaran')
  await expect(page.getByRole('region', { name: 'Tabel pasien di IGD' }).getByRole('row', { name: new RegExp(nama) })).toContainText('Merah')
  await page.context().close()
})

// 2.26: dokter ber-SIP (akun `dokter.poli`, SIP sintetis dari lokal:kunjungan-contoh) memberi disposisi menurut matriks
// perpindahan. Pasien uji masih di IGD dari tes sebelumnya (serial).
test('dokter memberi disposisi pulang: konfirmasi menyebut pasien, kunjungan ditutup dan keluar dari daftar IGD aktif', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'dokter.poli')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/igd/disposisi')
  await expect(page.getByRole('heading', { level: 1, name: 'Disposisi' })).toBeVisible()

  const tabel = page.getByRole('region', { name: 'Tabel pasien di IGD untuk disposisi' })
  const baris = tabel.getByRole('row', { name: new RegExp(nama) })
  await baris.click()
  await expect(page.getByRole('heading', { level: 2, name: `Disposisi ${nama}` })).toBeVisible()
  await expect(page.getByText('Belum ada disposisi untuk kunjungan ini.')).toBeVisible()

  // Tujuan dari sel matriks aktif asal IGD: konsul dan order penunjang tidak ditawarkan.
  const tujuan = page.getByRole('group', { name: /Tujuan/ })
  await expect(tujuan.getByRole('radio', { name: 'Pulang', exact: true })).toBeVisible()
  await expect(tujuan.getByRole('radio', { name: /Penunjang|Poli lain/ })).toHaveCount(0)
  const simpan = page.getByRole('button', { name: 'Simpan disposisi' })
  await tujuan.getByRole('radio', { name: 'Pulang', exact: true }).check()
  // Alasan wajib.
  await expect(simpan).toBeDisabled()
  await page.getByRole('textbox', { name: /^Alasan/ }).fill('Keluhan membaik, tanda vital stabil (uji sintetis)')
  expect(await pelanggaranAxe(page)).toEqual([])
  await simpan.click()

  const konfirmasi = page.getByRole('alertdialog', { name: `Simpan disposisi Pulang untuk ${nama}?` })
  await expect(konfirmasi).toContainText(/No\. RM [\d.]+, lahir/)
  await expect(konfirmasi).toContainText('Kunjungan IGD ditutup')
  expect(await pelanggaranAxe(page)).toEqual([])
  await konfirmasi.getByRole('button', { name: 'Ya, simpan disposisi' }).click()

  await expect(page.getByText(new RegExp(`^Disposisi Pulang tersimpan untuk ${nama}, pukul \\d{2}\\.\\d{2} WIB\\. Status: Selesai\\.$`))).toBeVisible()
  await expect(baris).toHaveCount(0)
  await expect(page.getByText('Kunjungan IGD pasien ini sudah ditutup', { exact: false })).toBeVisible()
  const riwayat = page.getByRole('list', { name: 'Riwayat disposisi, terbaru di atas' }).getByRole('listitem')
  await expect(riwayat).toHaveCount(1)
  await expect(riwayat.first()).toContainText('Pulang')
  await expect(riwayat.first()).toContainText('Selesai')
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})

test('disposisi rawat inap: serah terima wajib, status Menunggu kamar, pasien tetap di IGD', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  // Pasien yang sama sudah keluar dari IGD, jadi boleh didaftarkan lagi (perawat, lewat API).
  const perawat = await halamanAkun(browser, asal, 'perawat')
  await perawat.goto('/')
  const r = await perawat.request.post('/api/v1/kunjungan-igd', { data: { pasien_id: pasienId, cara_bayar: 'non_jkn' }, headers: await headerTulis(perawat, asal) })
  expect(r.status(), await r.text()).toBe(201)
  await perawat.context().close()

  const page = await halamanAkun(browser, asal, 'dokter.poli')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/igd/disposisi')
  const baris = page.getByRole('region', { name: 'Tabel pasien di IGD untuk disposisi' }).getByRole('row', { name: new RegExp(nama) })
  await baris.click()
  await page.getByRole('group', { name: /Tujuan/ }).getByRole('radio', { name: 'Rawat inap' }).check()
  await page.getByRole('textbox', { name: /^Alasan/ }).fill('Perlu observasi rawat inap (uji sintetis)')
  const simpan = page.getByRole('button', { name: 'Simpan disposisi' })
  await expect(simpan).toBeDisabled()
  await expect(page.getByText('Isi catatan serah terima, minimal 5 karakter.')).toBeVisible()
  await page.getByRole('textbox', { name: /^Serah terima/ }).fill('Infus terpasang, pantau tanda vital tiap jam (uji sintetis)')
  await simpan.click()
  const konfirmasi = page.getByRole('dialog', { name: `Simpan disposisi Rawat inap untuk ${nama}?` })
  await expect(konfirmasi).toContainText('Permintaan kamar rawat inap dibuat')
  await konfirmasi.getByRole('button', { name: 'Ya, simpan disposisi' }).click()

  const riwayat = page.getByRole('list', { name: 'Riwayat disposisi, terbaru di atas' }).getByRole('listitem')
  await expect(riwayat.first()).toContainText('Menunggu kamar')
  await expect(riwayat.first()).toContainText('Serah terima: Infus terpasang')
  await expect(baris).toHaveCount(1)
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
