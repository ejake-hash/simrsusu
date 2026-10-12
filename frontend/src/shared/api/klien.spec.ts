import { AxiosError, AxiosHeaders, type AxiosAdapter } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import { aturPenangan401, aturPenanganMfaUlang, keApiError, klien } from './klien'

function errorAxios(status: number, data: unknown, headers: Record<string, string> = {}, configAsal?: object) {
  const config = { headers: new AxiosHeaders(), ...configAsal }
  return new AxiosError('gagal', 'ERR', config, null, {
    status,
    statusText: '',
    data,
    headers,
    config,
  })
}

describe('keApiError', () => {
  it('membaca format error tunggal dan Retry-After', () => {
    const g = keApiError(
      errorAxios(403, { error: { kode: 'akun_terkunci', pesan: 'Akun terkunci.', rincian: [] } }, { 'retry-after': '840' }),
    )
    expect(g).toMatchObject({ status: 403, kode: 'akun_terkunci', message: 'Akun terkunci.', cobaLagiDetik: 840 })
  })

  it('rincian validasi diteruskan', () => {
    const g = keApiError(
      errorAxios(422, { error: { kode: 'validasi_gagal', pesan: 'Isian belum benar.', rincian: [{ kolom: 'sandi', pesan: 'Sandi wajib diisi.' }] } }),
    )
    expect(g.rincian).toEqual([{ kolom: 'sandi', pesan: 'Sandi wajib diisi.' }])
    expect(g.cobaLagiDetik).toBeNull()
  })

  it('tanpa respons = error jaringan', () => {
    expect(keApiError(new AxiosError('Network Error'))).toMatchObject({ status: 0, kode: 'jaringan' })
  })
})

describe('penangan 401', () => {
  const tolak401: AxiosAdapter = async (config) => {
    throw errorAxios(401, { error: { kode: 'sesi_kedaluwarsa', pesan: 'Sesi berakhir.', rincian: [] } }, {}, config)
  }

  it('401 dari endpoint mana pun memanggil penangan sesi berakhir', async () => {
    const penangan = vi.fn()
    aturPenangan401(penangan)

    await expect(klien.get('/pasien', { adapter: tolak401 })).rejects.toMatchObject({ kode: 'sesi_kedaluwarsa' })
    expect(penangan).toHaveBeenCalledWith(expect.objectContaining({ status: 401, message: 'Sesi berakhir.' }))
  })

  it('kecuali permintaan yang menangani 401 sendiri (sandi salah, cek sesi awal)', async () => {
    const penangan = vi.fn()
    aturPenangan401(penangan)

    await expect(klien.post('/autentikasi/masuk', {}, { adapter: tolak401, tangani401Sendiri: true })).rejects.toBeTruthy()
    expect(penangan).not.toHaveBeenCalled()
  })
})

describe('penangan MFA ulang (tiket 1.5, K11)', () => {
  /** Menolak dengan 403 mfa_ulang_diperlukan sampai permintaannya ditandai sudah MFA ulang. */
  function adapterMfa(tetapMenolak = false): { adapter: AxiosAdapter; panggilan: () => number } {
    let n = 0
    const adapter: AxiosAdapter = async (config) => {
      n++
      if (tetapMenolak || !config.sudahMfaUlang) {
        throw errorAxios(403, { error: { kode: 'mfa_ulang_diperlukan', pesan: 'Verifikasi MFA ulang diperlukan.', rincian: [] } }, {}, config)
      }
      return { data: { data: 'ok' }, status: 200, statusText: 'OK', headers: {}, config }
    }
    return { adapter, panggilan: () => n }
  }

  it('meminta kode lewat penangan lalu mengulang permintaan yang sama sekali', async () => {
    const penangan = vi.fn().mockResolvedValue(true)
    aturPenanganMfaUlang(penangan)
    const { adapter, panggilan } = adapterMfa()

    const r = await klien.post('/akun/1/mfa/reset', { alasan: 'x' }, { adapter })
    expect(r.data).toEqual({ data: 'ok' })
    expect(penangan).toHaveBeenCalledTimes(1)
    expect(panggilan()).toBe(2)
  })

  it('dibatalkan: tanpa mengulang, pemanggil menerima pesan pembatalan yang jelas', async () => {
    aturPenanganMfaUlang(vi.fn().mockResolvedValue(false))
    const { adapter, panggilan } = adapterMfa()

    await expect(klien.post('/akun/1/mfa/reset', {}, { adapter })).rejects.toMatchObject({
      status: 403,
      kode: 'mfa_ulang_dibatalkan',
      message: 'Aksi dibatalkan karena verifikasi dua langkah tidak diselesaikan.',
    })
    expect(panggilan()).toBe(1)
  })

  it("masa verifikasi menurut klien sudah lewat di server: pengulangan 'masa' yang tetap 403 membuka dialog sekali", async () => {
    // Jam klien tertinggal: klien mengira masa masih berlaku, server menolak lagi.
    const penangan = vi.fn().mockImplementation(async (paksa: boolean) => (paksa ? 'dialog' : 'masa'))
    aturPenanganMfaUlang(penangan)
    let n = 0
    const adapter: AxiosAdapter = async (config) => {
      n++
      if (config.sudahMfaUlang !== true) {
        throw errorAxios(403, { error: { kode: 'mfa_ulang_diperlukan', pesan: 'Verifikasi MFA ulang diperlukan.', rincian: [] } }, {}, config)
      }
      return { data: { data: 'ok' }, status: 200, statusText: 'OK', headers: {}, config }
    }

    await expect(klien.post('/aksi', {}, { adapter })).resolves.toMatchObject({ data: { data: 'ok' } })
    expect(penangan.mock.calls).toEqual([[false], [true]])
    expect(n).toBe(3)
  })

  it('polling latar (X-Permintaan-Latar) tidak membuka dialog', async () => {
    const penangan = vi.fn().mockResolvedValue(true)
    aturPenanganMfaUlang(penangan)
    const { adapter, panggilan } = adapterMfa()

    await expect(klien.get('/saya/notifikasi', { adapter, headers: { 'x-permintaan-latar': '1' } })).rejects.toMatchObject({ kode: 'mfa_ulang_diperlukan' })
    expect(penangan).not.toHaveBeenCalled()
    expect(panggilan()).toBe(1)
  })

  it('ditolak lagi sesudah verifikasi: tidak membuka dialog berulang', async () => {
    const penangan = vi.fn().mockResolvedValue(true)
    aturPenanganMfaUlang(penangan)
    const { adapter, panggilan } = adapterMfa(true)

    await expect(klien.post('/akun/1/mfa/reset', {}, { adapter })).rejects.toMatchObject({ kode: 'mfa_ulang_diperlukan' })
    expect(penangan).toHaveBeenCalledTimes(1)
    expect(panggilan()).toBe(2)
  })
})
