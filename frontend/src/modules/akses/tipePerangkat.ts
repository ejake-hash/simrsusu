import type { Pelaku } from './tipePengaturan'

/** Perangkat tepercaya dan kebijakan keamanan (docs/api/openapi.yaml v0.16.0, tiket 1.13). */
export type AlasanCabut = 'dicabut_pemilik' | 'dicabut_admin' | 'sandi_diganti' | 'sandi_direset' | 'mfa_direset' | 'akun_nonaktif'

export interface PerangkatTepercaya {
  id: string
  /** Dari user-agent saat dipercaya, mis. "Chrome di Windows". */
  nama_perangkat: string
  status: 'aktif' | 'kedaluwarsa' | 'dicabut'
  dibuat_pada: string
  /** Terakhir MFA dilewati karena perangkat ini. */
  terakhir_dipakai_pada: string | null
  /** Efektif: sudah memperhitungkan kebijakan yang berlaku sekarang. */
  berlaku_sampai: string
  /** Perangkat yang terikat ke sesi ini (hanya di /saya/perangkat). */
  perangkat_ini: boolean
  dicabut_pada: string | null
  alasan_cabut: AlasanCabut | null
  /** Teks alasan admin (dicabut_admin). */
  keterangan_cabut: string | null
  dicabut_oleh: Pelaku | null
}

export interface KebijakanKeamanan {
  versi: string
  /** Bawaan instalasi 30; 0 = perangkat tepercaya mati. */
  perangkat_tepercaya_hari: number
  /** Dari konfigurasi server, tidak bisa diubah dari layar. */
  batas: { maks_hari: number; maks_hari_superadmin: number; maks_hari_peran_sensitif: number }
  diubah_pada: string | null
}
