import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/shared/api/klien'
import { konfirmasiDaftarMfa, mintaRahasiaMfa } from '../api'
import LangkahDaftarMfa from './LangkahDaftarMfa.vue'

vi.mock('../api', () => ({ mintaRahasiaMfa: vi.fn(), konfirmasiDaftarMfa: vi.fn(), ambilSaya: vi.fn(), keluar: vi.fn() }))

const RAHASIA = {
  uri_otpauth: 'otpauth://totp/SIMRS:contoh.mfa.baru?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=SIMRS',
  qr_svg: '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
}
const KODE_CADANGAN = Array.from({ length: 10 }, (_, i) => `aaaa${i}-bbbb${i}`)

async function pasang() {
  const lanjut = vi.fn().mockResolvedValue(undefined)
  const w = mount(LangkahDaftarMfa, {
    props: { langkah: { status: 'perlu_daftar_mfa', challenge_id: 't-9', kedaluwarsa_pada: '' }, lanjut },
    attachTo: document.body,
  })
  await flushPromises()
  return { w, lanjut }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(mintaRahasiaMfa).mockReset().mockResolvedValue(RAHASIA)
  vi.mocked(konfirmasiDaftarMfa).mockReset()
})

describe('LangkahDaftarMfa', () => {
  it('meminta rahasia saat dipasang; QR lewat <img>, kunci manual dikelompokkan', async () => {
    const { w } = await pasang()

    expect(mintaRahasiaMfa).toHaveBeenCalledWith('t-9')
    const img = w.get('img')
    expect(img.attributes('src')).toMatch(/^data:image\/svg\+xml;charset=utf-8,/)
    expect(img.attributes('alt')).toBe('Kode QR untuk aplikasi autentikator')
    expect(w.html()).not.toContain('<rect')
    expect(w.get('[data-testid="kunci-manual"]').text()).toBe('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')
    expect(document.activeElement).toBe(w.get('#kode-daftar-mfa').element)
  })

  it('konfirmasi → 10 kode cadangan sekali tampil; lanjut hanya setelah dicentang', async () => {
    vi.mocked(konfirmasiDaftarMfa).mockResolvedValue({ langkah: { status: 'masuk' }, kodeCadangan: KODE_CADANGAN })
    const { w, lanjut } = await pasang()

    await w.get('#kode-daftar-mfa').setValue('123456')
    await w.get('form').trigger('submit')
    await flushPromises()

    expect(konfirmasiDaftarMfa).toHaveBeenCalledWith('t-9', '123456')
    expect(w.get('h1').text()).toBe('Simpan kode cadangan')
    expect(w.findAll('[data-testid="kode-cadangan"] li')).toHaveLength(10)
    expect(w.find('img').exists()).toBe(false)

    const tombol = w.get('button.tombol-utama')
    expect(tombol.attributes('disabled')).toBeDefined()
    await tombol.trigger('click')
    expect(lanjut).not.toHaveBeenCalled()

    await w.get('input[type="checkbox"]').setValue(true)
    await w.get('button.tombol-utama').trigger('click')
    await flushPromises()
    expect(lanjut).toHaveBeenCalledWith({ status: 'masuk' })
  })

  it('kode salah saat konfirmasi: tetap di langkah pindai dengan pesan', async () => {
    vi.mocked(konfirmasiDaftarMfa).mockRejectedValue(new ApiError(401, 'kode_mfa_salah', 'x'))
    const { w } = await pasang()
    await w.get('#kode-daftar-mfa').setValue('000000')
    await w.get('form').trigger('submit')
    await flushPromises()

    expect(w.get('[role="alert"]').text()).toContain('Kode salah.')
    expect(w.find('img').exists()).toBe(true)
  })

  it('gagal meminta rahasia: pesan dan tombol coba lagi', async () => {
    vi.mocked(mintaRahasiaMfa).mockReset().mockRejectedValueOnce(new ApiError(0, 'jaringan', 'Tidak dapat terhubung ke server.'))
    const { w } = await pasang()

    expect(w.get('[role="alert"]').text()).toContain('Tidak dapat terhubung ke server.')
    vi.mocked(mintaRahasiaMfa).mockResolvedValue(RAHASIA)
    await w.get('button.tombol-kedua').trigger('click')
    await flushPromises()
    expect(w.find('img').exists()).toBe(true)
  })
})
