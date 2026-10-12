import type { GrupMenu, Menu, MenuSaya } from '../tipeMenu'
import type { AkunRinci, CakupanJenis, IzinKhusus, IzinNode, NodeMenu, Penugasan, PeranRinci, PohonMenu, StatusMfaAkun } from '../tipePengaturan'
import type { JenisPersetujuan, PermintaanAkses, PersetujuanAkses } from '../tipePersetujuan'
import { buatPohon, izinMenu, uid } from './dataPengaturan'

/**
 * Server tiruan kontrak docs/api/openapi.yaml v0.2.3 (tiket 1.2c) dan v0.4.0 (tiket 1.2d: persetujuan dan permintaan
 * akses, 202 untuk pemberian berisiko): menyimpan keadaan di memori dan meniru aturan yang tertulis di kontrak,
 * termasuk error 403/409/412/422/428. Akun yang masuk bisa diganti (`sebagai`) agar alur dua orang teruji. Dipakai unit test,
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

interface PersetujuanTiruan {
  id: string
  nomor: string
  jenis: JenisPersetujuan
  objek: PersetujuanAkses['objek']
  peranId: string | null
  izinKode: string | null
  sasaranAkunId: string | null
  penyetujuPeranId: string
  status: 'menunggu' | 'disetujui' | 'ditolak' | 'kedaluwarsa' | 'dibatalkan'
  diterapkan: boolean
  diajukanOleh: string
  diajukanPada: string
  alasan: string | null
  dasar: string
  kedaluwarsaPada: string
  diputuskanOleh: string | null
  diputuskanPada: string | null
  catatan: string | null
  grupId: string
  /** Menerapkan perubahan saat seluruh grup disetujui; melempar string = tidak bisa diterapkan. */
  terapkan: () => void
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
  /** Akun baru dari pegawai (1.8d): endpoint bersesi lain 403 wajib_ganti_sandi sampai POST /saya/sandi. */
  wajib_ganti_sandi?: boolean
  pegawai_id?: string | null
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
  /** Data unit dari server tiruan organisasi (cakupan `unit`, kontrak v0.3.0); tanpa ini hanya cakupan `rs`. */
  unit?: { label: (id: string) => string | null; aktif: (id: string) => boolean }
  /** Akun yang dianggap sedang masuk (diri_sendiri). Bawaan: contoh.superadmin. */
  penggunaId?: string
  sekarang?: () => Date
  /** Status pegawai (server tiruan SDM, 1.8d): aktifkan akun milik pegawai `keluar` → 409 pegawai_keluar. */
  pegawaiKeluar?: (pegawaiId: string) => boolean
}

/** Sandi akun yang masuk di server tiruan (dev/unit test saja; bukan sandi sungguhan). */
export const SANDI_TIRUAN = 'sandi-tiruan-lokal-1'

export const ID_SUPERADMIN = uid(1)
/** Superadmin kedua (penyetuju K4/K13) dan pemilik data kasir, tiket 1.2d. */
export const ID_SUPERADMIN_DUA = uid(9)
export const ID_KABID = uid(10)

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
  // Superadmin juga memegang Profil RS (1.8a-be: MenuBawaan::SUB_MENU_SUPERADMIN).
  tambahPeran(SA, 'superadmin', 'Superadmin', izinMenu(pohon, []).concat(['pengaturan.peran', 'pengaturan.akun'].flatMap((k) => ['lihat', 'input', 'perbaiki'].map((a) => `${k}.${a}`)), ['pengaturan.profil_rs.lihat', 'pengaturan.profil_rs.perbaiki'], ['lihat', 'input', 'perbaiki'].map((a) => `pengaturan.persetujuan.${a}`)), {
    bawaan_sistem: true,
    wajib_mfa: true,
    uraian: 'Mengelola menu, peran, dan akun. Tidak membuka rekam medis atau angka rupiah (PRD 9.2.4).',
  })
  tambahPeran(uid(102), 'contoh_admisi', 'Contoh Petugas Admisi', izinMenu(pohon, ['pendaftaran']))
  tambahPeran(uid(103), 'contoh_perawat', 'Contoh Perawat', izinMenu(pohon, ['igd', 'rawat_inap']))
  tambahPeran(uid(104), 'contoh_dokter', 'Contoh Dokter', izinMenu(pohon, ['igd', 'rawat_jalan', 'rekam_medis']))
  tambahPeran(uid(105), 'contoh_kasir', 'Contoh Kasir', [...izinMenu(pohon, ['kasir']), 'kasir.tagihan.cetak', 'kasir.tagihan.lihat_rupiah'], { wajib_mfa: true })
  tambahPeran(uid(106), 'contoh_lama', 'Contoh Peran Lama', [], { status: 'nonaktif' })
  // Pemilik data induk organisasi (1.8a-be: akun contoh.data.induk); superadmin tidak memegangnya.
  tambahPeran(uid(107), 'contoh_data_induk', 'Contoh Admin Data Induk', ['lihat', 'input', 'perbaiki'].map((a) => `master_data.organisasi.${a}`))
  // Pengelola data pegawai (1.8d); bagian identitas (NIK utuh) tidak ikut, diberikan tersendiri lewat persetujuan.
  tambahPeran(uid(109), 'contoh_sdm', 'Contoh Admin SDM', [
    ...['pegawai', 'kredensial'].flatMap((s) => ['lihat', 'input', 'perbaiki'].map((a) => `sdm.${s}.${a}`)),
    'master_data.organisasi.lihat',
  ])
  // Bagian identitas (NIK utuh) untuk uji; tidak dipasang ke akun mana pun secara bawaan.
  tambahPeran(uid(110), 'contoh_sdm_identitas', 'Contoh Pembaca NIK Pegawai', ['sdm.pegawai.lihat', 'sdm.pegawai.identitas.lihat'])
  // Pemilik data contoh (1.2d): penyetuju izin sensitif kasir; membuka antrean persetujuan lewat peran (kontrak v0.4.0).
  const KABID = uid(108)
  tambahPeran(KABID, 'contoh_kabid_keuangan', 'Contoh Kabid Keuangan', ['pengaturan.persetujuan.lihat'])

  // Penyetuju izin sensitif (K3): data awal K13 = superadmin untuk pengelola akses; kasir = Kabid Keuangan; pelepasan RM
  // sengaja belum ditetapkan (422 penyetuju_belum_ditetapkan). Disimpan di node pohon agar GET /menu ikut berubah.
  const ringkas = (id: string) => {
    const x = peran.get(id)!
    return { id: x.id, kode: x.kode, nama: x.nama }
  }
  for (const [kode, info] of perKode) {
    if (!info.izin.sensitif) continue
    info.izin.penyetuju_peran = kode.startsWith('pengaturan.') ? ringkas(SA) : kode.startsWith('kasir.') ? ringkas(KABID) : null
  }
  const penyetujuIzin = (kode: string) => perKode.get(kode)?.izin.penyetuju_peran?.id ?? null

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
  // 1.2d: superadmin kedua (penyetuju K4/K13) dan pemilik data kasir.
  tambahAkun(ID_SUPERADMIN_DUA, 'contoh.superadmin.dua', 'Contoh Superadmin Kedua', [SA], { mfa_aktif_pada: '2026-10-01T02:00:00.000Z' })
  tambahAkun(ID_KABID, 'contoh.kabid', 'Contoh Kabid Keuangan', [KABID], { mfa_aktif_pada: '2026-10-01T02:00:00.000Z' })
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

  let penggunaId = opsi.penggunaId ?? ID_SUPERADMIN

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
      izin_menunggu: persetujuan
        .filter((x) => x.objek.jenis === 'izin_peran' && x.peranId === p.id && masihMenunggu(x))
        .map((x) => ({ kode: x.izinKode!, persetujuan_id: x.id, penyetuju_peran: ringkas(x.penyetujuPeranId), kedaluwarsa_pada: x.kedaluwarsaPada }))
        .sort((x, y) => x.kode.localeCompare(y.kode)),
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
      // Satu baris per pengajuan (grup), meski butuh beberapa penyetuju.
      menunggu_persetujuan: persetujuan
        .filter((x) => x.sasaranAkunId === a.id && ['penugasan', 'pengakhiran_penugasan', 'izin_khusus'].includes(x.objek.jenis) && masihMenunggu(x))
        .filter((x, i, xs) => xs.findIndex((y) => y.grupId === x.grupId) === i)
        .map(bentukPersetujuan),
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

  // ── Persetujuan dan permintaan akses (kontrak v0.4.0, tiket 1.2d) ──
  const persetujuan: PersetujuanTiruan[] = []
  const permintaan: (PermintaanAkses & { penggunaId: string })[] = []
  let nomorPsa = 0
  let nomorPma = 0
  const namaAkun = (id: string | null) => (id ? { id, nama: akun.get(id)?.nama ?? id } : null)
  const MASA_MS = 7 * 24 * 3600 * 1000
  const statusSekarang = (x: PersetujuanTiruan) => (x.status === 'menunggu' && x.kedaluwarsaPada <= iso() ? 'kedaluwarsa' : x.status)
  const masihMenunggu = (x: PersetujuanTiruan) => statusSekarang(x) === 'menunggu'
  const peranDipegang = (a: AkunTiruan) => new Set(peranBerlaku(a).map((p) => p.id))
  const labelIzin = (kode: string) => {
    const info = perKode.get(kode)
    if (!info) return kode
    const aksi = info.izin.jenis_aksi === 'lihat_rupiah' ? 'Lihat rupiah' : info.izin.jenis_aksi[0]!.toUpperCase() + info.izin.jenis_aksi.slice(1)
    return `${info.jalur} → ${aksi}`
  }
  const objekPeran = (x: PersetujuanTiruan) => x.objek.jenis === 'izin_peran' || x.objek.jenis === 'penyetuju_izin'

  /** Kode 403 bila akun yang masuk tidak boleh memutuskan; null = boleh. */
  function alasanTidakBoleh(x: PersetujuanTiruan): string | null {
    const saya = akun.get(penggunaId)!
    if (!peranDipegang(saya).has(x.penyetujuPeranId)) return 'bukan_penyetuju'
    if (x.diajukanOleh === penggunaId) return 'pengaju_sendiri'
    if (x.sasaranAkunId === penggunaId) return 'penerima_sendiri'
    if (objekPeran(x) && x.peranId && saya.penugasan.some((p) => p.peran.id === x.peranId && p.status === 'aktif')) return 'penerima_sendiri'
    return null
  }
  const terlihat = (x: PersetujuanTiruan) =>
    x.diajukanOleh === penggunaId || x.sasaranAkunId === penggunaId || peranDipegang(akun.get(penggunaId)!).has(x.penyetujuPeranId)

  function bentukPersetujuan(x: PersetujuanTiruan): PersetujuanAkses {
    const grup = persetujuan.filter((y) => y.grupId === x.grupId)
    const status = statusSekarang(x)
    return {
      id: x.id, nomor: x.nomor, jenis: x.jenis, ringkasan: ringkasanPersetujuan(x), objek: x.objek,
      penyetuju_peran: ringkas(x.penyetujuPeranId), status, diterapkan: x.diterapkan,
      diajukan_oleh: namaAkun(x.diajukanOleh), diajukan_pada: x.diajukanPada, alasan: x.alasan, dasar: x.dasar,
      kedaluwarsa_pada: x.kedaluwarsaPada, diputuskan_oleh: namaAkun(x.diputuskanOleh), diputuskan_pada: x.diputuskanPada,
      catatan_keputusan: x.catatan,
      grup: { id: x.grupId, jumlah: grup.length, disetujui: grup.filter((y) => y.status === 'disetujui').length },
      akun_terdampak: objekPeran(x) && x.peranId ? jumlahAkun(x.peranId) : 1,
      dapat_diputuskan: status === 'menunggu' && alasanTidakBoleh(x) === null,
    }
  }
  function ringkasanPersetujuan(x: PersetujuanTiruan): string {
    const o = x.objek
    const peranNama = o.peran?.nama ?? '-'
    const akunNama = o.akun?.nama ?? '-'
    const izin = o.izin?.label ?? '-'
    switch (o.jenis) {
      case 'izin_peran': return `Tambah izin ${izin} ke peran ${peranNama}`
      case 'penugasan': return `Tugaskan peran ${peranNama} ke ${akunNama}${o.cakupan_label ? ` (${o.cakupan_label})` : ''}`
      case 'pengakhiran_penugasan': return `Akhiri penugasan peran ${peranNama} untuk ${akunNama}`
      case 'izin_khusus': return `Tambah pengecualian ${izin} untuk ${akunNama}`
      case 'penyetuju_izin': return `Tetapkan peran ${peranNama} sebagai penyetuju izin ${izin}`
    }
  }

  /** Catat pengajuan (satu baris per peran penyetuju, satu grup); 202 berisi baris pertama. */
  function ajukan(
    jenis: JenisPersetujuan,
    objek: PersetujuanAkses['objek'],
    penyetuju: string[],
    terapkan: () => void,
    isi: { peranId?: string | null; izinKode?: string | null; sasaranAkunId?: string | null; dasar: string; alasan: string | null },
  ): ResponsTiruan {
    const grupId = idBaru()
    const baris = [...new Set(penyetuju)].map((penyetujuPeranId) => {
      const x: PersetujuanTiruan = {
        id: idBaru(), nomor: `PSA-2026-${String(++nomorPsa).padStart(6, '0')}`, jenis, objek, penyetujuPeranId,
        peranId: isi.peranId ?? null, izinKode: isi.izinKode ?? null, sasaranAkunId: isi.sasaranAkunId ?? null,
        status: 'menunggu', diterapkan: false, diajukanOleh: penggunaId, diajukanPada: iso(), alasan: isi.alasan, dasar: isi.dasar,
        kedaluwarsaPada: new Date(sekarang().getTime() + MASA_MS).toISOString(), diputuskanOleh: null, diputuskanPada: null, catatan: null,
        grupId, terapkan,
      }
      persetujuan.push(x)
      return x
    })
    return ok({ data: bentukPersetujuan(baris[0]!) }, 202)
  }
  function tutupGrup(x: PersetujuanTiruan, status: PersetujuanTiruan['status'], oleh: string | null, catatan: string | null) {
    for (const y of persetujuan.filter((z) => z.grupId === x.grupId && z.status === 'menunggu')) {
      Object.assign(y, { status, diputuskanOleh: oleh, diputuskanPada: iso(), catatan })
    }
  }
  const tidakAdaPenyetuju = (kode: string[]) =>
    error(422, 'penyetuju_belum_ditetapkan', 'Izin sensitif ini belum punya penyetuju (pemilik data). Tetapkan dulu di pengaturan izin.',
      kode.map((k) => ({ kolom: 'izin', kode: k, pesan: `${labelIzin(k)} belum punya penyetuju.` })))
  /** Peran yang menjadi penyetuju izin sensitif mana pun (penugasannya butuh persetujuan superadmin lain). */
  const peranPenyetuju = (peranId: string) => [...perKode.values()].some((i) => i.izin.sensitif && i.izin.penyetuju_peran?.id === peranId)
  const permintaanValid = (a: AkunTiruan, id: unknown) => {
    if (id === undefined || id === null) return null
    const pm = permintaan.find((x) => x.id === id)
    return pm && pm.status === 'menunggu' && pm.penggunaId === a.id ? pm : false
  }
  const tindakLanjuti = (pm: (typeof permintaan)[number] | null) => {
    if (pm) Object.assign(pm, { status: 'ditindaklanjuti', diputuskan_oleh: namaAkun(penggunaId), diputuskan_pada: iso() })
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
          wajib_ganti_sandi: a.wajib_ganti_sandi ?? false,
          pegawai: { id: a.pegawai_id ?? uid(9000), nama: a.nama },
          peran: peranSaya,
          masuk_sebelumnya_pada: '2026-10-09T23:48:00.000Z',
          masuk_sebelumnya_dari_jaringan_rs: true,
        },
      })
    }
    if (m === 'GET' && r.jalur === '/saya/menu') return ok({ data: menuEfektif(akun.get(penggunaId)!) })
    if (m === 'POST' && r.jalur === '/saya/sandi') return gantiSandi(akun.get(penggunaId)!, isi)
    if (m === 'POST' && r.jalur === '/autentikasi/keluar') return ok(null, 204)
    if (m === 'GET' && r.jalur === '/menu') return ok({ data: pohon })

    // ── 1.2d: persetujuan akses (tanpa izin khusus; hanya yang relevan bagi akun ini) ──
    if (a0 === 'persetujuan-akses') {
      if (m === 'GET' && !a1) {
        const tab = r.kueri.get('tab')
        if (tab !== 'perlu_saya_putuskan' && tab !== 'saya_ajukan') return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'tab', pesan: 'Pilih tab.' }])
        const status = r.kueri.get('status')
        const daftar = persetujuan
          .filter((x) => (tab === 'perlu_saya_putuskan' ? masihMenunggu(x) && alasanTidakBoleh(x) === null : x.diajukanOleh === penggunaId))
          .filter((x) => !status || statusSekarang(x) === status)
          .sort((x, y) => y.diajukanPada.localeCompare(x.diajukanPada) || y.id.localeCompare(x.id))
          .map(bentukPersetujuan)
        return halaman(daftar, r.kueri)
      }
      const x = persetujuan.find((y) => y.id === a1)
      if (!x || !terlihat(x)) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET' && !a2) return ok({ data: bentukPersetujuan(x) })
      if (m === 'POST' && (a2 === 'setujui' || a2 === 'tolak')) {
        const alasanTolak = a2 === 'tolak' ? teks(isi, 'alasan', 5, 500) : null
        if (salah(alasanTolak)) return alasanTolak
        const tidakBoleh = alasanTidakBoleh(x)
        if (tidakBoleh) {
          const pesan: Record<string, string> = {
            bukan_penyetuju: 'Anda bukan penyetuju yang ditunjuk untuk pengajuan ini.',
            pengaju_sendiri: 'Pengaju tidak bisa memutuskan pengajuannya sendiri.',
            penerima_sendiri: 'Penerima akses tidak bisa memutuskan pengajuan untuk dirinya.',
          }
          return error(403, tidakBoleh, pesan[tidakBoleh]!)
        }
        if (statusSekarang(x) !== 'menunggu') {
          if (statusSekarang(x) === 'kedaluwarsa' && x.status === 'menunggu') tutupGrup(x, 'kedaluwarsa', null, 'Kedaluwarsa tanpa keputusan (7 hari)')
          return error(409, 'sudah_diputuskan', 'Pengajuan ini sudah diputuskan, kedaluwarsa, atau dibatalkan.')
        }
        if (a2 === 'tolak') {
          Object.assign(x, { status: 'ditolak', diputuskanOleh: penggunaId, diputuskanPada: iso(), catatan: alasanTolak })
          tutupGrup(x, 'ditolak', penggunaId, alasanTolak)
          return ok({ data: bentukPersetujuan(x) })
        }
        Object.assign(x, { status: 'disetujui', diputuskanOleh: penggunaId, diputuskanPada: iso(), catatan: typeof isi.catatan === 'string' && isi.catatan ? isi.catatan : null })
        const grup = persetujuan.filter((y) => y.grupId === x.grupId)
        if (grup.every((y) => y.status === 'disetujui')) {
          try {
            x.terapkan()
            grup.forEach((y) => (y.diterapkan = true))
          } catch (e) {
            for (const y of grup) Object.assign(y, { status: 'ditolak', diputuskanOleh: null, diputuskanPada: iso(), catatan: `Tidak bisa diterapkan: ${String(e)}` })
            return error(409, 'tidak_bisa_diterapkan', `Pengajuan ini tidak bisa diterapkan lagi: ${String(e)}`)
          }
        }
        return ok({ data: bentukPersetujuan(x) })
      }
      if (m === 'POST' && a2 === 'batalkan') {
        if (x.diajukanOleh !== penggunaId) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
        const alasan = teks(isi, 'alasan', 5, 500)
        if (salah(alasan)) return alasan
        if (statusSekarang(x) !== 'menunggu') return error(409, 'sudah_diputuskan', 'Pengajuan ini sudah diputuskan, kedaluwarsa, atau dibatalkan.')
        tutupGrup(x, 'dibatalkan', penggunaId, alasan)
        return ok({ data: bentukPersetujuan(x) })
      }
    }

    // ── 1.2d: permintaan akses dari layar Akses ditolak ──
    if (a0 === 'permintaan-akses') {
      const saya = akun.get(penggunaId)!
      if (m === 'GET' && !a1) {
        const milikSaya = r.kueri.get('milik_saya') === 'true'
        if (!milikSaya && !izinEfektif(saya).has('pengaturan.akun.lihat')) return error(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.')
        const status = r.kueri.get('status')
        const daftar = permintaan
          .filter((x) => (!milikSaya || x.penggunaId === penggunaId) && (!status || x.status === status))
          .sort((x, y) => y.dibuat_pada.localeCompare(x.dibuat_pada))
          .map(({ penggunaId: _p, ...x }) => x)
        return halaman(daftar, r.kueri)
      }
      if (m === 'POST' && !a1) {
        const alasan = teks(isi, 'alasan', 5, 500)
        if (salah(alasan)) return alasan
        const jenis = isi.sasaran_jenis === 'izin' ? 'izin' : 'menu'
        const kode = String(isi.sasaran_kode ?? '')
        const node = jenis === 'menu' ? nodePerKode.get(kode) : undefined
        const info = jenis === 'izin' ? perKode.get(kode) : undefined
        if (!node && !info) return error(422, 'sasaran_tidak_dikenal', 'Sasaran tidak dikenal.', [{ kolom: 'sasaran_kode', kode, pesan: 'Tidak dikenal.' }])
        const izinSaya = izinEfektif(saya)
        const sudah = info ? izinSaya.has(kode) : (lihatDari(node!.node) ? izinSaya.has(lihatDari(node!.node)!) : false)
        if (sudah) return error(409, 'sudah_punya_akses', 'Anda sudah punya akses ini. Muat ulang halaman.')
        if (permintaan.some((x) => x.penggunaId === penggunaId && x.sasaran_kode === kode && x.status === 'menunggu')) {
          return error(409, 'permintaan_ganda', 'Permintaan untuk akses yang sama masih menunggu.')
        }
        const baru: PermintaanAkses & { penggunaId: string } = {
          id: idBaru(), nomor: `PMA-2026-${String(++nomorPma).padStart(6, '0')}`, penggunaId,
          akun: { id: saya.id, nama_pengguna: saya.nama_pengguna, nama: saya.nama }, sasaran_jenis: jenis, sasaran_kode: kode,
          sasaran_label: info ? labelIzin(kode) : node!.jalur, alasan: alasan!, status: 'menunggu',
          diputuskan_oleh: null, diputuskan_pada: null, catatan_keputusan: null, dibuat_pada: iso(),
        }
        permintaan.push(baru)
        const { penggunaId: _p, ...bentuk } = baru
        return ok({ data: bentuk }, 201)
      }
      if (m === 'POST' && a2 === 'tolak') {
        if (!izinEfektif(saya).has('pengaturan.akun.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.')
        const x = permintaan.find((y) => y.id === a1)
        if (!x) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
        const alasan = teks(isi, 'alasan', 5, 500)
        if (salah(alasan)) return alasan
        if (x.penggunaId === penggunaId) return error(403, 'diri_sendiri', 'Permintaan Anda sendiri diputuskan admin lain.')
        if (x.status !== 'menunggu') return error(409, 'sudah_diputuskan', 'Permintaan ini sudah diputuskan.')
        Object.assign(x, { status: 'ditolak', diputuskan_oleh: namaAkun(penggunaId), diputuskan_pada: iso(), catatan_keputusan: alasan })
        const { penggunaId: _p, ...bentuk } = x
        return ok({ data: bentuk })
      }
    }

    // ── 1.2d: penyetuju izin sensitif (kontrol ganda: 202 menunggu superadmin lain) ──
    if (a0 === 'izin' && a2 === 'penyetuju' && m === 'PUT') {
      const kode = decodeURIComponent(a1 ?? '')
      const info = perKode.get(kode)
      if (!info) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (!izinEfektif(akun.get(penggunaId)!).has('pengaturan.peran.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.')
      const dasar = teks(isi, 'dasar_perubahan', 3, 200)
      if (salah(dasar)) return dasar
      if (!info.izin.sensitif) return error(422, 'izin_tidak_sensitif', 'Penyetuju hanya untuk izin sensitif.')
      const target = typeof isi.peran_id === 'string' ? peran.get(isi.peran_id) : undefined
      if (!target || target.status !== 'aktif') return error(422, 'peran_tidak_valid', 'Pilih peran aktif.', [{ kolom: 'peran_id', pesan: 'Pilih peran aktif.' }])
      if (pegangPeran(target.id)) return error(403, 'peran_sendiri', 'Anda tidak bisa menunjuk peran yang Anda pegang.')
      if (persetujuan.some((x) => x.objek.jenis === 'penyetuju_izin' && x.izinKode === kode && masihMenunggu(x))) {
        return error(409, 'persetujuan_ganda', 'Penggantian penyetuju izin ini masih menunggu.')
      }
      return ajukan('izin_sensitif', { jenis: 'penyetuju_izin', peran: ringkas(target.id), izin: { kode, label: labelIzin(kode) } }, [SA], () => {
        info.izin.penyetuju_peran = ringkas(target.id)
      }, { peranId: target.id, izinKode: kode, dasar: dasar!, alasan: null })
    }

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
        // 1.2d: izin sensitif baru menjadi persetujuan menunggu (satu per izin); yang sudah menunggu tidak diajukan ulang.
        const sensitifBaru = [...baru].filter((k) => !p.izin.has(k) && sensitif(k))
        const tanpaPenyetuju = sensitifBaru.filter((k) => !penyetujuIzin(k))
        if (tanpaPenyetuju.length) return tidakAdaPenyetuju(tanpaPenyetuju)
        const menunggu = sensitifBaru.map((kode) => {
          const lama = persetujuan.find((x) => x.objek.jenis === 'izin_peran' && x.peranId === p.id && x.izinKode === kode && masihMenunggu(x))
          if (lama) return bentukPersetujuan(lama)
          const respons = ajukan('izin_sensitif', { jenis: 'izin_peran', peran: ringkas(p.id), izin: { kode, label: labelIzin(kode) } }, [penyetujuIzin(kode)!], () => {
            p.izin.add(kode)
            p.versi++
            p.diubah_pada = iso()
          }, { peranId: p.id, izinKode: kode, dasar: dasar!, alasan: typeof isi.alasan === 'string' ? isi.alasan : null })
          return (respons.isi as { data: PersetujuanAkses }).data
        })
        // Izin menunggu yang tidak dikirim lagi = ditarik (persetujuan dibatalkan), sama dengan backend 1.2d.
        for (const x of persetujuan.filter((y) => y.objek.jenis === 'izin_peran' && y.peranId === p.id && masihMenunggu(y) && !baru.has(y.izinKode!))) {
          tutupGrup(x, 'dibatalkan', penggunaId, 'Ditarik dari matriks peran')
        }
        const ditambah = [...baru].filter((k) => !p.izin.has(k) && !sensitif(k))
        const dicabut = [...p.izin].filter((k) => !baru.has(k))
        p.izin = new Set([...baru].filter((k) => !sensitifBaru.includes(k)))
        p.versi++
        p.diubah_pada = iso()
        return denganEtag(p, 200, { perubahan: { ditambah: ditambah.sort(), dicabut: dicabut.sort(), akun_terdampak: jumlahAkun(p.id), menunggu_persetujuan: menunggu } })
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
      if (m === 'GET' && a1 === 'saran-peran') {
        const hitung = new Map<string, { efek: 'tambah' | 'cabut'; sasaran_jenis: 'menu' | 'izin'; sasaran_kode: string; sasaran_label: string; akun: Set<string> }>()
        for (const x of akun.values()) {
          for (const k of x.izin_khusus.filter(berlaku)) {
            const kunci = `${k.efek}:${k.sasaran_kode}`
            const h = hitung.get(kunci) ?? { efek: k.efek, sasaran_jenis: k.sasaran_jenis, sasaran_kode: k.sasaran_kode, sasaran_label: k.sasaran_label, akun: new Set<string>() }
            h.akun.add(x.id)
            hitung.set(kunci, h)
          }
        }
        const data = [...hitung.values()].filter((h) => h.akun.size >= 5).sort((x, y) => y.akun.size - x.akun.size).slice(0, 50)
          .map(({ akun: daftar, ...h }) => ({ ...h, jumlah_akun: daftar.size }))
        return ok({ data })
      }
      const a = a1 ? akun.get(a1) : undefined
      if (!a) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET' && !a2) return ok({ data: akunRinci(a) })
      if (m === 'GET' && a2 === 'menu') return ok({ data: menuEfektif(a) })
      if (a.id === penggunaId) return error(403, 'diri_sendiri', 'Perubahan akun sendiri dilakukan superadmin lain.')
      if (terkunciSuperadmin(a)) return error(403, 'akun_superadmin', 'Akses akun superadmin hanya diubah oleh superadmin lain.')

      // ── 1.8d: nonaktifkan / aktifkan dengan alasan ──
      if (m === 'POST' && (a2 === 'nonaktifkan' || a2 === 'aktifkan') && !a3) {
        const alasan = teks(isi, 'alasan', 5, 500)
        if (salah(alasan)) return alasan
        const ke = a2 === 'nonaktifkan' ? 'nonaktif' : 'aktif'
        if (a.status === ke) return error(409, ke === 'nonaktif' ? 'sudah_nonaktif' : 'sudah_aktif', ke === 'nonaktif' ? 'Akun ini sudah nonaktif.' : 'Akun ini sudah aktif.')
        if (superadmin(a)) return error(409, 'superadmin_aktif', 'Akun ini masih memegang peran Superadmin. Akhiri penugasannya lebih dulu lewat persetujuan superadmin lain.')
        if (ke === 'aktif' && a.pegawai_id && opsi.pegawaiKeluar?.(a.pegawai_id)) return error(409, 'pegawai_keluar', 'Pegawai pemilik akun ini sudah keluar. Batalkan dulu tanggal keluar di data pegawai.')
        a.status = ke
        return ok({ data: akunRinci(a) })
      }

      if (m === 'POST' && a2 === 'penugasan' && !a3) {
        const dasar = teks(isi, 'dasar_pemberian', 3, 200)
        if (salah(dasar)) return dasar
        const p = typeof isi.peran_id === 'string' ? peran.get(isi.peran_id) : undefined
        if (!p) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'peran_id', pesan: 'Pilih peran.' }])
        const jenisCakupan = isi.cakupan_jenis as CakupanJenis
        const cakupanId = typeof isi.cakupan_id === 'string' ? isi.cakupan_id : null
        if (jenisCakupan !== 'rs' && !(jenisCakupan === 'unit' && opsi.unit)) return error(422, 'cakupan_belum_didukung', 'Cakupan ini belum didukung.')
        if (jenisCakupan === 'rs' ? cakupanId !== null : !cakupanId || !opsi.unit!.aktif(cakupanId)) {
          return error(422, 'cakupan_tidak_valid', 'Pilih unit aktif untuk cakupan unit.', [{ kolom: 'cakupan_id', pesan: 'Pilih unit aktif.' }])
        }
        if (p.status !== 'aktif') return error(409, 'peran_nonaktif', 'Peran ini nonaktif.')
        if (a.penugasan.some((x) => x.peran.id === p.id && x.cakupan_id === cakupanId && x.status === 'aktif' && berlaku(x))) {
          return error(409, 'penugasan_ganda', 'Peran dan cakupan yang sama masih berlaku untuk akun ini.')
        }
        const pm = permintaanValid(a, isi.permintaan_akses_id)
        if (pm === false) return error(422, 'permintaan_tidak_valid', 'Permintaan akses itu bukan permintaan menunggu milik akun ini.')
        const buatBaru = () => penugasan(p.id, {
          cakupan_jenis: jenisCakupan,
          cakupan_id: cakupanId,
          cakupan_label: cakupanId ? opsi.unit!.label(cakupanId) : null,
          berlaku_dari: typeof isi.berlaku_dari === 'string' ? isi.berlaku_dari : iso(),
          berlaku_sampai: typeof isi.berlaku_sampai === 'string' ? isi.berlaku_sampai : null,
          dasar_pemberian: dasar!,
          alasan: typeof isi.alasan === 'string' ? isi.alasan : null,
          diberikan_oleh: { id: penggunaId, nama: akun.get(penggunaId)!.nama },
          dibuat_pada: iso(),
        })
        // 1.2d: superadmin → superadmin lain (K4); peran berizin sensitif → penyetuju tiap izin; peran penyetuju → superadmin lain.
        const sensitifPeran = [...p.izin].filter(sensitif)
        const penyetuju = [
          ...(p.kode === 'superadmin' || peranPenyetuju(p.id) ? [SA] : []),
          ...sensitifPeran.map(penyetujuIzin).filter((x): x is string => !!x),
        ]
        if (sensitifPeran.some((k) => !penyetujuIzin(k))) return tidakAdaPenyetuju(sensitifPeran.filter((k) => !penyetujuIzin(k)))
        if (penyetuju.length) {
          if (persetujuan.some((x) => x.objek.jenis === 'penugasan' && x.sasaranAkunId === a.id && x.peranId === p.id && x.objek.cakupan_label === (cakupanId ? opsi.unit!.label(cakupanId) : 'Seluruh RS') && masihMenunggu(x))) {
            return error(409, 'persetujuan_ganda', 'Penugasan yang sama masih menunggu persetujuan.')
          }
          tindakLanjuti(pm)
          return ajukan(p.kode === 'superadmin' ? 'peran_superadmin' : 'izin_sensitif',
            { jenis: 'penugasan', peran: ringkas(p.id), akun: { id: a.id, nama_pengguna: a.nama_pengguna, nama: a.nama }, cakupan_label: cakupanId ? opsi.unit!.label(cakupanId) : 'Seluruh RS', berlaku_sampai: typeof isi.berlaku_sampai === 'string' ? isi.berlaku_sampai : null },
            penyetuju, () => {
              if (p.status !== 'aktif') throw 'peran nonaktif'
              a.penugasan.push(buatBaru())
            }, { peranId: p.id, sasaranAkunId: a.id, dasar: dasar!, alasan: typeof isi.alasan === 'string' ? isi.alasan : null })
        }
        tindakLanjuti(pm)
        const baru = penugasan(p.id, {
          cakupan_jenis: jenisCakupan,
          cakupan_id: cakupanId,
          cakupan_label: cakupanId ? opsi.unit!.label(cakupanId) : null,
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
          const aktifSa = [...akun.values()].filter((y) => y.status === 'aktif' && superadmin(y)).length
          if (aktifSa <= 2) return error(409, 'superadmin_minimal', 'Minimal dua superadmin aktif harus tetap ada (PRD 9.2.4).')
          if (persetujuan.some((y) => y.objek.jenis === 'pengakhiran_penugasan' && y.objek.penugasan_id === x.id && masihMenunggu(y))) {
            return error(409, 'persetujuan_ganda', 'Pengakhiran ini masih menunggu persetujuan.')
          }
          return ajukan('peran_superadmin', { jenis: 'pengakhiran_penugasan', penugasan_id: x.id, peran: ringkas(SA), akun: { id: a.id, nama_pengguna: a.nama_pengguna, nama: a.nama } }, [SA], () => {
            Object.assign(x, { status: 'batal', alasan_batal: alasan, dibatalkan_oleh: { id: penggunaId, nama: akun.get(penggunaId)!.nama }, dibatalkan_pada: iso() })
          }, { peranId: SA, sasaranAkunId: a.id, dasar: alasan!, alasan })
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
        if (a.izin_khusus.some((k) => k.sasaran_kode === kode && k.efek === efek && berlaku(k))) return error(409, 'pengecualian_ganda', 'Pengecualian yang sama masih berlaku.')
        const pm = permintaanValid(a, isi.permintaan_akses_id)
        if (pm === false) return error(422, 'permintaan_tidak_valid', 'Permintaan akses itu bukan permintaan menunggu milik akun ini.')
        const label = info ? labelIzin(kode) : node!.jalur
        const buatKhusus = (): IzinKhusus => ({
          id: idBaru(), efek, sasaran_jenis: jenis, sasaran_kode: kode, sasaran_label: label, sensitif: peka, berlaku_dari: iso(),
          berlaku_sampai: typeof isi.berlaku_sampai === 'string' ? isi.berlaku_sampai : null, dasar_pemberian: dasar!, alasan: alasan!,
          diberikan_oleh: { id: penggunaId, nama: akun.get(penggunaId)!.nama }, status: 'aktif', berlaku_sekarang: true,
          alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, dibuat_pada: iso(),
        })
        if (efek === 'tambah' && peka) {
          if (!penyetujuIzin(kode)) return tidakAdaPenyetuju([kode])
          if (persetujuan.some((x) => x.objek.jenis === 'izin_khusus' && x.sasaranAkunId === a.id && x.izinKode === kode && masihMenunggu(x))) {
            return error(409, 'persetujuan_ganda', 'Pengecualian yang sama masih menunggu persetujuan.')
          }
          tindakLanjuti(pm)
          return ajukan('pengecualian', { jenis: 'izin_khusus', akun: { id: a.id, nama_pengguna: a.nama_pengguna, nama: a.nama }, izin: { kode, label }, berlaku_sampai: (isi.berlaku_sampai as string) ?? null },
            [penyetujuIzin(kode)!], () => void a.izin_khusus.push(buatKhusus()), { izinKode: kode, sasaranAkunId: a.id, dasar: dasar!, alasan: alasan! })
        }
        tindakLanjuti(pm)
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

  /** Dev: tambahkan peran contoh ke akun yang masuk (lihat serverGabungan). */
  /** POST /saya/sandi (kontrak v0.6.0): aturan PRD 14.2 versi tiruan (panjang 12, kontekstual, beda dari sandi lama). */
  function gantiSandi(a: AkunTiruan, isi: Isi): ResponsTiruan {
    if (isi.sandi_lama !== SANDI_TIRUAN) return error(422, 'sandi_lama_salah', 'Sandi lama salah.', [{ kolom: 'sandi_lama', pesan: 'Sandi lama salah.' }])
    const baru = typeof isi.sandi_baru === 'string' ? isi.sandi_baru : ''
    const tolak = (pesan: string) => error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'sandi_baru', pesan }])
    if (baru.length < 12) return tolak('Sandi baru minimal 12 karakter.')
    if (baru === isi.sandi_lama) return tolak('Sandi baru harus berbeda dari sandi lama.')
    const kecil = baru.toLowerCase()
    if (kecil.includes(a.nama_pengguna.toLowerCase()) || kecil.includes('karsitara')) return tolak('Sandi baru tidak boleh memuat nama pengguna, nama rumah sakit, atau nama produk.')
    a.wajib_ganti_sandi = false
    return tangani({ metode: 'get', jalur: '/saya', kueri: new URLSearchParams(), header: {}, isi: null })!
  }

  // ── Untuk server tiruan SDM (1.8d) ──
  const bolehSaya = (kode: string) => izinEfektif(akun.get(penggunaId)!).has(kode)
  const wajibGantiSandi = () => akun.get(penggunaId)?.wajib_ganti_sandi ?? false
  const ringkasAkunPegawai = (pegawaiId: string) => {
    const a = [...akun.values()].find((x) => x.pegawai_id === pegawaiId)
    return a ? { id: a.id, nama_pengguna: a.nama_pengguna, status: a.status } : null
  }
  /** Buat akun tanpa peran, wajib ganti sandi (POST /pegawai/{id}/akun). Melempar 'nama_pengguna_dipakai'. */
  function buatAkunPegawai(pegawaiId: string, namaPengguna: string, nama: string) {
    if ([...akun.values()].some((x) => x.nama_pengguna === namaPengguna)) throw new Error('nama_pengguna_dipakai')
    const id = idBaru()
    tambahAkun(id, namaPengguna, nama, [], { login_terakhir_pada: null, wajib_ganti_sandi: true, pegawai_id: pegawaiId })
    const a = akun.get(id)!
    return { id: a.id, nama_pengguna: a.nama_pengguna, nama: a.nama, status: a.status, terkunci_sampai: null, login_terakhir_pada: null, peran: [], pengecualian: { tambah: 0, cabut: 0 }, mfa_aktif: false }
  }
  const akunSuperadminPegawai = (pegawaiId: string) => {
    const a = [...akun.values()].find((x) => x.pegawai_id === pegawaiId)
    return a ? superadmin(a) : false
  }
  /** Pegawai keluar (tanggalnya tercapai): akun nonaktif (sesi diputus di backend). */
  function nonaktifkanAkunPegawai(pegawaiId: string) {
    const a = [...akun.values()].find((x) => x.pegawai_id === pegawaiId)
    if (a) a.status = 'nonaktif'
  }
  /** Sambungkan akun sintetis ke pegawai sintetis server SDM. */
  function tautkanPegawai(namaPengguna: string, pegawaiId: string) {
    const a = [...akun.values()].find((x) => x.nama_pengguna === namaPengguna)
    if (a) a.pegawai_id = pegawaiId
  }

  function tambahPenugasanDev(kodePeran: string) {
    const p = [...peran.values()].find((x) => x.kode === kodePeran)
    if (p) akun.get(penggunaId)!.penugasan.push(penugasan(p.id))
  }
  /** Unit dari penugasan ber-cakupan unit yang berlaku milik akun yang masuk (GET /unit-organisasi?milik_saya=true). */
  const unitMilikSaya = () =>
    akun.get(penggunaId)!.penugasan.filter((x) => berlaku(x) && x.cakupan_jenis === 'unit' && x.cakupan_id).map((x) => x.cakupan_id!)

  /** Uji: ganti akun yang sedang masuk (alur dua orang: pengaju dan penyetuju). */
  function sebagai(id: string) {
    penggunaId = id
  }
  return {
    tangani, pohon, tambahPenugasanDev, unitMilikSaya, sebagai,
    bolehSaya, wajibGantiSandi, ringkasAkunPegawai, buatAkunPegawai, akunSuperadminPegawai, nonaktifkanAkunPegawai, tautkanPegawai,
    /** Uji: jadikan akun yang masuk wajib ganti sandi. */
    wajibkanGantiSandi: () => void (akun.get(penggunaId)!.wajib_ganti_sandi = true),
  }
}

export type ServerTiruan = ReturnType<typeof buatServerTiruan>
