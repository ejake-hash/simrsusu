import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { tanpaGulirSamping } from './bantu'

/**
 * Katalog komponen design system (tiket 1.11). Rute /katalog hanya ada di mode pengembangan (Playwright menjalankan
 * `vite` dev); tidak butuh masuk. Uji: axe tanpa pelanggaran serius/kritis, tanpa gulir samping halaman.
 */
test.beforeAll(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'lebar layar diatur per uji')
})

for (const [lebar, tinggi] of [
  [1440, 900],
  [768, 1024],
  [390, 844],
] as const) {
  test(`${lebar} px: katalog tanpa pelanggaran axe serius/kritis dan tanpa gulir samping`, async ({ page }) => {
    await page.setViewportSize({ width: lebar, height: tinggi })
    await page.goto('/katalog')
    await expect(page.getByRole('heading', { level: 1, name: 'Katalog komponen' })).toBeVisible()
    await tanpaGulirSamping(page)
    const hasil = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
    const berat = hasil.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
    expect(berat.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([])
  })
}

test('komponen interaktif di katalog: toast, pohon dengan keyboard, pencarian popover, dialog', async ({ page }) => {
  await page.goto('/katalog')
  await page.getByRole('button', { name: 'Berhasil' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Perubahan tersimpan.' })).toBeVisible()

  const pohon = page.getByRole('tree', { name: 'Contoh lokasi' })
  await pohon.getByRole('treeitem', { name: /Gedung A/ }).first().focus()
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('ArrowRight')
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Enter')
  await expect(page.getByText('Terpilih: g1l1r1')).toBeVisible()

  await page.getByRole('button', { name: 'Cari contoh' }).click()
  await page.keyboard.type('akun')
  await expect(page.getByRole('option', { name: /Akun & akses/ })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('button', { name: 'Cari contoh' })).toBeFocused()

  await page.getByRole('button', { name: 'Buka dialog' }).click()
  await expect(page.getByRole('dialog', { name: 'Contoh dialog' })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('button', { name: 'Buka dialog' })).toBeFocused()
})
