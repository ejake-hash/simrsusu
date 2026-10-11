import { AxiosError, AxiosHeaders, type AxiosAdapter } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import { aturPenangan401, keApiError, klien } from './klien'

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
