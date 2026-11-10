import { expect, test, type Page } from '@playwright/test'
import { artisan, halamanAkun, pelanggaranAxe, SANDI, tungguJatahPenuh } from './bantu'

/**
 * 2.27-fe: Rawat Inap → Peta tempat tidur ke backend asli @1440 px + axe, akun `perawat` (peran contoh_perawat: seluruh
 * menu Rawat Inap, jadi lihat + perbaiki peta TT, tanpa izin sensitif). Data SINTETIS dari `lokal:kunjungan-contoh`: gedung
 * CTHG, lantai 2, bangsal CONTOH-MELATI, kamar 201/202 dengan enam status; perintah itu mengembalikan status tiap
 * dijalankan, jadi uji yang menandai CTHG-2-201-2 kosong siap bisa diulang.
 */
test.beforeAll(() => {
  if (!SANDI) throw new Error('Set E2E_SANDI sama dengan AKUN_SINTETIS_SANDI saat seeding.')
  artisan()
})
test.afterAll(() => {
  artisan('--batal')
})

const tt = (page: Page, kode: string) => page.getByRole('group', { name: `TT ${kode}`, exact: true })

test('peta TT berkelompok dengan status berteks; cari kode TT; tandai TT dibersihkan kosong siap', async ({ browser }, info) => {
  test.setTimeout(120_000)
  const asal = new URL(info.project.use.baseURL ?? '').origin
  const page = await halamanAkun(browser, asal, 'perawat')
  await tungguJatahPenuh(page.context().request, asal)
  await page.goto('/rawat-inap/peta-tempat-tidur')
  await expect(page.getByRole('heading', { level: 1, name: 'Peta tempat tidur' })).toBeVisible()

  const gedung = page.getByRole('region', { name: 'Gedung Rawat Inap (contoh)' })
  await expect(gedung.getByRole('heading', { level: 3, name: 'Lantai 2 (contoh)' })).toBeVisible()
  await expect(gedung.getByRole('heading', { level: 4, name: 'Bangsal Melati (contoh)' })).toBeVisible()
  await expect(gedung.getByRole('list', { name: 'Kamar 201 (contoh)' }).getByRole('group')).toHaveCount(3)

  const harap: [string, string][] = [
    ['CTHG-2-201-1', 'Terisi'], ['CTHG-2-201-2', 'Dibersihkan'], ['CTHG-2-201-3', 'Dipesan'],
    ['CTHG-2-202-1', 'Rencana pulang'], ['CTHG-2-202-2', 'Tidak dipakai'], ['CTHG-2-202-3', 'Kosong siap'],
  ]
  for (const [kode, label] of harap) await expect(tt(page, kode)).toContainText(label)
  // Aksi hanya di TT dibersihkan; TT tidak dipakai dan status lain tanpa aksi.
  for (const [kode] of harap.filter(([k]) => k !== 'CTHG-2-201-2')) await expect(tt(page, kode).getByRole('button')).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])

  const cari = page.getByRole('main').getByRole('searchbox', { name: 'Cari kode TT' })
  await cari.fill('CTHG-2-202')
  await expect(tt(page, 'CTHG-2-202-1')).toBeVisible()
  await expect(tt(page, 'CTHG-2-201-1')).toHaveCount(0)
  await cari.fill('')
  await expect(tt(page, 'CTHG-2-201-1')).toBeVisible()

  await tt(page, 'CTHG-2-201-2').getByRole('button', { name: 'Tandai kosong siap CTHG-2-201-2' }).click()
  const dialog = page.getByRole('dialog', { name: 'Tandai CTHG-2-201-2 kosong siap?' })
  await expect(dialog).toBeVisible()
  expect(await pelanggaranAxe(page)).toEqual([])
  await dialog.getByRole('button', { name: 'Tandai kosong siap' }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText('TT CTHG-2-201-2 kosong siap.')).toBeVisible()
  await expect(tt(page, 'CTHG-2-201-2')).toContainText('Kosong siap')
  await expect(tt(page, 'CTHG-2-201-2').getByRole('button')).toHaveCount(0)
  expect(await pelanggaranAxe(page)).toEqual([])
  await page.context().close()
})
