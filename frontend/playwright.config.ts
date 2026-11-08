import { defineConfig, devices } from '@playwright/test'

/**
 * E2E hanya ke lokal/CI dengan akun sintetis, atau staging (CLAUDE.md). Backend dijalankan
 * terpisah dan sudah di-seed AkunSintetisSeeder dengan AKUN_SINTETIS_SANDI = E2E_SANDI;
 * Vite dev server di bawah memproksi /api dan /sanctum ke SIMRS_API_URL.
 */
const LAYAR_FASE2 = /(penjamin|buku-tarif|jadwal-praktik|gabung-rm|formularium)\.spec\.ts/
/** Spec yang punya proyek berurutan sendiri; desktop/seluler mengabaikannya. */
const LAYAR_FASE2_ATAU_BERURUTAN = [/(pengaturan|fase1|pendaftaran|antrean|rawat-jalan)\.spec\.ts/, LAYAR_FASE2]
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
    { name: 'desktop', testIgnore: LAYAR_FASE2_ATAU_BERURUTAN, use: { ...devices['Desktop Chrome'] } },
    // Lebar 390 px (PRD: tanpa scroll samping).
    {
      name: 'seluler',
      testIgnore: LAYAR_FASE2_ATAU_BERURUTAN,
      use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
    },
    // Mengubah akses admisi dan mereset MFA mfa yang dipakai spec lain, jadi berjalan paling akhir.
    { name: 'pengaturan', testMatch: /pengaturan\.spec\.ts/, dependencies: ['desktop', 'seluler'], use: { ...devices['Desktop Chrome'] } },
    // 1.12-fe: semua layar fase 1 di empat lebar + axe, sesudah semua spec lain (data sintetis sudah terisi).
    { name: 'fase1', testMatch: /fase1\.spec\.ts/, dependencies: ['pengaturan'], use: { ...devices['Desktop Chrome'] } },
    // Menandai instalasi siap melayani selama uji (lokal:kunjungan-contoh), jadi berjalan sesudah semua spec lain.
    { name: 'pendaftaran', testMatch: /pendaftaran\.spec\.ts/, dependencies: ['fase1'], use: { ...devices['Desktop Chrome'] } },
    // 2.8-fe: keluar Tahap A (pendaftaran → SEP → antrean → display); memakai data contoh pendaftaran, jadi sesudahnya.
    { name: 'antrean', testMatch: /antrean\.spec\.ts/, dependencies: ['pendaftaran'], use: { ...devices['Desktop Chrome'] } },
    // Layar Fase 2 ke backend asli @1440 px + axe. Mengubah penjamin/tarif (syarat langkah 6 penyiapan) dan menandai siap
    // melayani, jadi paling akhir dan satu berkas sekali jalan.
    { name: 'fase2', testMatch: LAYAR_FASE2, dependencies: ['antrean'], workers: 1, use: { ...devices['Desktop Chrome'] } },
    // 2.13-fe: worklist poli + asesmen awal perawat (backend asli); memakai data contoh pendaftaran dan menandai siap melayani.
    { name: 'rawat-jalan', testMatch: /rawat-jalan\.spec\.ts/, dependencies: ['fase2'], workers: 1, use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: {
    command: `npm run dev -- --port ${port} --strictPort`,
    url: baseURL,
    reuseExistingServer: !process.env.CI,
    // e2e selalu memanggil backend sungguhan (atau page.route), bukan server tiruan bawaan dev.
    env: { VITE_API_TIRUAN: 'false' },
  },
})
