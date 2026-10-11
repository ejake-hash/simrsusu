import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSesiStore } from '@/app/sesi'
import { GalatApi } from '@/shared/api/klien'
import { kirimKodeMfa } from '../api'
import type { MetodeMfa } from '../alurMasuk'
import LangkahKodeMfa from './LangkahKodeMfa.vue'

vi.mock('../api', () => ({ kirimKodeMfa: vi.fn(), ambilSaya: vi.fn(), keluar: vi.fn() }))

function pasang(metode: MetodeMfa[] = ['totp', 'kode_cadangan']) {
  const lanjut = vi.fn().mockResolvedValue(undefined)
  const w = mount(LangkahKodeMfa, {
    props: { langkah: { status: 'perlu_mfa', tantangan_id: 't-1', metode, kedaluwarsa_pada: '' }, lanjut },
    attachTo: document.body,
  })
  return { w, lanjut }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(kirimKodeMfa).mockReset()
})

describe('LangkahKodeMfa', () => {
  it('fokus ke kolom kode; kode 6 digit dikirim ke server lalu lanjut', async () => {
    vi.mocked(kirimKodeMfa).mockResolvedValue({ status: 'masuk' })
    const { w, lanjut } = pasang()
    const input = w.get('#kode-mfa')
    expect(document.activeElement).toBe(input.element)
    expect(input.attributes('autocomplete')).toBe('one-time-code')
    expect(input.attributes('inputmode')).toBe('numeric')

    await input.setValue('123 456')
    await w.get('form').trigger('submit')
    await flushPromises()

    expect(kirimKodeMfa).toHaveBeenCalledWith('t-1', 'totp', '123456')
    expect(lanjut).toHaveBeenCalledWith({ status: 'masuk' })
  })

  it('kode belum 6 digit: tidak dikirim, pesan di kolom', async () => {
    const { w } = pasang()
    await w.get('#kode-mfa').setValue('12')
    await w.get('form').trigger('submit')

    expect(kirimKodeMfa).not.toHaveBeenCalled()
    expect(w.get('#kode-mfa-galat').text()).toBe('Masukkan 6 digit dari aplikasi autentikator.')
    expect(w.get('#kode-mfa').attributes('aria-invalid')).toBe('true')
  })

  it('beralih ke kode cadangan bila ditawarkan server', async () => {
    vi.mocked(kirimKodeMfa).mockResolvedValue({ status: 'masuk' })
    const { w } = pasang()
    await w.get('button.tautan').trigger('click')

    expect(w.get('h1').text()).toBe('Masukkan kode cadangan')
    await w.get('#kode-mfa').setValue(' abcde-fghjk ')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(kirimKodeMfa).toHaveBeenCalledWith('t-1', 'kode_cadangan', 'abcde-fghjk')
  })

  it('tanpa kode cadangan dari server: pilihan itu tidak ditampilkan', () => {
    const { w } = pasang(['totp'])
    expect(w.find('button.tautan').exists()).toBe(false)
  })

  it('kode salah: pesan di atas form, kolom dikosongkan dan difokuskan', async () => {
    vi.mocked(kirimKodeMfa).mockRejectedValue(new GalatApi(401, 'kode_mfa_salah', 'Kode verifikasi salah.'))
    const { w } = pasang()
    await w.get('#kode-mfa').setValue('000000')
    await w.get('form').trigger('submit')
    await flushPromises()

    expect(w.get('[role="alert"]').text()).toContain('Kode salah.')
    expect((w.get('#kode-mfa').element as HTMLInputElement).value).toBe('')
    expect(document.activeElement).toBe(w.get('#kode-mfa').element)
  })

  it('tantangan tidak berlaku: kembali ke langkah sandi dengan pesan', async () => {
    vi.mocked(kirimKodeMfa).mockRejectedValue(new GalatApi(401, 'tantangan_tidak_berlaku', 'x'))
    const { w } = pasang()
    await w.get('#kode-mfa').setValue('000000')
    await w.get('form').trigger('submit')
    await flushPromises()

    expect(w.emitted('kembali')).toHaveLength(1)
    expect(useSesiStore().pesanBerakhir).toBe('Waktu verifikasi habis. Silakan masuk lagi.')
  })
})
