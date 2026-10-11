import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ambilSaya } from '@/modules/akses/api'
import { ApiError } from '@/shared/api/klien'
import { useSesiStore } from './sesi'

vi.mock('@/modules/akses/api', () => ({ ambilSaya: vi.fn(), keluar: vi.fn(), kirimSandi: vi.fn() }))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(ambilSaya).mockReset()
})

describe('useSesiStore.pastikan', () => {
  it('401 = belum masuk, tidak ditanya ulang', async () => {
    vi.mocked(ambilSaya).mockRejectedValue(new ApiError(401, 'belum_masuk', 'Silakan masuk terlebih dahulu.'))
    const sesi = useSesiStore()

    await sesi.pastikan()
    await sesi.pastikan()

    expect(sesi.pengguna).toBeNull()
    expect(ambilSaya).toHaveBeenCalledTimes(1)
  })

  it('server tak terjangkau = belum masuk tanpa melempar, dicek lagi berikutnya', async () => {
    vi.mocked(ambilSaya).mockRejectedValue(new ApiError(0, 'jaringan', 'Tidak dapat terhubung ke server.'))
    const sesi = useSesiStore()

    await expect(sesi.pastikan()).resolves.toBeUndefined()
    expect(sesi.pengguna).toBeNull()

    await sesi.pastikan()
    expect(ambilSaya).toHaveBeenCalledTimes(2)
  })

  it('sesi aktif memuat pengguna', async () => {
    vi.mocked(ambilSaya).mockResolvedValue({
      id: 'p-1',
      nama_pengguna: 'contoh.admisi',
      email: null,
      login_terakhir_pada: null,
      mfa_aktif: false,
      pegawai: null,
    })
    const sesi = useSesiStore()

    await sesi.pastikan()
    expect(sesi.pengguna?.id).toBe('p-1')
  })
})
