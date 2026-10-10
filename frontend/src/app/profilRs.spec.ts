import { describe, expect, it } from 'vitest'
import { profilRsDariEnv } from './profilRs'

describe('profilRsDariEnv', () => {
  it('membaca nama, logo, dan kontak admin', () => {
    expect(
      profilRsDariEnv({ VITE_RS_NAMA: ' RS Contoh ', VITE_RS_LOGO: '/logo/rs.png', VITE_RS_KONTAK_ADMIN: 'ext. 1234' }),
    ).toEqual({ nama: 'RS Contoh', logo: '/logo/rs.png', kontakAdmin: 'ext. 1234' })
  })

  it('nilai kosong menjadi null', () => {
    expect(profilRsDariEnv({ VITE_RS_NAMA: '  ', VITE_RS_KONTAK_ADMIN: '' })).toEqual({ nama: null, logo: null, kontakAdmin: null })
  })
})
