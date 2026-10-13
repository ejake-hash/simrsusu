import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { HALAMAN_MASUK_BAWAAN } from '@/app/profilRs'
import PanelMerek from './PanelMerek.vue'

const pasang = (props: Partial<InstanceType<typeof PanelMerek>['$props']> = {}) =>
  mount(PanelMerek, { props: { isi: HALAMAN_MASUK_BAWAAN, namaRs: 'RS Contoh Pendidikan', foto: '/foto.webp', motif: '/motif.svg', ...props } })

describe('PanelMerek (tiket 1.15)', () => {
  it('teks bawaan: lencana, judul dengan bagian sorot, uraian, tiga butir fitur; dekoratif bagi pembaca layar', () => {
    const w = pasang()
    expect(w.attributes('aria-hidden')).toBe('true')
    expect(w.find('.tag').text()).toBe('Satu data untuk seluruh rumah sakit')
    expect(w.find('.judul-merek').text()).toBe('Pelayanan, pendapatan, dan mutu dalam satu layar kerja.')
    expect(w.find('.judul-merek em').text()).toBe('satu layar kerja.')
    expect(w.findAll('.poin li').map((li) => li.find('b').text())).toEqual(['Paperless', 'Terhubung', 'Pendidikan'])
  })

  it('isi berisi tag HTML tampil sebagai teks biasa', () => {
    const w = pasang({ isi: { ...HALAMAN_MASUK_BAWAAN, judul: '<script>alert(1)</script>', judulSorot: '<b>x</b>' } })
    expect(w.find('script').exists()).toBe(false)
    expect(w.find('.judul-merek em b').exists()).toBe(false)
    expect(w.find('.judul-merek').text()).toBe('<script>alert(1)</script> <b>x</b>')
  })

  it('keterangan foto: isian admin, kosong = nama RS; tanpa foto tanpa figure; tanpa sorot tanpa em', () => {
    expect(pasang().find('figcaption').text()).toBe('RS Contoh Pendidikan')
    expect(pasang({ isi: { ...HALAMAN_MASUK_BAWAAN, fotoKeterangan: 'Gedung utama' } }).find('figcaption').text()).toBe('Gedung utama')
    expect(pasang({ foto: null }).find('figure').exists()).toBe(false)
    expect(pasang({ isi: { ...HALAMAN_MASUK_BAWAAN, judulSorot: null } }).find('em').exists()).toBe(false)
  })

  it('motif hanya bila ada berkas dan tidak disembunyikan', () => {
    expect(pasang().attributes('style')).toContain('--motif: url(/motif.svg)')
    expect(pasang({ isi: { ...HALAMAN_MASUK_BAWAAN, tampilkanMotif: false } }).attributes('style')).toBeUndefined()
    expect(pasang({ motif: null }).attributes('style')).toBeUndefined()
  })
})
