import { afterEach, describe, expect, it } from 'vitest'
import { profilRsTiruan } from '@/modules/organisasi/tiruan/profilRs'
import { dariRespons, HALAMAN_MASUK_BAWAAN, PROFIL_BAWAAN, terapkanProfilRs, useProfilRs } from './profilRs'

afterEach(() => terapkanProfilRs(profilRsTiruan()))

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
      halamanMasuk: HALAMAN_MASUK_BAWAAN,
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
})
