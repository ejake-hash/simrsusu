import { defineConfig, devices } from '@playwright/test'

/**
 * E2E hanya ke lokal/CI dengan akun sintetis, atau staging (CLAUDE.md). Backend dijalankan
 * terpisah dan sudah di-seed AkunSintetisSeeder dengan AKUN_SINTETIS_SANDI = E2E_SANDI;
 * Vite dev server di bawah memproksi /api dan /sanctum ke SIMRS_API_URL.
 */
const port = Number(process.env.E2E_PORT ?? 5173)
const baseURL = `http://localhost:${port}`

export default defineConfig({
  testDir: './e2e',
  forbidOnly: !!process.env.CI,
  // Tanpa retry: uji "terkunci" mengubah status akun, mengulang hanya menutupi masalah.
  retries: 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL,
    locale: 'id-ID',
    timezoneId: 'Asia/Jakarta',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    // Lebar 390 px (PRD: tanpa scroll samping).
    { name: 'seluler', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
  webServer: {
    command: `npm run dev -- --port ${port} --strictPort`,
    url: baseURL,
    reuseExistingServer: !process.env.CI,
  },
})
