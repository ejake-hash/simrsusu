import { AxiosError, AxiosHeaders, type AxiosAdapter } from 'axios'
import { afterEach, describe, expect, it } from 'vitest'
import { profilRsTiruan } from '@/modules/organisasi/tiruan/profilRs'
import { HALAMAN_MASUK_BAWAAN } from '@/modules/organisasi/halamanMasuk'
import { klien } from '@/shared/api/klien'
import { dariRespons, muatProfilRs, PROFIL_BAWAAN, terapkanProfilRs, useProfilRs } from './profilRs'

const adapterAsli = klien.defaults.adapter
afterEach(() => {
  klien.defaults.adapter = adapterAsli
  terapkanProfilRs(profilRsTiruan())
})

describe('profil RS dari GET /profil-rs', () => {
  it('memetakan nama, berkas identitas, kontak pengelola akun, dan tanda demo', () => {
    expect(
      dariRespons(
        profilRsTiruan({
          versi: 'v9',
          nama: ' RS Contoh ',
          instalasi_demo: true,
          paket_identitas: { logo_url: '/api/v1/profil-rs/berkas/logo?v=ab', motif_url: '/api/v1/profil-rs/berkas/motif?v=cd', foto_url: null },
          kontak_admin: { unit: 'Instalasi SIMRS', kontak: 'ext. 1234' },
        }),
      ),
    ).toEqual({
      versi: 'v9',
      nama: 'RS Contoh',
      logo: '/api/v1/profil-rs/berkas/logo?v=ab',
      motif: '/api/v1/profil-rs/berkas/motif?v=cd',
      foto: null,
      unitAdmin: 'Instalasi SIMRS',
      kontakAdmin: 'ext. 1234',
      demo: true,
      halamanMasuk: { ...HALAMAN_MASUK_BAWAAN, bawaan: true },
    })
  })

  it('instalasi kosong: nilai kosong jadi null, unit admin memakai bawaan', () => {
    expect(dariRespons(profilRsTiruan({ nama: '  ', kontak_admin: { unit: ' ', kontak: '' } }))).toEqual({ ...PROFIL_BAWAAN, versi: 'p1' })
  })

  it('terapkanProfilRs mengganti profil yang dibaca semua layar', () => {
    const profil = useProfilRs()
    terapkanProfilRs(profilRsTiruan({ nama: 'RS Baru', instalasi_demo: true }))
    expect(profil.value.nama).toBe('RS Baru')
    expect(profil.value.demo).toBe(true)
  })

  it('muatProfilRs: gagal saat mulai (502) dicoba lagi pada panggilan berikutnya; sesudah berhasil tidak mengambil ulang', async () => {
    terapkanProfilRs(profilRsTiruan({ instalasi_demo: false }))
    let panggilan = 0
    const adapter: AxiosAdapter = async (config) => {
      if (++panggilan === 1) {
        throw new AxiosError('gagal', 'ERR', config, null, { status: 502, statusText: '', headers: {}, config: { ...config, headers: new AxiosHeaders() }, data: '' })
      }
      return { data: { data: profilRsTiruan({ nama: 'RS Contoh Pendidikan', instalasi_demo: true }) }, status: 200, statusText: 'OK', headers: {}, config }
    }
    klien.defaults.adapter = adapter
    const profil = useProfilRs()

    await muatProfilRs()
    expect(profil.value.demo).toBe(false)
    await muatProfilRs()
    expect(profil.value).toMatchObject({ nama: 'RS Contoh Pendidikan', demo: true })
    await muatProfilRs()
    expect(panggilan).toBe(2)
  })
})
