import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ambilMenuSaya, lupakanMenuSaya } from '@/modules/akses/api'
import { menuSayaTiruan } from '@/modules/akses/tiruan/menuSaya'
import { klien } from '@/shared/api/klien'
import { cariHalaman, cariPosisi } from './menu'

const grup = menuSayaTiruan.grup

describe('cariPosisi / cariHalaman', () => {
  it('beranda hanya cocok dengan "/" persis', () => {
    expect(cariPosisi(grup, '/')?.menu.kode).toBe('beranda')
    expect(cariPosisi(grup, '/kasir')?.menu.kode).toBe('kasir')
  })

  it('sub-menu menandai menu induknya; garis miring akhir diabaikan', () => {
    const posisi = cariPosisi(grup, '/data-induk/akun/')
    expect(posisi?.menu.kode).toBe('data_induk')
    expect(posisi?.sub?.kode).toBe('data_induk.akun')
    expect(posisi?.grup.label).toBe('Tata Kelola')
  })

  it('awalan yang mirip bukan anak menu', () => {
    expect(cariPosisi(grup, '/igdx')).toBeNull()
  })

  it('cariHalaman hanya untuk rute menu persis', () => {
    expect(cariHalaman(grup, '/igd/papan')?.kode).toBe('igd.papan')
    expect(cariHalaman(grup, '/igd')?.kode).toBe('igd')
    expect(cariHalaman(grup, '/igd/papan/123')).toBeNull()
    expect(cariHalaman(grup, '/tidak-ada')).toBeNull()
  })
})

describe('ambilMenuSaya (ETag)', () => {
  beforeEach(() => {
    lupakanMenuSaya()
    vi.restoreAllMocks()
  })

  it('mengirim If-None-Match setelah respons ber-ETag dan memakai data lama saat 304', async () => {
    const get = vi
      .spyOn(klien, 'get')
      .mockResolvedValueOnce({ status: 200, data: { data: menuSayaTiruan }, headers: { etag: '"c3f1a9"' } })
      .mockResolvedValueOnce({ status: 304, data: '', headers: {} })

    expect(await ambilMenuSaya()).toEqual(menuSayaTiruan)
    expect(await ambilMenuSaya()).toEqual(menuSayaTiruan)
    expect(get.mock.calls[0]?.[1]?.headers).toEqual({})
    expect(get.mock.calls[1]?.[1]?.headers).toEqual({ 'If-None-Match': '"c3f1a9"' })
  })

  it('setelah sesi berakhir tidak mengirim ETag akun sebelumnya', async () => {
    const get = vi.spyOn(klien, 'get').mockResolvedValue({ status: 200, data: { data: menuSayaTiruan }, headers: { etag: '"a"' } })
    await ambilMenuSaya()
    lupakanMenuSaya()
    await ambilMenuSaya()
    expect(get.mock.calls[1]?.[1]?.headers).toEqual({})
  })
})
