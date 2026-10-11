import { describe, expect, it } from 'vitest'
import { profilRsDariEnv } from './profilRs'

describe('profilRsDariEnv', () => {
  it('membaca nama, logo, dan kontak admin', () => {
    expect(
      profilRsDariEnv({
        VITE_RS_NAMA: ' RS Contoh ',
        VITE_RS_LOGO: '/logo/rs.png',
        VITE_RS_MOTIF: '/motif/rs.svg',
        VITE_RS_FOTO: '/foto/rs.webp',
        VITE_RS_UNIT_ADMIN: 'Instalasi SIMRS',
        VITE_RS_KONTAK_ADMIN: 'ext. 1234',
        VITE_INSTALASI_DEMO: 'true',
      }),
    ).toEqual({
      nama: 'RS Contoh',
      logo: '/logo/rs.png',
      motif: '/motif/rs.svg',
      foto: '/foto/rs.webp',
      unitAdmin: 'Instalasi SIMRS',
      kontakAdmin: 'ext. 1234',
      demo: true,
    })
  })

  it('nilai kosong menjadi null; unit admin memakai bawaan', () => {
    expect(profilRsDariEnv({ VITE_RS_NAMA: '  ', VITE_RS_UNIT_ADMIN: ' ', VITE_RS_KONTAK_ADMIN: '' })).toEqual({
      nama: null,
      logo: null,
      motif: null,
      foto: null,
      unitAdmin: 'admin SIMRS',
      kontakAdmin: null,
      demo: false,
    })
  })
})
