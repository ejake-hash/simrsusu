import { describe, expect, it } from 'vitest'
import { ApiError } from '@/shared/api/klien'
import {
  aturAksi,
  aturTingkat,
  bandingkan,
  indeksPohon,
  kodeDariRincian,
  labelIzin,
  menuTampil,
  periksa,
  pilihanTingkat,
  terkunciInduk,
  tingkatDari,
  tingkatTampil,
} from './matriks'
import { buatPohon } from './tiruan/dataPengaturan'

const indeks = indeksPohon(buatPohon())
const node = (kode: string) => indeks.perNode.get(kode)!.node
const baris = (kode: string) => indeks.perNode.get(kode)!

describe('matriks izin peran', () => {
  it('tingkat L/I/P → himpunan kode aksi dasar node itu saja', () => {
    const tagihan = node('kasir.tagihan')
    expect([...aturTingkat(indeks, tagihan, new Set(), 'lihat')]).toEqual(['kasir.tagihan.lihat'])
    expect([...aturTingkat(indeks, tagihan, new Set(), 'input')].sort()).toEqual(['kasir.tagihan.input', 'kasir.tagihan.lihat'])
    expect([...aturTingkat(indeks, tagihan, new Set(), 'perbaiki')].sort()).toEqual(['kasir.tagihan.input', 'kasir.tagihan.lihat', 'kasir.tagihan.perbaiki'])
    // Turun dari perbaiki ke lihat mencabut input dan perbaiki, aksi khusus tetap.
    const awal = new Set(['kasir.tagihan.lihat', 'kasir.tagihan.input', 'kasir.tagihan.perbaiki', 'kasir.tagihan.cetak'])
    expect([...aturTingkat(indeks, tagihan, awal, 'lihat')].sort()).toEqual(['kasir.tagihan.cetak', 'kasir.tagihan.lihat'])
  })

  it('tingkat dihitung dari aksi yang utuh berurutan', () => {
    const tagihan = node('kasir.tagihan')
    expect(tingkatDari(tagihan, new Set())).toBe('tidak')
    expect(tingkatDari(tagihan, new Set(['kasir.tagihan.lihat', 'kasir.tagihan.input']))).toBe('input')
    // perbaiki tanpa input tidak dihitung "perbaiki"
    expect(tingkatDari(tagihan, new Set(['kasir.tagihan.lihat', 'kasir.tagihan.perbaiki']))).toBe('lihat')
    // bagian tanpa input: lihat → perbaiki
    expect(pilihanTingkat(node('kasir.tagihan.panel_diskon'))).toEqual(['tidak', 'lihat', 'perbaiki'])
    expect(tingkatDari(node('kasir.tagihan.panel_diskon'), new Set(['kasir.tagihan.panel_diskon.lihat', 'kasir.tagihan.panel_diskon.perbaiki']))).toBe('perbaiki')
  })

  it('tingkat tampil "campuran" bila ada aksi dasar di atas tingkat utuh', () => {
    const tagihan = node('kasir.tagihan')
    expect(tingkatTampil(tagihan, new Set(['kasir.tagihan.lihat', 'kasir.tagihan.perbaiki']))).toBe('campuran')
    expect(tingkatTampil(tagihan, new Set(['kasir.tagihan.lihat', 'kasir.tagihan.input']))).toBe('input')
    expect(tingkatTampil(tagihan, new Set(['kasir.tagihan.cetak']))).toBe('tidak')
  })

  it('menyalakan aksi ikut menyalakan lihat; mencabut lihat mencabut node dan semua bagiannya', () => {
    const tagihan = node('kasir.tagihan')
    const satu = aturAksi(indeks, tagihan, 'kasir.tagihan.cetak', true, new Set())
    expect([...satu].sort()).toEqual(['kasir.tagihan.cetak', 'kasir.tagihan.lihat'])

    const penuh = new Set(['kasir.tagihan.lihat', 'kasir.tagihan.input', 'kasir.tagihan.cetak', 'kasir.tagihan.panel_diskon.lihat', 'kasir.deposit.lihat'])
    expect([...aturAksi(indeks, tagihan, 'kasir.tagihan.lihat', false, penuh)]).toEqual(['kasir.deposit.lihat'])
    expect([...aturTingkat(indeks, tagihan, penuh, 'tidak')]).toEqual(['kasir.deposit.lihat'])
  })

  it('bagian terkunci selama sub-menu induk belum diberi lihat (MD-3)', () => {
    const diskon = baris('kasir.tagihan.panel_diskon')
    expect(terkunciInduk(diskon, new Set())).toBe(true)
    expect(terkunciInduk(diskon, new Set(['kasir.tagihan.input']))).toBe(true)
    expect(terkunciInduk(diskon, new Set(['kasir.tagihan.lihat']))).toBe(false)
    expect(terkunciInduk(baris('kasir.tagihan'), new Set())).toBe(false)
  })

  it('menu tanpa izin sendiri tampil bila ada turunan yang boleh dilihat', () => {
    expect(menuTampil(indeks, node('kasir'), new Set())).toBe(false)
    expect(menuTampil(indeks, node('kasir'), new Set(['kasir.tutup.lihat']))).toBe(true)
  })

  it('periksa meniru validasi server', () => {
    expect(periksa(indeks, new Set(['kasir.tagihan.input']))).toEqual([{ kode: 'kasir.tagihan.input', masalah: 'lihat_wajib' }])
    expect(periksa(indeks, new Set(['kasir.tagihan.panel_diskon.lihat']))).toEqual([{ kode: 'kasir.tagihan.panel_diskon.lihat', masalah: 'induk_belum_diberikan' }])
    expect(periksa(indeks, new Set(['tidak.ada']))).toEqual([{ kode: 'tidak.ada', masalah: 'izin_tidak_dikenal' }])
    expect(periksa(indeks, new Set(['kasir.tagihan.lihat', 'kasir.tagihan.panel_diskon.lihat']))).toEqual([])
  })

  it('selisih berurutan sesuai pohon, label berupa jalur', () => {
    const hasil = bandingkan(indeks, ['kasir.tutup.lihat', 'igd.papan.lihat'], new Set(['igd.papan.lihat', 'kasir.tagihan.lihat', 'igd.triase.lihat']))
    expect(hasil).toEqual({ ditambah: ['igd.triase.lihat', 'kasir.tagihan.lihat'], dicabut: ['kasir.tutup.lihat'] })
    expect(labelIzin(indeks, 'kasir.tagihan.lihat_rupiah')).toBe('Kasir → Tagihan pasien → Lihat rupiah')
  })

  it('kode dari rincian error (kontrak v0.2.2), validasi kolom biasa diabaikan', () => {
    const e = new ApiError(422, 'lihat_wajib', 'x', [{ kolom: 'izin', kode: 'a.b.input', pesan: 'Butuh lihat' }, { kode: 'c.d.batal', pesan: 'Izin sensitif' }, { kolom: 'dasar_perubahan', pesan: 'wajib' }])
    expect(kodeDariRincian(e.rincian)).toEqual(['a.b.input', 'c.d.batal'])
  })
})
