/**
 * Bentuk respons Pengaturan → Peran & izin, Akun & akses (docs/api/openapi.yaml v0.2.2, tiket 1.2c; tambahan v0.4.0
 * tiket 1.2d: penyetuju izin, keadaan menunggu persetujuan). Tanpa impor nilai agar data tiruan bisa dipakai e2e
 * (tsconfig.node) tanpa menarik klien API.
 */
import type { IzinMenunggu, PersetujuanAkses, RingkasPeran } from './tipePersetujuan'
export type JenisAksi = 'lihat' | 'input' | 'perbaiki' | 'sahkan' | 'verifikasi' | 'setujui' | 'batal' | 'ekspor' | 'cetak' | 'lihat_rupiah' | 'usul'

export type TingkatNode = 'menu' | 'sub_menu' | 'bagian'

export interface IzinNode {
  kode: string
  jenis_aksi: JenisAksi
  sensitif: boolean
  /** Izin sensitif: peran penyetuju (K3); null = belum ditetapkan, tidak bisa diberikan. Sejak 1.2d. */
  penyetuju_peran?: RingkasPeran | null
}

export interface NodeMenu {
  id: string
  kode: string
  label: string
  tingkat: TingkatNode
  ikon?: string | null
  fase?: number | null
  /** Halaman sudah dibangun di versi ini. */
  tersedia: boolean
  /** Modul dinyalakan di instalasi ini. */
  modul_aktif: boolean
  ikut_induk: boolean
  izin: IzinNode[]
  anak: NodeMenu[]
}

export interface GrupPohon {
  kode: string
  label: string | null
  menu: NodeMenu[]
}

export interface PohonMenu {
  grup: GrupPohon[]
}

export interface MetaKursor {
  kursor_berikutnya: string | null
}

export interface Halaman<T> {
  data: T[]
  meta: MetaKursor
}

export interface PeranRingkas {
  id: string
  kode: string
  nama: string
  uraian: string | null
  bawaan_sistem: boolean
  wajib_mfa: boolean
  status: 'aktif' | 'nonaktif'
  jumlah_akun: number
  memuat_izin_sensitif: boolean
}

export type AlasanTerkunci = 'peran_bawaan_terkunci' | 'peran_sendiri'

export interface PeranRinci extends PeranRingkas {
  versi: string
  izin: string[]
  wajib_mfa_terkunci: boolean
  /** Perangkat tepercaya (v0.16.0, tiket 1.13): null = ikut kebijakan instalasi. Opsional sampai 1.13-be. */
  masa_percaya_hari?: number | null
  /** Batas atas dari konfigurasi (superadmin, peran sensitif, atau umum). */
  batas_masa_percaya_hari?: number
  /** Kebijakan instalasi saat ini (baca saja), untuk teks "kosong = ikut instalasi". */
  masa_percaya_instalasi_hari?: number
  dapat_disunting: boolean
  alasan_terkunci: AlasanTerkunci | null
  diubah_pada: string
  /** Izin sensitif yang menunggu persetujuan untuk peran ini; tidak termasuk di `izin` (sejak 1.2d). */
  izin_menunggu?: IzinMenunggu[]
}

export interface PerubahanIzin {
  /** Yang langsung berlaku. */
  ditambah: string[]
  dicabut: string[]
  akun_terdampak: number
  /** Izin sensitif baru yang menunggu persetujuan (sejak 1.2d). */
  menunggu_persetujuan?: PersetujuanAkses[]
}

export interface Pelaku {
  id: string
  nama: string
}

export type CakupanJenis = 'rs' | 'unit' | 'ruang' | 'poli' | 'ksm' | 'depo' | 'pasien_dirawat'

export interface Penugasan {
  id: string
  peran: { id: string; kode: string; nama: string }
  cakupan_jenis: CakupanJenis
  cakupan_id: string | null
  /** Nama dan kode unit untuk cakupan `unit`, null untuk `rs` (kontrak v0.3.0; opsional bila backend lebih lama). */
  cakupan_label?: string | null
  berlaku_dari: string
  berlaku_sampai: string | null
  dasar_pemberian: string
  alasan: string | null
  diberikan_oleh: Pelaku | null
  status: 'aktif' | 'batal'
  berlaku_sekarang: boolean
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
  dibuat_pada: string
}

export interface IzinKhusus {
  id: string
  efek: 'tambah' | 'cabut'
  sasaran_jenis: 'menu' | 'izin'
  sasaran_kode: string
  /** Jalur label, mis. Kasir → Tagihan pasien → Batal. */
  sasaran_label: string
  sensitif: boolean
  berlaku_dari: string
  berlaku_sampai: string | null
  dasar_pemberian: string
  alasan: string
  diberikan_oleh: Pelaku | null
  status: 'aktif' | 'batal'
  berlaku_sekarang: boolean
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
  dibuat_pada: string
}

export interface SumberWajibMfa {
  jenis: 'akun' | 'peran' | 'superadmin' | 'izin_sensitif'
  label: string
  dapat_dimatikan: boolean
}

export interface StatusMfaAkun {
  aktif: boolean
  aktif_pada: string | null
  wajib_akun: boolean
  sumber_wajib: SumberWajibMfa[]
  dapat_direset: boolean
}

export interface AkunRingkas {
  id: string
  nama_pengguna: string
  nama: string
  status: 'aktif' | 'nonaktif'
  terkunci_sampai: string | null
  login_terakhir_pada: string | null
  peran: { id: string; nama: string; berlaku_sampai: string | null }[]
  pengecualian: { tambah: number; cabut: number }
  mfa_aktif: boolean
}

export interface AkunRinci {
  id: string
  nama_pengguna: string
  nama: string
  status: 'aktif' | 'nonaktif'
  terkunci_sampai: string | null
  login_terakhir_pada: string | null
  diri_sendiri: boolean
  /** false = semua perubahan /akun/{id}/* oleh penyunting ini ditolak 403; tombol ubah tidak dirender. */
  dapat_diubah: boolean
  /** Kode 403 yang akan diterima bila tetap mengubah. */
  alasan_terkunci: 'diri_sendiri' | 'akun_superadmin' | null
  mfa: StatusMfaAkun
  penugasan: Penugasan[]
  izin_khusus: IzinKhusus[]
  /** Penugasan, pengakhiran, dan izin khusus akun ini yang menunggu persetujuan (sejak 1.2d). */
  menunggu_persetujuan?: PersetujuanAkses[]
}

export interface FilterAkun {
  cari?: string
  status?: 'aktif' | 'nonaktif' | 'terkunci'
  peran_id?: string
  mfa?: 'aktif' | 'belum'
}

export interface FilterPeran {
  cari?: string
  status?: 'aktif' | 'nonaktif'
}

export interface IsianBuatPeran {
  kode: string
  nama: string
  uraian: string | null
  wajib_mfa: boolean
  salin_dari_id: string | null
  dasar_perubahan: string
}

export interface IsianUbahPeran {
  nama?: string
  uraian?: string | null
  wajib_mfa?: boolean
  status?: 'aktif' | 'nonaktif'
  /** v0.16.0: null = ikut kebijakan instalasi; di atas batas → 422 masa_percaya_melebihi_batas. */
  masa_percaya_hari?: number | null
  dasar_perubahan: string
}

export interface IsianPenugasan {
  peran_id: string
  cakupan_jenis: CakupanJenis
  cakupan_id: string | null
  berlaku_dari: string | null
  berlaku_sampai: string | null
  dasar_pemberian: string
  alasan: string | null
  /** Menindaklanjuti permintaan akses akun ini (sejak 1.2d). */
  permintaan_akses_id?: string | null
}

export interface IsianIzinKhusus {
  efek: 'tambah' | 'cabut'
  sasaran_jenis: 'menu' | 'izin'
  sasaran_kode: string
  berlaku_sampai: string | null
  dasar_pemberian: string
  alasan: string
  /** Menindaklanjuti permintaan akses akun ini (sejak 1.2d). */
  permintaan_akses_id?: string | null
}

// ── Lapis 4: label sensitivitas (kontrak v0.14.0, tiket 1.5) ──

/** Label data induk (PRD 11.2). penyetuju_peran null = label belum bisa diberikan ke akun mana pun. */
export interface LabelSensitivitas {
  id: string
  kode: string
  nama: string
  uraian: string | null
  butuh_relasi_perawatan: boolean
  penyetuju_peran: { id: string; kode: string; nama: string } | null
  aktif: boolean
}

export type StatusIzinLabel = 'menunggu' | 'berlaku' | 'berakhir' | 'batal' | 'ditolak'

/** Izin label sebuah akun: selalu lewat persetujuan pejabat PDP dan berbatas waktu. */
export interface IzinLabelAkun {
  id: string
  label: { kode: string; nama: string }
  /** Saat disetujui; null selama menunggu atau bila ditolak. */
  berlaku_dari: string | null
  berlaku_sampai: string
  status: StatusIzinLabel
  /** status berlaku dan di dalam masa berlaku saat ini. */
  berlaku_sekarang: boolean
  persetujuan: { id: string; nomor: string }
  dasar_pemberian: string
  alasan: string
  diberikan_oleh: Pelaku | null
  disetujui_oleh: Pelaku | null
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
  dibuat_pada: string
}

export interface IsianIzinLabel {
  label_kode: string
  berlaku_sampai: string
  dasar_pemberian: string
  alasan: string
}
