import type { GrupMenu, Menu, MenuSaya } from '../tipeMenu'
import type { AkunRinci, CakupanJenis, IzinKhusus, IzinNode, NodeMenu, Penugasan, PeranRinci, PohonMenu, StatusMfaAkun } from '../tipePengaturan'
import { buatPohon, izinMenu, uid } from './dataPengaturan'

/**
 * Server tiruan kontrak docs/api/openapi.yaml v0.2.3 (tiket 1.2c) sampai 1.2c-be merge: menyimpan keadaan di
 * memori dan meniru aturan yang tertulis di kontrak, termasuk error 403/409/412/422/428. Dipakai unit test,
 * e2e (page.route), dan `vite` dev dengan VITE_API_TIRUAN=true; tidak masuk build produksi.
 * Bukan sumber aturan: bila berbeda dengan kontrak atau backend, kontrak yang berlaku.
 */

export interface PermintaanTiruan {
  metode: string
  /** Jalur setelah /api/v1, mis. /peran/abc/izin. */
  jalur: string
  kueri: URLSearchParams
  header: Record<string, string | undefined>
  isi: unknown
}

export interface ResponsTiruan {
  status: number
  header?: Record<string, string>
  isi?: unknown
}

interface PeranTiruan {
  id: string
  kode: string
  nama: string
  uraian: string | null
  bawaan_sistem: boolean
  wajib_mfa: boolean
  status: 'aktif' | 'nonaktif'
  izin: Set<string>
  versi: number
  diubah_pada: string
}

interface AkunTiruan {
  id: string
  nama_pengguna: string
  nama: string
  status: 'aktif' | 'nonaktif'
  terkunci_sampai: string | null
  login_terakhir_pada: string | null
  wajib_mfa: boolean
  mfa_aktif_pada: string | null
  penugasan: Penugasan[]
  izin_khusus: IzinKhusus[]
}

const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({
  status,
  isi: { error: { kode, pesan, rincian } },
})
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })

type Isi = Record<string, unknown>

function teks(isi: Isi, kolom: string, min: number, max: number, wajib = true): string | null | ResponsTiruan {
  const nilai = isi[kolom]
  if (nilai === undefined || nilai === null || nilai === '') {
    return wajib ? error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan: 'Wajib diisi.' }]) : null
  }
  if (typeof nilai !== 'string' || nilai.trim().length < min || nilai.length > max) {
    return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan: `Panjang ${min}–${max} karakter.` }])
  }
  return nilai.trim()
}
const salah = (v: unknown): v is ResponsTiruan => typeof v === 'object' && v !== null && 'status' in v

export interface OpsiServerTiruan {
  /** Akun yang dianggap sedang masuk (diri_sendiri). Bawaan: contoh.superadmin. */
  penggunaId?: string
  sekarang?: () => Date
}

export const ID_SUPERADMIN = uid(1)

export function buatServerTiruan(opsi: OpsiServerTiruan = {}) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  let nomor = 5000
  const idBaru = () => uid(++nomor)

  const pohon: PohonMenu = buatPohon()
  const perKode = new Map<string, { izin: IzinNode; node: NodeMenu; jalur: string; induk: NodeMenu | null; subInduk: NodeMenu | null }>()
  const nodePerKode = new Map<string, { node: NodeMenu; jalur: string; turunan: string[] }>()
  ;(function indeks() {
    const jalan = (node: NodeMenu, leluhur: NodeMenu[]): string[] => {
      const jalur = [...leluhur, node].map((n) => n.label).join(' → ')
      const subInduk = node.tingkat === 'bagian' ? ([...leluhur].reverse().find((n) => n.tingkat === 'sub_menu') ?? null) : null
      for (const izin of node.izin) perKode.set(izin.kode, { izin, node, jalur, induk: leluhur[leluhur.length - 1] ?? null, subInduk })
      const turunan = [...node.izin.map((i) => i.kode), ...node.anak.flatMap((a) => jalan(a, [...leluhur, node]))]
      nodePerKode.set(node.kode, { node, jalur, turunan })
      return turunan
    }
    for (const g of pohon.grup) for (const m of g.menu) jalan(m, [])
  })()
  const sensitif = (kode: string) => perKode.get(kode)?.izin.sensitif ?? false
  const lihatDari = (node: NodeMenu) => node.izin.find((i) => i.jenis_aksi === 'lihat')?.kode

  const peran = new Map<string, PeranTiruan>()
  const tambahPeran = (id: string, kode: string, nama: string, izin: string[], isi: Partial<PeranTiruan> = {}) =>
    peran.set(id, { id, kode, nama, uraian: 'Peran sintetis untuk lokal/staging.', bawaan_sistem: false, wajib_mfa: false, status: 'aktif', izin: new Set(izin), versi: 1, diubah_pada: '2026-10-01T02:00:00.000Z', ...isi })

  const SA = uid(101)
  tambahPeran(SA, 'superadmin', 'Superadmin', izinMenu(pohon, []).concat(['pengaturan.peran', 'pengaturan.akun'].flatMap((k) => ['lihat', 'input', 'perbaiki'].map((a) => `${k}.${a}`))), {
    bawaan_sistem: true,
    wajib_mfa: true,
    uraian: 'Mengelola menu, peran, dan akun. Tidak membuka rekam medis atau angka rupiah (PRD 9.2.4).',
  })
  tambahPeran(uid(102), 'contoh_admisi', 'Contoh Petugas Admisi', izinMenu(pohon, ['pendaftaran']))
  tambahPeran(uid(103), 'contoh_perawat', 'Contoh Perawat', izinMenu(pohon, ['igd', 'rawat_inap']))
  tambahPeran(uid(104), 'contoh_dokter', 'Contoh Dokter', izinMenu(pohon, ['igd', 'rawat_jalan', 'rekam_medis']))
  tambahPeran(uid(105), 'contoh_kasir', 'Contoh Kasir', [...izinMenu(pohon, ['kasir']), 'kasir.tagihan.cetak', 'kasir.tagihan.lihat_rupiah'], { wajib_mfa: true })
  tambahPeran(uid(106), 'contoh_lama', 'Contoh Peran Lama', [], { status: 'nonaktif' })

  const akun = new Map<string, AkunTiruan>()
  const penugasan = (peranId: string, isi: Partial<Penugasan> = {}): Penugasan => {
    const p = peran.get(peranId)!
    return {
      id: idBaru(),
      peran: { id: p.id, kode: p.kode, nama: p.nama },
      cakupan_jenis: 'rs',
      cakupan_id: null,
      berlaku_dari: '2026-10-01T02:00:00.000Z',
      berlaku_sampai: null,
      dasar_pemberian: 'Data sintetis (AkunSintetisSeeder)',
      alasan: null,
      diberikan_oleh: null,
      status: 'aktif',
      berlaku_sekarang: true,
      alasan_batal: null,
      dibatalkan_oleh: null,
      dibatalkan_pada: null,
      dibuat_pada: '2026-10-01T02:00:00.000Z',
      ...isi,
    }
  }
  const tambahAkun = (id: string, nama_pengguna: string, nama: string, peranId: string[], isi: Partial<AkunTiruan> = {}) =>
    akun.set(id, {
      id,
      nama_pengguna,
      nama,
      status: 'aktif',
      terkunci_sampai: null,
      login_terakhir_pada: '2026-10-10T01:15:00.000Z',
      wajib_mfa: false,
      mfa_aktif_pada: null,
      penugasan: peranId.map((p) => penugasan(p)),
      izin_khusus: [],
      ...isi,
    })

  tambahAkun(ID_SUPERADMIN, 'contoh.superadmin', 'Contoh Superadmin', [SA], { mfa_aktif_pada: '2026-10-01T02:00:00.000Z' })
  tambahAkun(uid(2), 'contoh.admisi', 'Contoh Petugas Admisi', [uid(102)])
  tambahAkun(uid(3), 'contoh.perawat', 'Contoh Perawat', [uid(103)])
  tambahAkun(uid(4), 'contoh.dokter', 'Contoh Dokter', [uid(104)])
  tambahAkun(uid(5), 'contoh.terkunci', 'Contoh Akun Uji Kunci', [], { terkunci_sampai: '2099-01-01T00:00:00.000Z', login_terakhir_pada: null })
  tambahAkun(uid(6), 'contoh.mfa', 'Contoh Akun MFA', [uid(104)], { wajib_mfa: true, mfa_aktif_pada: '2026-10-02T03:00:00.000Z' })
  tambahAkun(uid(7), 'contoh.mfa.baru', 'Contoh Akun MFA Baru', [], { wajib_mfa: true, login_terakhir_pada: null })
  tambahAkun(uid(8), 'contoh.kasir', 'Contoh Kasir', [uid(105)], { mfa_aktif_pada: '2026-10-03T03:00:00.000Z' })
  // Contoh pengecualian aktif dan riwayat batal (MD-1).
  akun.get(uid(8))!.izin_khusus.push(
    {
      id: idBaru(), efek: 'cabut', sasaran_jenis: 'menu', sasaran_kode: 'kasir.tutup', sasaran_label: 'Kasir → Tutup kasir', sensitif: false,
      berlaku_dari: '2026-10-05T01:00:00.000Z', berlaku_sampai: null, dasar_pemberian: 'Memo Kabid Keuangan 14/2026', alasan: 'Belum lulus pelatihan tutup kasir',
      diberikan_oleh: { id: ID_SUPERADMIN, nama: 'Contoh Superadmin' }, status: 'aktif', berlaku_sekarang: true, alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, dibuat_pada: '2026-10-05T01:00:00.000Z',
    },
    {
      id: idBaru(), efek: 'tambah', sasaran_jenis: 'izin', sasaran_kode: 'kasir.deposit.perbaiki', sasaran_label: 'Kasir → Deposit & refund → Perbaiki', sensitif: false,
      berlaku_dari: '2026-09-01T01:00:00.000Z', berlaku_sampai: '2026-09-30T16:59:59.000Z', dasar_pemberian: 'Surat tugas 9/2026', alasan: 'Menggantikan kasir senior cuti',
      diberikan_oleh: { id: ID_SUPERADMIN, nama: 'Contoh Superadmin' }, status: 'batal', berlaku_sekarang: false, alasan_batal: 'Masa cuti selesai lebih awal',
      dibatalkan_oleh: { id: ID_SUPERADMIN, nama: 'Contoh Superadmin' }, dibatalkan_pada: '2026-09-20T01:00:00.000Z', dibuat_pada: '2026-09-01T01:00:00.000Z',
    },
  )
  // Banyak akun sintetis agar "Muat lagi" teruji.
  for (let i = 1; i <= 60; i++) {
    tambahAkun(uid(200 + i), `contoh.massal${String(i).padStart(2, '0')}`, `Contoh Akun Massal ${i}`, [uid(102 + (i % 3))])
  }

  const penggunaId = opsi.penggunaId ?? ID_SUPERADMIN

  // ── Turunan ──
  const berlaku = (x: { status: string; berlaku_dari: string; berlaku_sampai: string | null }) =>
    x.status === 'aktif' && x.berlaku_dari <= iso() && (x.berlaku_sampai === null || x.berlaku_sampai > iso())
  const segarkan = <T extends Penugasan | IzinKhusus>(x: T): T => ({ ...x, berlaku_sekarang: berlaku(x) })
  const peranBerlaku = (a: AkunTiruan) => a.penugasan.filter(berlaku).map((p) => peran.get(p.peran.id)!).filter((p) => p.status === 'aktif')
  const jumlahAkun = (peranId: string) => [...akun.values()].filter((a) => a.penugasan.some((p) => p.peran.id === peranId && berlaku(p))).length
  const memuatSensitif = (p: PeranTiruan) => [...p.izin].some(sensitif)
  const superadmin = (a: AkunTiruan) => peranBerlaku(a).some((p) => p.kode === 'superadmin')
  /** 403 akun_superadmin (kontrak v0.2.2): akun superadmin hanya diubah superadmin lain. */
  const terkunciSuperadmin = (a: AkunTiruan) => superadmin(a) && !superadmin(akun.get(penggunaId)!)
  const pegangPeran = (peranId: string) => akun.get(penggunaId)?.penugasan.some((p) => p.peran.id === peranId && berlaku(p)) ?? false

  function izinEfektif(a: AkunTiruan): Set<string> {
    const izin = new Set(peranBerlaku(a).flatMap((p) => [...p.izin]))
    const aktif = a.izin_khusus.filter(berlaku)
    const sasaran = (k: IzinKhusus) => (k.sasaran_jenis === 'izin' ? [k.sasaran_kode] : (nodePerKode.get(k.sasaran_kode)?.turunan ?? []))
    for (const k of aktif.filter((x) => x.efek === 'tambah')) {
      // Tambah menu = beri lihat node itu; tambah izin = kode itu saja.
      const n = nodePerKode.get(k.sasaran_kode)?.node
      if (k.sasaran_jenis === 'menu' && n) {
        const lihat = lihatDari(n)
        if (lihat) izin.add(lihat)
      } else izin.add(k.sasaran_kode)
    }
    // Cabut selalu menang (FR-09-13).
    for (const k of aktif.filter((x) => x.efek === 'cabut')) for (const kode of sasaran(k)) izin.delete(kode)
    return izin
  }

  function menuEfektif(a: AkunTiruan): MenuSaya {
    const izin = izinEfektif(a)
    const rute = (kode: string) => (kode === 'beranda' ? '/' : `/${kode.replace(/_/g, '-').replace(/\./g, '/')}`)
    const grup: GrupMenu[] = []
    for (const g of pohon.grup) {
      const menu: Menu[] = []
      for (const m of g.menu) {
        const sub = m.anak.filter((s) => {
          const lihat = lihatDari(s)
          return lihat !== undefined && izin.has(lihat) && s.modul_aktif
        })
        if (m.kode !== 'beranda' && sub.length === 0) continue
        menu.push({
          kode: m.kode,
          label: m.label,
          ikon: m.ikon ?? m.kode,
          rute: rute(m.kode),
          status: m.kode === 'beranda' || sub.some((s) => s.tersedia) ? 'aktif' : 'segera',
          fase: m.fase ?? 1,
          sub_menu: sub.map((s) => ({ kode: s.kode, label: s.label, rute: rute(s.kode), status: s.tersedia ? 'aktif' : 'segera', fase: s.fase ?? 1 })),
        })
      }
      if (menu.length) grup.push({ kode: g.kode, label: g.label, menu })
    }
    const daftar = [...izin].filter((k) => perKode.has(k)).sort()
    return { versi: `t${daftar.length}-${daftar.join('').length}`, grup, izin: daftar }
  }

  function statusMfa(a: AkunTiruan): StatusMfaAkun {
    const sumber: StatusMfaAkun['sumber_wajib'] = []
    if (a.wajib_mfa) sumber.push({ jenis: 'akun', label: 'Sakelar akun', dapat_dimatikan: true })
    for (const p of peranBerlaku(a)) {
      if (!p.wajib_mfa) continue
      const superadmin = p.kode === 'superadmin'
      sumber.push({ jenis: superadmin ? 'superadmin' : 'peran', label: `Peran ${p.nama}`, dapat_dimatikan: !superadmin && !memuatSensitif(p) })
    }
    if ([...izinEfektif(a)].some(sensitif)) sumber.push({ jenis: 'izin_sensitif', label: 'Memegang izin sensitif', dapat_dimatikan: false })
    return { aktif: a.mfa_aktif_pada !== null, aktif_pada: a.mfa_aktif_pada, wajib_akun: a.wajib_mfa, sumber_wajib: sumber, dapat_direset: a.mfa_aktif_pada !== null && a.id !== penggunaId && !terkunciSuperadmin(a) }
  }

  function peranRinci(p: PeranTiruan): PeranRinci {
    const bawaan = p.kode === 'superadmin'
    const sendiri = pegangPeran(p.id)
    return {
      id: p.id, kode: p.kode, nama: p.nama, uraian: p.uraian, bawaan_sistem: p.bawaan_sistem, wajib_mfa: p.wajib_mfa, status: p.status,
      jumlah_akun: jumlahAkun(p.id), memuat_izin_sensitif: memuatSensitif(p), versi: String(p.versi), izin: [...p.izin].sort(),
      wajib_mfa_terkunci: bawaan || memuatSensitif(p), dapat_disunting: !bawaan && !sendiri,
      alasan_terkunci: bawaan ? 'peran_bawaan_terkunci' : sendiri ? 'peran_sendiri' : null, diubah_pada: p.diubah_pada,
    }
  }
  const ringkasPeran = (p: PeranTiruan) => {
    const { versi: _v, izin: _i, wajib_mfa_terkunci: _w, dapat_disunting: _d, alasan_terkunci: _a, diubah_pada: _u, ...r } = peranRinci(p)
    return r
  }
  const denganEtag = (p: PeranTiruan, status = 200, tambahan: Isi = {}) => ok({ data: peranRinci(p), ...tambahan }, status, { ETag: `"${p.versi}"` })

  function akunRinci(a: AkunTiruan): AkunRinci {
    const urut = <T extends Penugasan | IzinKhusus>(xs: T[]) =>
      xs.map(segarkan).sort((x, y) => Number(y.berlaku_sekarang) - Number(x.berlaku_sekarang) || y.dibuat_pada.localeCompare(x.dibuat_pada))
    return {
      id: a.id, nama_pengguna: a.nama_pengguna, nama: a.nama, status: a.status, terkunci_sampai: a.terkunci_sampai, login_terakhir_pada: a.login_terakhir_pada,
      diri_sendiri: a.id === penggunaId,
      dapat_diubah: a.id !== penggunaId && !terkunciSuperadmin(a),
      alasan_terkunci: a.id === penggunaId ? 'diri_sendiri' : terkunciSuperadmin(a) ? 'akun_superadmin' : null,
      mfa: statusMfa(a), penugasan: urut(a.penugasan), izin_khusus: urut(a.izin_khusus),
    }
  }

  function halaman<T>(daftar: T[], kueri: URLSearchParams): ResponsTiruan {
    const batas = Number(kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Ukuran halaman maksimum 200.')
    const mulai = kueri.get('kursor') ? Number(atob(kueri.get('kursor')!)) : 0
    if (!Number.isFinite(mulai)) return error(422, 'kursor_tidak_valid', 'Kursor tidak valid.')
    const isi = daftar.slice(mulai, mulai + batas)
    return ok({ data: isi, meta: { kursor_berikutnya: mulai + batas < daftar.length ? btoa(String(mulai + batas)) : null } })
  }

  function cocokVersi(p: PeranTiruan, header: Record<string, string | undefined>): ResponsTiruan | null {
    const ifMatch = header['if-match']
    if (!ifMatch) return error(428, 'if_match_wajib', 'Header If-Match wajib.')
    if (ifMatch.replace(/^W\//, '').replace(/"/g, '') !== String(p.versi)) {
      return error(412, 'versi_berubah', 'Peran ini diubah orang lain sejak dibuka. Muat ulang untuk melihat perubahan terbaru.')
    }
    return null
  }

  // ── Rute ──
  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const isi = (r.isi ?? {}) as Isi
    const bagian = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const [a0, a1, a2, a3, a4] = bagian
    const m = r.metode.toUpperCase()

    if (m === 'GET' && r.jalur === '/saya') {
      const a = akun.get(penggunaId)!
      const peranSaya = peranBerlaku(a).map((p) => ({ id: p.id, nama: p.nama })).sort((x, y) => x.nama.localeCompare(y.nama))
      return ok({
        data: {
          id: a.id,
          nama_pengguna: a.nama_pengguna,
          login_terakhir_pada: a.login_terakhir_pada,
          mfa_aktif: a.mfa_aktif_pada !== null,
          pegawai: { id: uid(9000), nama: a.nama },
          peran: peranSaya,
          masuk_sebelumnya_pada: '2026-10-09T23:48:00.000Z',
          masuk_sebelumnya_dari_jaringan_rs: true,
        },
      })
    }
    if (m === 'GET' && r.jalur === '/saya/menu') return ok({ data: menuEfektif(akun.get(penggunaId)!) })
    if (m === 'POST' && r.jalur === '/autentikasi/keluar') return ok(null, 204)
    if (m === 'GET' && r.jalur === '/menu') return ok({ data: pohon })

    if (a0 === 'peran') {
      if (m === 'GET' && !a1) {
        const cari = (r.kueri.get('cari') ?? '').toLowerCase()
        const status = r.kueri.get('status')
        const daftar = [...peran.values()]
          .filter((p) => (!cari || p.kode.includes(cari) || p.nama.toLowerCase().includes(cari)) && (!status || p.status === status))
          .map(ringkasPeran)
        return halaman(daftar, r.kueri)
      }
      if (m === 'POST' && !a1) {
        const kode = typeof isi.kode === 'string' ? isi.kode : ''
        if (!/^[a-z][a-z0-9_]{1,49}$/.test(kode)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'kode', pesan: 'Huruf kecil, angka, garis bawah; diawali huruf.' }])
        const nama = teks(isi, 'nama', 3, 100)
        if (salah(nama)) return nama
        const dasar = teks(isi, 'dasar_perubahan', 3, 200)
        if (salah(dasar)) return dasar
        if ([...peran.values()].some((p) => p.kode === kode)) return error(409, 'kode_sudah_dipakai', `Kode ${kode} sudah dipakai peran lain.`, [{ kolom: 'kode' }])
        const sumber = typeof isi.salin_dari_id === 'string' ? peran.get(isi.salin_dari_id) : undefined
        const id = idBaru()
        tambahPeran(id, kode, nama!, sumber ? [...sumber.izin].filter((k) => !sensitif(k)) : [], {
          uraian: typeof isi.uraian === 'string' ? isi.uraian : null,
          wajib_mfa: isi.wajib_mfa === true,
          diubah_pada: iso(),
        })
        return denganEtag(peran.get(id)!, 201)
      }
      const p = a1 ? peran.get(a1) : undefined
      if (!p) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET' && !a2) return denganEtag(p)
      if (m === 'PATCH' && !a2) {
        const v = cocokVersi(p, r.header)
        if (v) return v
        const dasar = teks(isi, 'dasar_perubahan', 3, 200)
        if (salah(dasar)) return dasar
        if (pegangPeran(p.id)) return error(403, 'peran_sendiri', 'Anda tidak bisa mengubah peran yang sedang Anda pegang.')
        if (isi.status === 'nonaktif' && p.bawaan_sistem) return error(409, 'peran_bawaan_terkunci', 'Peran bawaan sistem tidak bisa dinonaktifkan.')
        if (isi.wajib_mfa === false && peranRinci(p).wajib_mfa_terkunci) return error(422, 'mfa_tidak_bisa_dimatikan', 'MFA wajib untuk superadmin dan peran yang memuat izin sensitif.')
        if (isi.status === 'nonaktif' && jumlahAkun(p.id) > 0) {
          return error(409, 'peran_masih_dipakai', `Peran masih ditugaskan ke ${jumlahAkun(p.id)} akun. Akhiri penugasannya dulu.`, [{ jumlah_akun: jumlahAkun(p.id) }])
        }
        if (typeof isi.nama === 'string') p.nama = isi.nama
        if ('uraian' in isi) p.uraian = (isi.uraian as string | null) ?? null
        if (typeof isi.wajib_mfa === 'boolean') p.wajib_mfa = isi.wajib_mfa
        if (isi.status === 'aktif' || isi.status === 'nonaktif') p.status = isi.status
        p.versi++
        p.diubah_pada = iso()
        return denganEtag(p)
      }
      if (m === 'PUT' && a2 === 'izin') {
        const v = cocokVersi(p, r.header)
        if (v) return v
        const dasar = teks(isi, 'dasar_perubahan', 3, 200)
        if (salah(dasar)) return dasar
        if (p.kode === 'superadmin') return error(409, 'peran_bawaan_terkunci', 'Isi peran Superadmin tidak bisa diubah dari layar.')
        if (pegangPeran(p.id)) return error(403, 'peran_sendiri', 'Anda tidak bisa mengubah peran yang sedang Anda pegang.')
        const baru = new Set(Array.isArray(isi.izin) ? (isi.izin as string[]) : [])
        const tidakDikenal = [...baru].filter((k) => !perKode.has(k))
        if (tidakDikenal.length) return error(422, 'izin_tidak_dikenal', 'Ada kode izin yang tidak dikenal.', tidakDikenal.map((kode) => ({ kolom: 'izin', kode, pesan: 'Kode izin tidak dikenal.' })))
        const tanpaLihat = [...baru].filter((k) => {
          const lihat = lihatDari(perKode.get(k)!.node)
          return lihat !== undefined && !baru.has(lihat)
        })
        if (tanpaLihat.length) return error(422, 'lihat_wajib', 'Aksi apa pun mensyaratkan izin lihat pada node yang sama.', tanpaLihat.map((kode) => ({ kolom: 'izin', kode, pesan: 'Butuh izin lihat.' })))
        const tanpaInduk = [...baru].filter((k) => {
          const s = perKode.get(k)!.subInduk
          const lihat = s ? lihatDari(s) : undefined
          return lihat !== undefined && !baru.has(lihat)
        })
        if (tanpaInduk.length) return error(422, 'induk_belum_diberikan', 'Bagian mensyaratkan sub-menu induknya diberi lihat.', tanpaInduk.map((kode) => ({ kolom: 'izin', kode, pesan: 'Sub-menu induk belum diberi lihat.' })))
        const ditambah = [...baru].filter((k) => !p.izin.has(k))
        const sensitifBaru = ditambah.filter(sensitif)
        if (sensitifBaru.length) {
          return error(409, 'butuh_persetujuan', 'Menambah izin sensitif butuh persetujuan pemilik data. Alur persetujuan tersedia di tiket 1.2d; tidak ada yang disimpan.', sensitifBaru.map((kode) => ({ kode, pesan: 'Izin sensitif' })))
        }
        const dicabut = [...p.izin].filter((k) => !baru.has(k))
        p.izin = baru
        p.versi++
        p.diubah_pada = iso()
        return denganEtag(p, 200, { perubahan: { ditambah: ditambah.sort(), dicabut: dicabut.sort(), akun_terdampak: jumlahAkun(p.id) } })
      }
    }

    if (a0 === 'akun') {
      if (m === 'GET' && !a1) {
        const cari = (r.kueri.get('cari') ?? '').toLowerCase()
        const status = r.kueri.get('status')
        const peranId = r.kueri.get('peran_id')
        const mfa = r.kueri.get('mfa')
        const daftar = [...akun.values()]
          .filter((a) => !cari || a.nama_pengguna.includes(cari) || a.nama.toLowerCase().includes(cari))
          .filter((a) => !status || (status === 'terkunci' ? a.terkunci_sampai !== null && a.terkunci_sampai > iso() : a.status === status))
          .filter((a) => !peranId || peranBerlaku(a).some((p) => p.id === peranId))
          .filter((a) => !mfa || (mfa === 'aktif') === (a.mfa_aktif_pada !== null))
          .map((a) => ({
            id: a.id, nama_pengguna: a.nama_pengguna, nama: a.nama, status: a.status, terkunci_sampai: a.terkunci_sampai, login_terakhir_pada: a.login_terakhir_pada,
            peran: a.penugasan.filter(berlaku).map((x) => ({ id: x.peran.id, nama: x.peran.nama, berlaku_sampai: x.berlaku_sampai })),
            pengecualian: {
              tambah: a.izin_khusus.filter((k) => berlaku(k) && k.efek === 'tambah').length,
              cabut: a.izin_khusus.filter((k) => berlaku(k) && k.efek === 'cabut').length,
            },
            mfa_aktif: a.mfa_aktif_pada !== null,
          }))
        return halaman(daftar, r.kueri)
      }
      const a = a1 ? akun.get(a1) : undefined
      if (!a) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET' && !a2) return ok({ data: akunRinci(a) })
      if (m === 'GET' && a2 === 'menu') return ok({ data: menuEfektif(a) })
      if (a.id === penggunaId) return error(403, 'diri_sendiri', 'Perubahan akun sendiri dilakukan superadmin lain.')
      if (terkunciSuperadmin(a)) return error(403, 'akun_superadmin', 'Akses akun superadmin hanya diubah oleh superadmin lain.')

      if (m === 'POST' && a2 === 'penugasan' && !a3) {
        const dasar = teks(isi, 'dasar_pemberian', 3, 200)
        if (salah(dasar)) return dasar
        const p = typeof isi.peran_id === 'string' ? peran.get(isi.peran_id) : undefined
        if (!p) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'peran_id', pesan: 'Pilih peran.' }])
        if ((isi.cakupan_jenis as CakupanJenis) !== 'rs') return error(422, 'cakupan_belum_didukung', 'Cakupan selain Seluruh RS menunggu data unit.')
        if (p.kode === 'superadmin') return error(409, 'peran_bawaan_terkunci', 'Peran Superadmin hanya bisa ditugaskan lewat konsol sampai alur persetujuan tersedia (tiket 1.2d).')
        if (p.status !== 'aktif') return error(409, 'peran_nonaktif', 'Peran ini nonaktif.')
        if (memuatSensitif(p)) return error(409, 'butuh_persetujuan', 'Peran ini memuat izin sensitif; penugasannya butuh persetujuan pemilik data.')
        if (a.penugasan.some((x) => x.peran.id === p.id && x.status === 'aktif' && berlaku(x))) return error(409, 'penugasan_ganda', 'Peran dan cakupan yang sama masih berlaku untuk akun ini.')
        const baru = penugasan(p.id, {
          berlaku_dari: typeof isi.berlaku_dari === 'string' ? isi.berlaku_dari : iso(),
          berlaku_sampai: typeof isi.berlaku_sampai === 'string' ? isi.berlaku_sampai : null,
          dasar_pemberian: dasar!,
          alasan: typeof isi.alasan === 'string' ? isi.alasan : null,
          diberikan_oleh: { id: penggunaId, nama: akun.get(penggunaId)!.nama },
          dibuat_pada: iso(),
        })
        a.penugasan.push(baru)
        return ok({ data: segarkan(baru) }, 201)
      }
      if (m === 'POST' && (a2 === 'penugasan' || a2 === 'izin-khusus') && a3 && a4 === 'batal') {
        const alasan = teks(isi, 'alasan_batal', 5, 500)
        if (salah(alasan)) return alasan
        const daftar: (Penugasan | IzinKhusus)[] = a2 === 'penugasan' ? a.penugasan : a.izin_khusus
        const x = daftar.find((d) => d.id === a3)
        if (!x) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
        if (x.status === 'batal') return error(409, 'sudah_batal', 'Sudah diakhiri sebelumnya.')
        if (a2 === 'penugasan' && (x as Penugasan).peran.kode === 'superadmin') {
          return error(409, 'peran_bawaan_terkunci', 'Penugasan superadmin diakhiri dengan persetujuan superadmin lain (tiket 1.2d).')
        }
        Object.assign(x, { status: 'batal', alasan_batal: alasan, dibatalkan_oleh: { id: penggunaId, nama: akun.get(penggunaId)!.nama }, dibatalkan_pada: iso() })
        return ok({ data: segarkan(x) })
      }
      if (m === 'POST' && a2 === 'izin-khusus' && !a3) {
        const alasan = teks(isi, 'alasan', 5, 500)
        if (salah(alasan)) return alasan
        const dasar = teks(isi, 'dasar_pemberian', 3, 200)
        if (salah(dasar)) return dasar
        const kode = String(isi.sasaran_kode ?? '')
        const jenis = isi.sasaran_jenis === 'izin' ? 'izin' : 'menu'
        const efek = isi.efek === 'tambah' ? 'tambah' : 'cabut'
        const info = jenis === 'izin' ? perKode.get(kode) : undefined
        const node = jenis === 'menu' ? nodePerKode.get(kode) : undefined
        if (!info && !node) return error(422, 'sasaran_tidak_dikenal', 'Sasaran tidak dikenal.', [{ kolom: 'sasaran_kode', kode, pesan: 'Tidak dikenal.' }])
        const peka = info?.izin.sensitif ?? false
        if (efek === 'tambah' && peka && !isi.berlaku_sampai) return error(422, 'berlaku_sampai_wajib', 'Pemberian izin sensitif wajib berbatas waktu.', [{ kolom: 'berlaku_sampai', pesan: 'Isi tanggal berakhir untuk izin sensitif.' }])
        if (efek === 'tambah' && peka) return error(409, 'butuh_persetujuan', 'Izin sensitif hanya bisa diberikan dengan persetujuan pemilik data.', [{ kode, pesan: 'Izin sensitif' }])
        if (a.izin_khusus.some((k) => k.sasaran_kode === kode && k.efek === efek && berlaku(k))) return error(409, 'pengecualian_ganda', 'Pengecualian yang sama masih berlaku.')
        const label = info ? `${info.jalur} → ${info.izin.jenis_aksi === 'lihat_rupiah' ? 'Lihat rupiah' : info.izin.jenis_aksi[0]!.toUpperCase() + info.izin.jenis_aksi.slice(1)}` : node!.jalur
        const baru: IzinKhusus = {
          id: idBaru(), efek, sasaran_jenis: jenis, sasaran_kode: kode, sasaran_label: label, sensitif: peka, berlaku_dari: iso(),
          berlaku_sampai: typeof isi.berlaku_sampai === 'string' ? isi.berlaku_sampai : null, dasar_pemberian: dasar!, alasan: alasan!,
          diberikan_oleh: { id: penggunaId, nama: akun.get(penggunaId)!.nama }, status: 'aktif', berlaku_sekarang: true,
          alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, dibuat_pada: iso(),
        }
        a.izin_khusus.push(baru)
        return ok({ data: baru }, 201)
      }
      if (m === 'PUT' && a2 === 'wajib-mfa') {
        const alasan = teks(isi, 'alasan', 5, 500)
        if (salah(alasan)) return alasan
        a.wajib_mfa = isi.wajib_mfa === true
        return ok({ data: statusMfa(a) })
      }
      if (m === 'POST' && a2 === 'mfa' && a3 === 'reset') {
        const alasan = teks(isi, 'alasan', 5, 500)
        if (salah(alasan)) return alasan
        const dasar = teks(isi, 'dasar', 3, 200)
        if (salah(dasar)) return dasar
        if (!a.mfa_aktif_pada) return error(409, 'mfa_belum_aktif', 'MFA akun ini belum aktif.')
        a.mfa_aktif_pada = null
        return ok({ data: statusMfa(a) })
      }
    }
    return null
  }

  return { tangani, pohon }
}

export type ServerTiruan = ReturnType<typeof buatServerTiruan>
