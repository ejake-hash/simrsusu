import { describe, expect, it } from 'vitest'
import { menuSayaTiruan } from '@/modules/akses/tiruan/menuSaya'
import { cariMenu, indeksPencarian, modulSaya, ringkasanSubMenu, salam } from './beranda'

describe('Beranda: logika murni', () => {
  it('salam menurut jam perangkat', () => {
    expect([3, 4, 10, 11, 14, 15, 17, 18, 23].map(salam)).toEqual([
      'Selamat malam',
      'Selamat pagi',
      'Selamat pagi',
      'Selamat siang',
      'Selamat siang',
      'Selamat sore',
      'Selamat sore',
      'Selamat malam',
      'Selamat malam',
    ])
  })

  it('modul saya: semua menu kecuali Beranda, urut grup sidebar', () => {
    const kode = modulSaya(menuSayaTiruan.grup).map((m) => m.kode)
    expect(kode[0]).toBe('pendaftaran')
    expect(kode).not.toContain('beranda')
    expect(kode[kode.length - 1]).toBe('pengaturan')
  })

  it('ringkasan sub-menu: tiga pertama, sisanya "dan N lainnya"', () => {
    const sub = [1, 2, 3, 4, 5].map((n) => ({ kode: `a.${n}`, label: `S${n}`, rute: `/a/${n}`, status: 'aktif' as const, fase: 1 }))
    const r = ringkasanSubMenu(sub)
    expect(r.tampil.map((s) => s.label)).toEqual(['S1', 'S2', 'S3'])
    expect(r.lainnya).toBe(2)
    expect(ringkasanSubMenu(sub.slice(0, 2)).lainnya).toBe(0)
  })

  it('pencarian hanya dari menu yang dikirim server; semua kata harus cocok; awalan label didahulukan', () => {
    const indeks = indeksPencarian(menuSayaTiruan.grup)
    const kode = (q: string, batas?: number) => cariMenu(indeks, q, batas).hasil.map((i) => i.kode)
    expect(kode('akun')).toEqual(['pengaturan.akun'])
    expect(kode('pengaturan akun')).toEqual(['pengaturan.akun'])
    // "kasir" ada sebagai menu; "keuangan" tidak dikirim server untuk akun ini.
    expect(kode('KASIR')[0]).toBe('kasir')
    expect(cariMenu(indeks, 'keuangan')).toEqual({ hasil: [], total: 0 })
    expect(cariMenu(indeks, '   ')).toEqual({ hasil: [], total: 0 })
    expect(kode('igd')).toEqual(['igd', 'igd.papan'])
    // Batas tampil: total tetap menghitung semua yang cocok.
    expect(cariMenu(indeks, 'a', 3)).toMatchObject({ total: expect.any(Number) })
    expect(cariMenu(indeks, 'a', 3).hasil).toHaveLength(3)
    expect(cariMenu(indeks, 'a', 3).total).toBeGreaterThan(3)
  })
})
