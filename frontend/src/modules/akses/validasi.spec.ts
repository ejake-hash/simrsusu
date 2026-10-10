import { describe, expect, it } from 'vitest'
import { validasiIsianSandi } from './validasi'

describe('validasiIsianSandi', () => {
  it('menandai kedua isian yang kosong', () => {
    expect(validasiIsianSandi({ nama_pengguna: '', sandi: '' })).toEqual({
      nama_pengguna: 'Nama pengguna wajib diisi.',
      sandi: 'Kata sandi wajib diisi.',
    })
  })

  it('nama pengguna berisi spasi saja dianggap kosong, sandi tidak di-trim', () => {
    expect(validasiIsianSandi({ nama_pengguna: '   ', sandi: '  ' })).toEqual({
      nama_pengguna: 'Nama pengguna wajib diisi.',
    })
  })

  it('memakai batas panjang yang sama dengan server', () => {
    expect(validasiIsianSandi({ nama_pengguna: 'a'.repeat(151), sandi: 'b'.repeat(1025) })).toEqual({
      nama_pengguna: 'Nama pengguna terlalu panjang.',
      sandi: 'Kata sandi terlalu panjang.',
    })
    expect(validasiIsianSandi({ nama_pengguna: 'a'.repeat(150), sandi: 'b'.repeat(1024) })).toEqual({})
  })
})
