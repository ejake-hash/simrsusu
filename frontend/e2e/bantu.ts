import AxeBuilder from '@axe-core/playwright'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type APIRequestContext, type Browser, type Page } from '@playwright/test'
import { kodeTotp } from './totp'

/** Bantuan e2e bersama: akun sintetis AkunSintetisSeeder dengan sandi E2E_SANDI. */
export const SANDI = process.env.E2E_SANDI ?? ''

async function xsrf(permintaan: APIRequestContext): Promise<string> {
  return decodeURIComponent((await permintaan.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
}

/**
 * Langkah sandi (+ TOTP bila diminta) kontrak 10.3.2 lewat API; halaman masuk sudah diuji di masuk.spec/mfa.spec.
 */
export async function masukLewatApi(permintaan: APIRequestContext, asal: string, nama: string, rahasiaTotp?: string): Promise<void> {
  await permintaan.get('/sanctum/csrf-cookie')
  // Backend hanya menerima langkah masuk dari origin SPA (asal_tidak_diizinkan).
  const header = async () => ({ 'X-XSRF-TOKEN': await xsrf(permintaan), Accept: 'application/json', Origin: asal })
  const masuk = await permintaan.post('/api/v1/autentikasi/masuk', { data: { nama_pengguna: nama, sandi: SANDI }, headers: await header() })
  expect(masuk.ok(), `${masuk.status()} ${await masuk.text()}`).toBeTruthy()
  const isi = (await masuk.json()) as { status: string; challenge_id?: string }
  if (isi.status === 'masuk') return
  expect(isi.status).toBe('perlu_mfa')
  const mfa = await permintaan.post('/api/v1/autentikasi/mfa', {
    data: { challenge_id: isi.challenge_id, metode: 'totp', kode: kodeTotp(rahasiaTotp ?? '') },
    headers: await header(),
  })
  expect(mfa.ok(), `${mfa.status()} ${await mfa.text()}`).toBeTruthy()
}

export async function tanpaGulirSamping(page: Page): Promise<void> {
  const [lebarIsi, lebarLayar] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth])
  expect(lebarIsi).toBeLessThanOrEqual(lebarLayar)
}

/** Perintah artisan backend lokal (hanya DB lokal/CI): data contoh, simulator VClaim, pembersihan. */
const BACKEND = fileURLToPath(new URL('../../backend/', import.meta.url))
// Semua tinker e2e mengubah data: ditolak di sisi PHP bila APP_ENV bukan local/testing (jangan pernah ke staging/produksi).
const hanyaLokal = "abort_unless(in_array(app()->environment(), ['local', 'testing'], true), 500, 'e2e hanya untuk lokal/CI'); "
export const php = (...arg: string[]) => {
  const i = arg.indexOf('--execute')
  const argumen = arg[0] === 'tinker' && i >= 0 ? arg.map((a, j) => (j === i + 1 ? hanyaLokal + a : a)) : arg
  return execFileSync('php', ['artisan', ...argumen], { cwd: BACKEND, stdio: 'pipe' }).toString()
}
export const artisan = (...arg: string[]) => php('lokal:kunjungan-contoh', ...arg)
export const simulatorVclaim = (mode: 'normal' | 'tolak') => php('integrasi:simulator', 'bpjs_vclaim', mode)
export const circuitBreaker = (status: 'terbuka' | 'tertutup') =>
  php('tinker', '--execute', `DB::table('integrasi.status_sistem')->where('tujuan', 'bpjs_vclaim')->update(['cb_status' => '${status}', 'cb_sejak' => now(), 'coba_lagi_pada' => ${status === 'terbuka' ? 'now()->addMinutes(5)' : 'null'}]);`)

/**
 * Peserta sintetis (No. kartu unik) dipakai ulang antarjalan: kunjungannya dibatalkan agar tidak bentrok pada hari yang sama,
 * berikut nomor antreannya (pembatalan lewat DB tidak memicu event batal kunjungan). Penempatan TT yang masih aktif (2.29)
 * diakhiri agar pasien bisa ditempatkan lagi di jalan berikutnya; status TT contoh dipulihkan `lokal:kunjungan-contoh`.
 */
export function bersihkanPesertaSintetis(): void {
  php('tinker', '--execute', "$u = DB::table('akses.pengguna')->value('id'); $k = DB::table('kunjungan.kunjungan')->whereIn('pasien_id', DB::table('pasien.pasien')->where('nama', 'Peserta Sintetis BPJS')->pluck('id'))->where('status', '<>', 'batal'); $id = (clone $k)->pluck('id'); $k->update(['status' => 'batal', 'alasan_batal' => 'Bersih uji e2e', 'dibatalkan_oleh' => $u, 'dibatalkan_pada' => now()]); DB::table('kunjungan.antrean')->whereIn('kunjungan_id', $id)->whereIn('status', ['menunggu', 'dipanggil', 'dilewati', 'selesai'])->update(['status' => 'batal', 'alasan_batal' => 'Bersih uji e2e', 'dibatalkan_oleh' => $u, 'dibatalkan_pada' => now()]); DB::table('kunjungan.penempatan_tempat_tidur')->whereIn('kunjungan_id', $id)->whereNull('selesai_pada')->update(['selesai_pada' => now(), 'alasan_selesai' => 'pulang', 'diakhiri_oleh' => $u]);")
}

/** Login sebagai admisi di konteks baru 1440 px (tanpa animasi supaya axe memeriksa keadaan akhir). */
export async function halamanAdmisi(browser: Browser, asal: string) {
  const masuk = await browser.newContext({ baseURL: asal })
  await masukLewatApi(masuk.request, asal, 'admisi')
  const konteks = await browser.newContext({ baseURL: asal, storageState: await masuk.storageState(), viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' })
  await masuk.close()
  return { konteks, page: await konteks.newPage() }
}

/** Pasien contoh dengan No. kartu BPJS: dipakai ulang bila sudah ada (No. kartu unik), selain itu didaftarkan lewat layar. */
export async function pastikanPasienBpjs(page: Page, nama: string, kartu: string): Promise<void> {
  await page.goto('/pendaftaran/pasien')
  await page.getByRole('main').getByRole('searchbox', { name: 'Cari pasien' }).fill(kartu)
  const ada = page.getByRole('region', { name: 'Hasil pencarian pasien' }).getByText(nama)
  const kosong = page.getByText('Tidak ada pasien yang cocok.', { exact: false })
  await expect(ada.or(kosong)).toBeVisible()
  if (await ada.isVisible()) return
  const lahir = new Date(Date.UTC(1940, 0, 1) + (Date.now() % 23_000) * 86_400_000).toISOString().slice(0, 10)
  await page.getByRole('button', { name: 'Pasien baru' }).click()
  const dialog = page.getByRole('dialog', { name: 'Daftarkan pasien baru' })
  await dialog.getByLabel('Nama lengkap').fill(nama)
  await dialog.getByRole('textbox', { name: 'Tanggal lahir' }).fill(lahir)
  await dialog.getByRole('combobox', { name: 'Jenis kelamin' }).selectOption('perempuan')
  await dialog.getByLabel('No. kartu BPJS').fill(kartu)
  await page.getByRole('button', { name: 'Daftarkan pasien' }).click()
  await expect(page.getByText(/^Pasien terdaftar dengan No\. RM \d+\.$/)).toBeVisible()
}

/** Kunjungan JKN di Poli Penyakit Dalam hari ini; layar langsung membuka panel SEP kunjungan itu. */
export async function daftarkanKunjunganJkn(page: Page, nama: string, kartu: string) {
  await page.goto('/pendaftaran/kunjungan')
  await page.getByRole('combobox', { name: 'Poliklinik' }).selectOption({ label: 'Poli Penyakit Dalam (contoh)' })
  await page.getByRole('region', { name: 'Jadwal praktik poliklinik' }).getByRole('row', { name: /Contoh Dokter Poli/ }).click()
  await page.getByRole('main').getByRole('searchbox', { name: 'Cari pasien' }).fill(kartu)
  await page.getByRole('button', { name: new RegExp(nama) }).click()
  await page.getByRole('button', { name: 'Daftarkan kunjungan' }).click()
  await expect(page.getByText(new RegExp(`^Kunjungan \\S+ terdaftar untuk ${nama}\\.$`))).toBeVisible()
  const panel = page.getByRole('region', { name: 'Terbitkan SEP' })
  await expect(panel).toContainText(nama)
  return panel
}

/** Konteks 1440 px per akun sintetis tanpa MFA; satu kali masuk per akun per worker (rate limit masuk 10/menit per nama pengguna). */
const sesiAkun = new Map<string, Awaited<ReturnType<APIRequestContext['storageState']>>>()
export async function halamanAkun(browser: Browser, asal: string, nama: string): Promise<Page> {
  if (!sesiAkun.has(nama)) {
    const masuk = await browser.newContext({ baseURL: asal, storageState: { cookies: [], origins: [] } })
    await masukLewatApi(masuk.request, asal, nama)
    sesiAkun.set(nama, await masuk.storageState())
    await masuk.close()
  }
  const konteks = await browser.newContext({ baseURL: asal, storageState: sesiAkun.get(nama), viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' })
  return konteks.newPage()
}

/**
 * Spec sebelumnya (mis. pengaturan, fase2) bisa sudah memakai sebagian besar jatah rate limit akun yang sama dalam menit
 * terakhir; SPA menganggap 429 di /saya sebagai belum masuk. Bila sisa jatah kurang dari separuh, tunggu satu jendela.
 * Panggil di awal uji, bukan di helper bersama: penantian ±61 dtk merusak spec yang peka waktu (jeda panggilan antrean).
 */
export async function tungguJatahPenuh(request: APIRequestContext, asal: string) {
  const r = await request.get('/api/v1/saya', { headers: { Accept: 'application/json', Origin: asal, Referer: `${asal}/` } })
  const sisa = Number(r.headers()['x-ratelimit-remaining'] ?? 0)
  const batas = Number(r.headers()['x-ratelimit-limit'] ?? 120)
  if (r.status() !== 429 && sisa >= batas / 2) return
  test.info().setTimeout(test.info().timeout + 62_000)
  await new Promise((selesai) => setTimeout(selesai, 61_000))
}

/** Header tulis API (cookie Sanctum stateful + XSRF) dari konteks halaman yang sudah masuk. */
export async function headerTulis(page: Page, asal: string) {
  const token = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  return { 'X-XSRF-TOKEN': token, Accept: 'application/json', Origin: asal, Referer: `${asal}/` }
}

/** Pelanggaran axe berdampak serius/kritis pada keadaan halaman saat ini. */
export async function pelanggaranAxe(page: Page): Promise<string[]> {
  const hasil = await new AxeBuilder({ page }).analyze()
  return hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').flatMap((v) => v.nodes.map((n) => `${v.id}: ${n.target.join(' ')}`))
}
