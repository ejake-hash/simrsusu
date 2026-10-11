/**
 * Bentuk respons persetujuan dan permintaan akses (docs/api/openapi.yaml v0.4.0, tiket 1.2d). Tanpa impor nilai agar
 * data tiruan bisa dipakai e2e (tsconfig.node).
 */
import type { Pelaku } from './tipePengaturan'

export interface RingkasPeran {
  id: string
  kode: string
  nama: string
}

export type StatusPersetujuan = 'menunggu' | 'disetujui' | 'ditolak' | 'kedaluwarsa' | 'dibatalkan'
export type JenisPersetujuan = 'izin_sensitif' | 'peran_superadmin' | 'pengecualian'
export type JenisObjekPersetujuan = 'izin_peran' | 'penugasan' | 'pengakhiran_penugasan' | 'izin_khusus' | 'penyetuju_izin'

export interface PersetujuanAkses {
  id: string
  /** Nomor yang dipakai sebagai dasar pemberian, mis. PSA-2026-000012. */
  nomor: string
  jenis: JenisPersetujuan
  ringkasan: string
  objek: {
    jenis: JenisObjekPersetujuan
    penugasan_id?: string | null
    peran?: RingkasPeran | null
    akun?: { id: string; nama_pengguna: string; nama: string } | null
    izin?: { kode: string; label: string } | null
    cakupan_label?: string | null
    berlaku_sampai?: string | null
  }
  penyetuju_peran: RingkasPeran | null
  status: StatusPersetujuan
  /** true = perubahan sudah berlaku (semua persetujuan dalam grup disetujui). */
  diterapkan: boolean
  diajukan_oleh: Pelaku | null
  diajukan_pada: string
  alasan: string | null
  dasar: string
  kedaluwarsa_pada: string
  /** null = belum diputuskan, atau diputuskan sistem (kedaluwarsa, tidak bisa diterapkan). */
  diputuskan_oleh: Pelaku | null
  diputuskan_pada: string | null
  catatan_keputusan: string | null
  grup: { id: string; jumlah: number; disetujui: number }
  akun_terdampak: number
  /** Akun yang sedang masuk boleh menyetujui/menolak sekarang (MD-4). */
  dapat_diputuskan: boolean
}

export type TabPersetujuan = 'perlu_saya_putuskan' | 'saya_ajukan'

export type StatusPermintaan = 'menunggu' | 'ditindaklanjuti' | 'ditolak'

export interface PermintaanAkses {
  id: string
  /** Nomor yang dipakai sebagai dasar pemberian saat ditindaklanjuti, mis. PMA-2026-000034. */
  nomor: string
  akun: { id: string; nama_pengguna: string; nama: string }
  sasaran_jenis: 'menu' | 'izin'
  sasaran_kode: string
  sasaran_label: string
  alasan: string
  status: StatusPermintaan
  diputuskan_oleh: Pelaku | null
  diputuskan_pada: string | null
  catatan_keputusan: string | null
  dibuat_pada: string
}

export interface IzinMenunggu {
  kode: string
  persetujuan_id: string
  penyetuju_peran: RingkasPeran | null
  kedaluwarsa_pada: string
}

export interface SaranPeran {
  efek: 'tambah' | 'cabut'
  sasaran_jenis: 'menu' | 'izin'
  sasaran_kode: string
  sasaran_label: string
  jumlah_akun: number
}

/** Hasil endpoint pemberian sejak 1.2d: 201 langsung berlaku, atau 202 menunggu persetujuan. */
export type HasilPemberian<T> = { menunggu: false; data: T } | { menunggu: true; persetujuan: PersetujuanAkses }
