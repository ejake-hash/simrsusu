import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import LangkahMfaBelumTersedia from './LangkahMfaBelumTersedia.vue'

afterEach(() => vi.unstubAllEnvs())

describe('LangkahMfaBelumTersedia', () => {
  it('menyebut unit admin dari profil RS, bukan teks tetap', () => {
    vi.stubEnv('VITE_RS_UNIT_ADMIN', 'Instalasi SIMRS')
    const w = mount(LangkahMfaBelumTersedia, {
      props: { langkah: { status: 'perlu_mfa', tantangan_id: 't', metode: ['totp'], kedaluwarsa_pada: '' }, lanjut: vi.fn() },
    })

    expect(w.get('.lead').text()).toContain('Hubungi Instalasi SIMRS untuk masuk.')
    expect(w.text()).not.toContain('Unit TI')
  })
})
