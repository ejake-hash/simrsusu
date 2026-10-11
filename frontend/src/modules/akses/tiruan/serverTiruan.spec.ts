import { describe, expect, it } from 'vitest'
import type { AkunRinci, PeranRinci } from '../tipePengaturan'
import type { PermintaanAkses, PersetujuanAkses } from '../tipePersetujuan'
import { buatServerTiruan, ID_KABID, ID_SUPERADMIN, ID_SUPERADMIN_DUA, type ResponsTiruan } from './serverTiruan'
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
    expect((ok.isi as { perubahan: unknown }).perubahan).toEqual({ ditambah: [], dicabut: ['kasir.tagihan.cetak'], akun_terdampak: 1, menunggu_persetujuan: [] })

    const basi = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 2' }, { 'if-match': etag })
    expect([basi.status, kode(basi)]).toEqual([412, 'versi_berubah'])
  })

  it('PUT izin (1.2d): izin sensitif baru menunggu persetujuan, tidak masuk izin; kirim ulang idempoten; tanpa penyetuju → 422', () => {
    const s = buatServerTiruan()
    const awal = minta(s, 'GET', `/peran/${KASIR}`)
    const izin = [...(awal.isi as { data: { izin: string[] } }).data.izin, 'kasir.tagihan.batal']
    const r = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 1' }, { 'if-match': awal.header!.ETag! })
    expect(r.status).toBe(200)
    const isi = r.isi as { data: PeranRinci; perubahan: { ditambah: string[]; menunggu_persetujuan: PersetujuanAkses[] } }
    expect(isi.data.izin).not.toContain('kasir.tagihan.batal')
    expect(isi.data.izin_menunggu!.map((m) => [m.kode, m.penyetuju_peran?.nama])).toEqual([['kasir.tagihan.batal', 'Contoh Kabid Keuangan']])
    expect(isi.perubahan.ditambah).toEqual([])
    const pertama = isi.perubahan.menunggu_persetujuan[0]!
    expect([pertama.status, pertama.objek.jenis, pertama.dapat_diputuskan]).toEqual(['menunggu', 'izin_peran', false])

    const ulang = minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 2' }, { 'if-match': r.header!.ETag! })
    expect((ulang.isi as typeof isi).perubahan.menunggu_persetujuan[0]!.id).toBe(pertama.id)

    const dokter = uid(104)
    const d = minta(s, 'GET', `/peran/${dokter}`)
    const izinDokter = [...(d.isi as { data: { izin: string[] } }).data.izin, 'rekam_medis.pelepasan.setujui']
    const tolak = minta(s, 'PUT', `/peran/${dokter}/izin`, { izin: izinDokter, dasar_perubahan: 'SK 3' }, { 'if-match': d.header!.ETag! })
    expect([tolak.status, kode(tolak)]).toEqual([422, 'penyetuju_belum_ditetapkan'])
    expect((tolak.isi as { error: { rincian: { kolom: string; kode: string }[] } }).error.rincian[0]).toMatchObject({ kolom: 'izin', kode: 'rekam_medis.pelepasan.setujui' })
  })

  it('persetujuan (1.2d): pengaju tidak bisa menyetujui; bukan penyetuju 403; penyetuju menyetujui → izin berlaku; tolak menutup grup', () => {
    const s = buatServerTiruan()
    const awal = minta(s, 'GET', `/peran/${KASIR}`)
    const izin = [...(awal.isi as { data: { izin: string[] } }).data.izin, 'kasir.tagihan.batal']
    const p = (minta(s, 'PUT', `/peran/${KASIR}/izin`, { izin, dasar_perubahan: 'SK 1' }, { 'if-match': awal.header!.ETag! }).isi as {
      perubahan: { menunggu_persetujuan: PersetujuanAkses[] }
    }).perubahan.menunggu_persetujuan[0]!
    expect(kode(minta(s, 'POST', `/persetujuan-akses/${p.id}/setujui`, {}))).toBe('bukan_penyetuju')
    expect((minta(s, 'GET', '/persetujuan-akses?tab=saya_ajukan').isi as { data: unknown[] }).data).toHaveLength(1)

    s.sebagai(ID_KABID)
    const antrean = minta(s, 'GET', '/persetujuan-akses?tab=perlu_saya_putuskan').isi as { data: PersetujuanAkses[] }
    expect(antrean.data.map((x) => [x.id, x.dapat_diputuskan])).toEqual([[p.id, true]])
    const setuju = minta(s, 'POST', `/persetujuan-akses/${p.id}/setujui`, { catatan: 'Sesuai SK' })
    expect((setuju.isi as { data: PersetujuanAkses }).data).toMatchObject({ status: 'disetujui', diterapkan: true })
    expect(kode(minta(s, 'POST', `/persetujuan-akses/${p.id}/setujui`, {}))).toBe('sudah_diputuskan')
    s.sebagai(ID_SUPERADMIN)
    expect((minta(s, 'GET', `/peran/${KASIR}`).isi as { data: PeranRinci }).data.izin).toContain('kasir.tagihan.batal')
  })

  it('penugasan superadmin (1.2d): 202; superadmin kedua menyetujui → berlaku; pengajuan ganda 409; tarik oleh pengaju', () => {
    const s = buatServerTiruan()
    const SA = uid(101)
    const r = minta(s, 'POST', `/akun/${uid(2)}/penugasan`, { peran_id: SA, cakupan_jenis: 'rs', cakupan_id: null, dasar_pemberian: 'SK Direktur 9/2026' })
    expect(r.status).toBe(202)
    const p = (r.isi as { data: PersetujuanAkses }).data
    expect([p.jenis, p.objek.jenis, p.penyetuju_peran?.kode]).toEqual(['peran_superadmin', 'penugasan', 'superadmin'])
    expect(kode(minta(s, 'POST', `/akun/${uid(2)}/penugasan`, { peran_id: SA, cakupan_jenis: 'rs', cakupan_id: null, dasar_pemberian: 'SK lagi' }))).toBe('persetujuan_ganda')
    expect((minta(s, 'GET', `/akun/${uid(2)}`).isi as { data: AkunRinci }).data.menunggu_persetujuan!.map((x) => x.id)).toEqual([p.id])
    expect(kode(minta(s, 'POST', `/persetujuan-akses/${p.id}/setujui`, {}))).toBe('pengaju_sendiri')

    s.sebagai(ID_SUPERADMIN_DUA)
    minta(s, 'POST', `/persetujuan-akses/${p.id}/setujui`, {})
    const akun = (minta(s, 'GET', `/akun/${uid(2)}`).isi as { data: AkunRinci }).data
    expect(akun.penugasan.some((x) => x.peran.kode === 'superadmin' && x.berlaku_sekarang)).toBe(true)
    expect(akun.menunggu_persetujuan).toEqual([])

    s.sebagai(ID_SUPERADMIN)
    const kedua = (minta(s, 'POST', `/akun/${uid(3)}/penugasan`, { peran_id: SA, cakupan_jenis: 'rs', cakupan_id: null, dasar_pemberian: 'SK 10' }).isi as { data: PersetujuanAkses }).data
    const tarik = minta(s, 'POST', `/persetujuan-akses/${kedua.id}/batalkan`, { alasan: 'Salah akun' })
    expect((tarik.isi as { data: PersetujuanAkses }).data.status).toBe('dibatalkan')
  })

  it('kedaluwarsa (1.2d): lewat 7 hari tampil kedaluwarsa walau belum diproses dan tidak bisa diputuskan', () => {
    let jam = new Date('2026-10-11T03:00:00.000Z')
    const s = buatServerTiruan({ sekarang: () => jam })
    const p = (minta(s, 'POST', `/akun/${uid(2)}/penugasan`, { peran_id: uid(101), cakupan_jenis: 'rs', cakupan_id: null, dasar_pemberian: 'SK 11' }).isi as { data: PersetujuanAkses }).data
    jam = new Date('2026-10-18T03:00:01.000Z')
    s.sebagai(ID_SUPERADMIN_DUA)
    expect((minta(s, 'GET', `/persetujuan-akses/${p.id}`).isi as { data: PersetujuanAkses }).data.status).toBe('kedaluwarsa')
    expect(kode(minta(s, 'POST', `/persetujuan-akses/${p.id}/setujui`, {}))).toBe('sudah_diputuskan')
  })

  it('permintaan akses (1.2d): dibuat dari Akses ditolak; ganda 409; sudah punya akses 409; ditindaklanjuti lewat penugasan', () => {
    const s = buatServerTiruan()
    s.sebagai(uid(2))
    const r = minta(s, 'POST', '/permintaan-akses', { sasaran_jenis: 'menu', sasaran_kode: 'kasir.tagihan', alasan: 'Membantu kasir saat cuti' })
    expect(r.status).toBe(201)
    const pm = (r.isi as { data: PermintaanAkses }).data
    expect(pm.sasaran_label).toBe('Kasir → Tagihan pasien')
    expect(kode(minta(s, 'POST', '/permintaan-akses', { sasaran_jenis: 'menu', sasaran_kode: 'kasir.tagihan', alasan: 'Membantu kasir lagi' }))).toBe('permintaan_ganda')
    expect(kode(minta(s, 'POST', '/permintaan-akses', { sasaran_jenis: 'menu', sasaran_kode: 'pendaftaran.pasien', alasan: 'Sudah punya akses' }))).toBe('sudah_punya_akses')
    expect(minta(s, 'GET', '/permintaan-akses').status).toBe(403)

    s.sebagai(ID_SUPERADMIN)
    expect((minta(s, 'GET', '/permintaan-akses?status=menunggu').isi as { data: unknown[] }).data).toHaveLength(1)
    const t = minta(s, 'POST', `/akun/${uid(2)}/penugasan`, { peran_id: uid(103), cakupan_jenis: 'rs', cakupan_id: null, dasar_pemberian: pm.nomor, permintaan_akses_id: pm.id })
    expect(t.status).toBe(201)
    expect((minta(s, 'GET', '/permintaan-akses?status=ditindaklanjuti').isi as { data: PermintaanAkses[] }).data[0]!.id).toBe(pm.id)
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
