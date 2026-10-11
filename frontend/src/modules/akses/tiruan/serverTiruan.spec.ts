import { describe, expect, it } from 'vitest'
import { buatServerTiruan, ID_SUPERADMIN, type ResponsTiruan } from './serverTiruan'
import { uid } from './dataPengaturan'

/** Server tiruan dipakai e2e sampai 1.2c-be merge; aturan kontrak yang paling sering dipakai layar diuji di sini. */
function minta(server: ReturnType<typeof buatServerTiruan>, metode: string, jalur: string, isi?: unknown, header: Record<string, string> = {}) {
  const [p, q] = jalur.split('?')
  return server.tangani({ metode, jalur: p!, kueri: new URLSearchParams(q), header, isi }) as ResponsTiruan
}
const kode = (r: ResponsTiruan) => (r.isi as { error: { kode: string } }).error.kode
const KASIR = uid(105)
const DOKTER = uid(4)

describe('server tiruan kontrak 1.2c', () => {
  it('PUT izin: If-Match wajib (428), versi lama → 412, sukses menaikkan ETag dan meringkas perubahan', () => {
    const s = buatServerTiruan()
    const awal = minta(s, 'GET', `/peran/${KASIR}`)
    const etag = awal.header!.ETag!
    const izin = (awal.isi as { data: { izin: string[] } }).data.izin.filter((k) => k !== 'kasir.tagihan.cetak')

    expect(minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 1' }).status).toBe(428)
    const ok = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 1' }, { 'if-match': etag })
    expect(ok.status).toBe(200)
    expect(ok.header!.ETag).not.toBe(etag)
    expect((ok.isi as { perubahan: unknown }).perubahan).toEqual({ ditambah: [], dicabut: ['kasir.tagihan.cetak'], akun_terdampak: 1 })

    const basi = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 2' }, { 'if-match': etag })
    expect([basi.status, kode(basi)]).toEqual([412, 'versi_berubah'])
  })

  it('PUT izin: izin sensitif baru → 409 butuh_persetujuan dengan rincian izin, tidak ada yang tersimpan', () => {
    const s = buatServerTiruan()
    const awal = minta(s, 'GET', `/peran/${KASIR}`)
    const izin = [...(awal.isi as { data: { izin: string[] } }).data.izin, 'kasir.tagihan.batal']
    const r = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 1' }, { 'if-match': awal.header!.ETag! })
    expect([r.status, kode(r)]).toEqual([409, 'butuh_persetujuan'])
    expect((r.isi as { error: { rincian: { kode: string }[] } }).error.rincian.map((x) => x.kode)).toEqual(['kasir.tagihan.batal'])
    expect(minta(s, 'GET', `/peran/${KASIR}`).header!.ETag).toBe(awal.header!.ETag)
  })

  it('PUT izin: 422 lihat_wajib dan induk_belum_diberikan; superadmin 409 terkunci', () => {
    const s = buatServerTiruan()
    const etag = minta(s, 'GET', `/peran/${KASIR}`).header!.ETag!
    const tanpaLihat = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin: ['kasir.tagihan.input'], dasar_perubahan: 'SK 1' }, { 'if-match': etag })
    expect(kode(tanpaLihat)).toBe('lihat_wajib')
    const tanpaInduk = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin: ['kasir.tagihan.panel_diskon.lihat'], dasar_perubahan: 'SK 1' }, { 'if-match': etag })
    expect(kode(tanpaInduk)).toBe('induk_belum_diberikan')
    const sa = uid(101)
    const r = minta(s, 'PUT', `/peran/${sa}/izin`, { izin: [], dasar_perubahan: 'SK 1' }, { 'if-match': minta(s, 'GET', `/peran/${sa}`).header!.ETag! })
    expect([r.status, kode(r)]).toEqual([409, 'peran_bawaan_terkunci'])
  })

  it('akun sendiri: rincian diri_sendiri, semua perubahan 403 diri_sendiri', () => {
    const s = buatServerTiruan()
    expect((minta(s, 'GET', `/akun/${ID_SUPERADMIN}`).isi as { data: { diri_sendiri: boolean } }).data.diri_sendiri).toBe(true)
    const r = minta(s, 'POST', `/akun/${ID_SUPERADMIN}/penugasan`, { peran_id: KASIR, cakupan_jenis: 'rs', dasar_pemberian: 'SK' })
    expect([r.status, kode(r)]).toEqual([403, 'diri_sendiri'])
  })

  it('cabut per akun menghilangkan menu (cabut menang), mengakhirinya mengembalikan akses peran; baris tetap sebagai riwayat', () => {
    const s = buatServerTiruan()
    const menu = () => (minta(s, 'GET', `/akun/${DOKTER}/menu`).isi as { data: { izin: string[] } }).data.izin
    expect(menu()).toContain('igd.papan.lihat')

    const dibuat = minta(s, 'POST', `/akun/${DOKTER}/izin-khusus`, { efek: 'cabut', sasaran_jenis: 'menu', sasaran_kode: 'igd.papan', dasar_pemberian: 'Memo 1', alasan: 'Rotasi ke poli' })
    expect(dibuat.status).toBe(201)
    expect(menu()).not.toContain('igd.papan.lihat')

    const id = (dibuat.isi as { data: { id: string } }).data.id
    expect(minta(s, 'POST', `/akun/${DOKTER}/izin-khusus/${id}/batal`, { alasan_batal: 'kependek' }).status).toBe(200)
    expect(menu()).toContain('igd.papan.lihat')
    const rinci = (minta(s, 'GET', `/akun/${DOKTER}`).isi as { data: { izin_khusus: { status: string; alasan_batal: string }[] } }).data
    expect(rinci.izin_khusus).toEqual([expect.objectContaining({ status: 'batal', alasan_batal: 'kependek' })])
  })

  it('reset MFA mengosongkan status; reset kedua 409 mfa_belum_aktif', () => {
    const s = buatServerTiruan()
    const mfa = uid(6)
    const r = minta(s, 'POST', `/akun/${mfa}/mfa/reset`, { alasan: 'Ganti ponsel', dasar: 'Tiket 12' })
    expect((r.isi as { data: { aktif: boolean } }).data.aktif).toBe(false)
    expect(kode(minta(s, 'POST', `/akun/${mfa}/mfa/reset`, { alasan: 'Ganti ponsel', dasar: 'Tiket 12' }))).toBe('mfa_belum_aktif')
  })

  it('daftar akun memakai kursor; batas di atas 200 → 422', () => {
    const s = buatServerTiruan()
    const h1 = minta(s, 'GET', '/akun?batas=50').isi as { data: unknown[]; meta: { kursor_berikutnya: string | null } }
    expect(h1.data).toHaveLength(50)
    const h2 = minta(s, 'GET', `/akun?batas=50&kursor=${h1.meta.kursor_berikutnya}`).isi as { data: unknown[]; meta: { kursor_berikutnya: string | null } }
    expect(h2.meta.kursor_berikutnya).toBeNull()
    expect(kode(minta(s, 'GET', '/akun?batas=201'))).toBe('ukuran_halaman_melebihi_batas')
  })
})
