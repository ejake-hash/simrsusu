import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/shared/api/klien'
import { kirimSandi } from '../api'
import type { Langkah } from '../alurMasuk'
import LangkahSandi from './LangkahSandi.vue'
import { terapkanProfilRs } from '@/app/profilRs'
import { profilRsTiruan } from '@/modules/organisasi/tiruan/profilRs'

/** Profil RS (GET /profil-rs) dengan unit pengelola akun dan kontaknya; kosong = nilai bawaan. */
const pasangProfil = (unit: string, kontak: string) => terapkanProfilRs(profilRsTiruan({ kontak_admin: { unit, kontak: kontak || null } }))

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
    expect(w.get('#nama-pengguna').attributes('aria-describedby')).toBe('nama-pengguna-error')
    expect(w.get('#nama-pengguna-error').text()).toBe('Nama pengguna wajib diisi.')
    expect(w.get('#sandi-error').text()).toBe('Kata sandi wajib diisi.')
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
    expect(tombol.find('.tombol-pemutar').exists()).toBe(true)
    expect(tombol.attributes('aria-busy')).toBe('true')

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
    vi.mocked(kirimSandi).mockRejectedValue(new ApiError(401, 'kredensial_salah', 'Nama pengguna atau sandi salah.'))
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()

    expect(w.get('[role="alert"]').text()).toContain('Nama pengguna atau sandi salah.')
    expect((w.get('#sandi').element as HTMLInputElement).value).toBe('')
    expect((w.get('#nama-pengguna').element as HTMLInputElement).value).toBe('contoh.perawat')
    expect(document.activeElement).toBe(w.get('#sandi').element)
  })

  it('akun terkunci: kalimat frontend + satu baris sisa waktu dari server, menghitung mundur', async () => {
    vi.useFakeTimers()
    vi.mocked(kirimSandi).mockRejectedValue(
      new ApiError(403, 'akun_terkunci', 'Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi nanti atau hubungi admin.', [], 900),
    )
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    const alert = () => w.get('[role="alert"]').text()
    expect(alert()).toBe('Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.')
    expect(w.get('#baris-tunggu').text()).toBe('Coba lagi dalam 15 menit.')

    await vi.advanceTimersByTimeAsync(6 * 60 * 1000)
    expect(alert()).toContain('Coba lagi dalam 9 menit.')

    await vi.advanceTimersByTimeAsync(9 * 60 * 1000)
    expect(alert()).toBe('Akun terkunci sementara karena terlalu banyak percobaan gagal. Anda sudah bisa mencoba lagi.')
  })

  it('rate limit 429: kalimat frontend, tanpa "Coba lagi" ganda', async () => {
    vi.useFakeTimers()
    vi.mocked(kirimSandi).mockRejectedValue(
      new ApiError(429, 'terlalu_banyak_permintaan', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.', [], 30),
    )
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    const teks = w.get('[role="alert"]').text()
    expect(teks).toBe('Terlalu banyak percobaan masuk. Coba lagi dalam kurang dari 1 menit.')
    expect(teks.match(/Coba lagi/g)).toHaveLength(1)
  })

  it('akun terkunci tanpa Retry-After: "Coba lagi nanti." dan tombol tetap aktif', async () => {
    vi.mocked(kirimSandi).mockRejectedValue(new ApiError(403, 'akun_terkunci', 'Akun terkunci.'))
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toBe('Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi nanti.')
    expect(w.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('tombol Masuk nonaktif selama hitung mundur, aktif lagi saat habis tanpa muat ulang', async () => {
    vi.useFakeTimers()
    vi.mocked(kirimSandi).mockRejectedValue(new ApiError(403, 'akun_terkunci', 'Akun terkunci.', [], 90))
    const w = pasang()
    const tombol = () => w.get('button[type="submit"]')

    await isiDanKirim(w)
    await flushPromises()
    expect(tombol().attributes('disabled')).toBeDefined()
    expect(tombol().attributes('aria-describedby')).toBe('baris-tunggu')
    expect(tombol().text()).toBe('Masuk')

    // Enter selama hitung mundur tidak mengirim.
    await w.get('form').trigger('submit')
    expect(kirimSandi).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(60 * 1000)
    expect(tombol().attributes('disabled')).toBeDefined()

    await vi.advanceTimersByTimeAsync(31 * 1000)
    expect(tombol().attributes('disabled')).toBeUndefined()
    expect(tombol().attributes('aria-describedby')).toBeUndefined()

    vi.mocked(kirimSandi).mockResolvedValue({ status: 'masuk' })
    await w.get('form').trigger('submit')
    expect(kirimSandi).toHaveBeenCalledTimes(2)
  })

  it('MFA belum terdaftar dari luar jaringan RS: arahkan ke jaringan RS atau admin, tombol tetap aktif', async () => {
    pasangProfil('Instalasi SIMRS', '')
    vi.mocked(kirimSandi).mockRejectedValue(new ApiError(403, 'mfa_belum_terdaftar', 'Verifikasi dua langkah belum aktif.'))
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toBe(
      'Verifikasi dua langkah akun ini belum aktif, dan hanya bisa diaktifkan dari jaringan rumah sakit. ' +
        'Masuk sekali dari komputer di rumah sakit, atau minta Instalasi SIMRS mereset verifikasi Anda.',
    )
    expect(w.find('#baris-tunggu').exists()).toBe(false)
    expect(w.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('konfigurasi belum lengkap (503): instalasi belum siap, hubungi admin', async () => {
    pasangProfil('', '')
    vi.mocked(kirimSandi).mockRejectedValue(new ApiError(503, 'konfigurasi_belum_lengkap', 'SIMRS belum selesai disiapkan.'))
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toBe('SIMRS belum selesai disiapkan sehingga belum bisa dipakai untuk masuk. Hubungi admin SIMRS.')
  })

  it('error validasi server dipasang ke field-nya', async () => {
    vi.mocked(kirimSandi).mockRejectedValue(
      new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'nama_pengguna', pesan: 'Isian terlalu panjang.' }]),
    )
    const w = pasang()

    await isiDanKirim(w)
    await flushPromises()
    expect(w.get('#nama-pengguna-error').text()).toBe('Isian terlalu panjang.')
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
    pasangProfil('', 'ext. 1234')
    expect(pasang().get('.bantuan').text()).toBe('Lupa sandi atau akun terkunci? Hubungi admin SIMRS di ext. 1234.')
  })

  it('lupa sandi: dijelaskan reset lewat petugas dengan verifikasi tatap muka (tiket 1.14), bukan email', () => {
    pasangProfil('', '')
    expect(pasang().get('.bantuan-rinci').text()).toBe('Petugas memverifikasi identitas Anda secara langsung, lalu memberi sandi sementara yang wajib diganti saat masuk.')
  })

  it('teks bantuan tanpa kontak bila profil RS tidak mengisinya', () => {
    pasangProfil('', '')
    expect(pasang().get('.bantuan').text()).toBe('Lupa sandi atau akun terkunci? Hubungi admin SIMRS.')
  })

  it('nama unit dari profil RS dipakai di subjudul, bantuan, dan pesan error', async () => {
    pasangProfil('Instalasi SIMRS', '')
    vi.mocked(kirimSandi).mockRejectedValue(new Error('bentuk respons aneh'))
    const w = pasang()

    expect(w.get('.lead').text()).toBe('Gunakan nama pengguna yang diberikan Instalasi SIMRS.')
    expect(w.get('.bantuan').text()).toBe('Lupa sandi atau akun terkunci? Hubungi Instalasi SIMRS.')

    await isiDanKirim(w)
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toBe('Respons server tidak dikenali. Coba lagi atau hubungi Instalasi SIMRS.')
    expect(w.text()).not.toContain('Unit TI')
  })

  it('profil RS tanpa unit pengelola akun memakai "admin SIMRS"', () => {
    pasangProfil('', '')
    expect(pasang().get('.lead').text()).toBe('Gunakan nama pengguna yang diberikan admin SIMRS.')
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
