import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { GalatApi } from '@/shared/api/klien'
import { kirimSandi } from '../api'
import type { Langkah } from '../alurMasuk'
import LangkahSandi from './LangkahSandi.vue'

vi.mock('../api', () => ({ kirimSandi: vi.fn(), ambilSaya: vi.fn(), keluar: vi.fn() }))

function pasang(lanjut: (l: Langkah) => Promise<void> = vi.fn().mockResolvedValue(undefined)) {
  return mount(LangkahSandi, {
    props: { langkah: { status: 'sandi' }, lanjut },
    attachTo: document.body,
  })
}

async function isiDanKirim(w: ReturnType<typeof pasang>, nama = 'contoh.perawat', sandi = 'sandi-panjang-sekali') {
  await w.get('#nama-pengguna').setValue(nama)
  await w.get('#sandi').setValue(sandi)
  await w.get('form').trigger('submit')
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(kirimSandi).mockReset()
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllEnvs()
  document.body.innerHTML = ''
})

describe('LangkahSandi', () => {
  it('fokus awal di nama pengguna, dengan label dan autocomplete yang benar', () => {
    const w = pasang()
    expect(document.activeElement).toBe(w.get('#nama-pengguna').element)
    expect(w.get('label[for="nama-pengguna"]').text()).toBe('Nama pengguna')
    expect(w.get('label[for="sandi"]').text()).toBe('Kata sandi')
    expect(w.get('#nama-pengguna').attributes('autocomplete')).toBe('username')
    expect(w.get('#sandi').attributes('autocomplete')).toBe('current-password')
  })

  it('isian kosong ditandai di field, tidak mengirim ke server', async () => {
    const w = pasang()
    await w.get('form').trigger('submit')

    expect(kirimSandi).not.toHaveBeenCalled()
    expect(w.get('#nama-pengguna').attributes('aria-invalid')).toBe('true')
    expect(w.get('#nama-pengguna').attributes('aria-describedby')).toBe('nama-pengguna-galat')
    expect(w.get('#nama-pengguna-galat').text()).toBe('Nama pengguna wajib diisi.')
    expect(w.get('#sandi-galat').text()).toBe('Kata sandi wajib diisi.')
    expect(document.activeElement).toBe(w.get('#nama-pengguna').element)
  })

  it('tombol nonaktif dengan indikator selama proses, lalu aktif lagi', async () => {
    let selesai!: (l: Langkah) => void
    vi.mocked(kirimSandi).mockReturnValue(new Promise((r) => (selesai = r)))
    const lanjut = vi.fn().mockResolvedValue(undefined)
    const w = pasang(lanjut)

    await isiDanKirim(w)
    const tombol = w.get('button[type="submit"]')
    expect(tombol.attributes('disabled')).toBeDefined()
    expect(tombol.text()).toBe('Memproses…')
    expect(tombol.find('.spinner').exists()).toBe(true)

    // Enter lagi saat proses tidak mengirim dua kali.
    await w.get('form').trigger('submit')
    expect(kirimSandi).toHaveBeenCalledTimes(1)

    selesai({ status: 'masuk' })
    await flushPromises()
    expect(lanjut).toHaveBeenCalledWith({ status: 'masuk' })
    expect(tombol.attributes('disabled')).toBeUndefined()
    expect(tombol.text()).toBe('Masuk')
  })

  it('kredensial salah: pesan di dekat form, sandi dikosongkan dan difokus', async () => {
    vi.mocked(kirimSandi).mockRejectedValue(new GalatApi(401, 'kredensial_salah', 'Nama pengguna atau sandi salah.'))
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()

    expect(w.get('[role="alert"]').text()).toContain('Nama pengguna atau sandi salah.')
    expect((w.get('#sandi').element as HTMLInputElement).value).toBe('')
    expect((w.get('#nama-pengguna').element as HTMLInputElement).value).toBe('contoh.perawat')
    expect(document.activeElement).toBe(w.get('#sandi').element)
  })

  it('akun terkunci menampilkan sisa waktu dari server dan menghitung mundur', async () => {
    vi.useFakeTimers()
    vi.mocked(kirimSandi).mockRejectedValue(new GalatApi(403, 'akun_terkunci', 'Akun terkunci sementara.', [], 900))
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toContain('Akun terkunci sementara. Coba lagi dalam 15 menit.')

    await vi.advanceTimersByTimeAsync(6 * 60 * 1000)
    expect(w.get('[role="alert"]').text()).toContain('Coba lagi dalam 9 menit.')

    await vi.advanceTimersByTimeAsync(9 * 60 * 1000)
    expect(w.get('[role="alert"]').text()).toContain('Anda sudah bisa mencoba lagi.')
  })

  it('galat validasi server dipasang ke field-nya', async () => {
    vi.mocked(kirimSandi).mockRejectedValue(
      new GalatApi(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'nama_pengguna', pesan: 'Isian terlalu panjang.' }]),
    )
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    expect(w.get('#nama-pengguna-galat').text()).toBe('Isian terlalu panjang.')
  })

  it('tombol tampilkan sandi mengganti tipe input dan aria-pressed', async () => {
    const w = pasang()
    const tombol = w.get('button[aria-controls="sandi"]')
    expect(w.get('#sandi').attributes('type')).toBe('password')

    await tombol.trigger('click')
    expect(w.get('#sandi').attributes('type')).toBe('text')
    expect(tombol.attributes('aria-pressed')).toBe('true')

    await tombol.trigger('click')
    expect(w.get('#sandi').attributes('type')).toBe('password')
    expect(tombol.attributes('aria-pressed')).toBe('false')
  })

  it('teks bantuan menyebut kontak admin dari profil RS', () => {
    vi.stubEnv('VITE_RS_KONTAK_ADMIN', 'ext. 1234')
    expect(pasang().get('.bantuan').text()).toBe('Lupa sandi atau akun terkunci? Hubungi admin SIMRS di ext. 1234.')
  })

  it('teks bantuan tanpa kontak bila profil RS tidak mengisinya', () => {
    vi.stubEnv('VITE_RS_KONTAK_ADMIN', '')
    expect(pasang().get('.bantuan').text()).toBe('Lupa sandi atau akun terkunci? Hubungi admin SIMRS.')
  })

  it('peringatan Caps Lock muncul saat aktif dan hilang saat blur', async () => {
    const w = pasang()
    const sandi = w.get('#sandi')

    const tombolCaps = new KeyboardEvent('keydown', { key: 'A', modifierCapsLock: true })
    sandi.element.dispatchEvent(tombolCaps)
    await w.vm.$nextTick()
    expect(w.get('#sandi-caps').text()).toBe('Caps Lock aktif')
    expect(sandi.attributes('aria-describedby')).toBe('sandi-caps')

    await sandi.trigger('blur')
    expect(w.find('#sandi-caps').exists()).toBe(false)
  })
})
