import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError } from '@/shared/api/klien'
import { ambilSaya, kirimSandi } from '../api'
import MasukPage from './MasukPage.vue'

vi.mock('../api', () => ({ kirimSandi: vi.fn(), ambilSaya: vi.fn(), keluar: vi.fn(), kirimKodeMfa: vi.fn(), mintaRahasiaMfa: vi.fn(), konfirmasiDaftarMfa: vi.fn() }))

async function pasang(kembali?: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/masuk', name: 'masuk', component: MasukPage },
      { path: '/', name: 'beranda', component: { template: '<p>beranda</p>' } },
      { path: '/pasien', component: { template: '<p>pasien</p>' } },
    ],
  })
  await router.push({ name: 'masuk', query: kembali ? { kembali } : {} })
  const w = mount(MasukPage, { global: { plugins: [router] }, attachTo: document.body })
  return { w, router }
}

async function kirim(w: Awaited<ReturnType<typeof pasang>>['w']) {
  await w.get('#nama-pengguna').setValue('contoh.dokter')
  await w.get('#sandi').setValue('sandi-panjang-sekali')
  await w.get('form').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(kirimSandi).mockReset()
  vi.mocked(ambilSaya).mockReset().mockResolvedValue({
    id: 'p-1',
    nama_pengguna: 'contoh.dokter',
    email: null,
    login_terakhir_pada: null,
    mfa_aktif: false,
    pegawai: { id: 'g-1', nama: 'Contoh Dokter' },
  })
})

describe('MasukPage (mesin status PRD 10.3.2)', () => {
  it('sandi → masuk: memuat /saya lalu kembali ke rute asal', async () => {
    vi.mocked(kirimSandi).mockResolvedValue({ status: 'masuk' })
    const { w, router } = await pasang('/pasien')

    await kirim(w)

    expect(ambilSaya).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.fullPath).toBe('/pasien')
  })

  it('tanpa rute asal diarahkan ke beranda', async () => {
    vi.mocked(kirimSandi).mockResolvedValue({ status: 'masuk' })
    const { w, router } = await pasang()

    await kirim(w)
    expect(router.currentRoute.value.name).toBe('beranda')
  })

  it('perlu_mfa pindah ke langkah MFA, kembali ke langkah sandi dengan isian bersih', async () => {
    vi.mocked(kirimSandi).mockResolvedValue({
      status: 'perlu_mfa',
      challenge_id: 't-1',
      metode: ['totp'],
      kedaluwarsa_pada: '2026-10-10T10:05:00+07:00',
    })
    const { w, router } = await pasang()

    await kirim(w)
    expect(w.get('h1').text()).toBe('Masukkan kode 6 digit')
    expect(ambilSaya).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('masuk')

    await w.get('button.kembali').trigger('click')
    expect(w.get('h1').text()).toBe('Masuk ke akun Anda')
    expect((w.get('#nama-pengguna').element as HTMLInputElement).value).toBe('')
  })

  it('/saya gagal setelah sandi diterima: tetap di langkah sandi dengan pesan', async () => {
    vi.mocked(kirimSandi).mockResolvedValue({ status: 'masuk' })
    vi.mocked(ambilSaya).mockRejectedValue(new ApiError(503, 'layanan_tidak_tersedia', 'Layanan sedang tidak tersedia.'))
    const { w, router } = await pasang()

    await kirim(w)
    expect(router.currentRoute.value.name).toBe('masuk')
    expect(w.get('[role="alert"]').text()).toContain('Layanan sedang tidak tersedia.')
  })
})
