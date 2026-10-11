import { expect, type APIRequestContext, type Page } from '@playwright/test'
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
