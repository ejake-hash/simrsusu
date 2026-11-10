/** Kontrak peta tempat tidur (docs/api/openapi.yaml v0.35.0, tiket 2.27; skema TempatTidurPeta). Tanpa data pasien. */

export type StatusTempatTidur = 'terisi' | 'kosong_siap' | 'dibersihkan' | 'dipesan' | 'tidak_dipakai' | 'rencana_pulang'
export type AturanJenisKelamin = 'campur' | 'laki_laki' | 'perempuan'

export interface RingkasLokasiPeta {
  id: string
  /** Kode data induk; untuk kamar, lantai, dan gedung = kode segmen lokasi. */
  kode: string
  nama: string
}

export interface TempatTidurPeta {
  id: string
  /** Kode lengkap lokasi TT, tetap sejak dibuat. */
  kode: string
  kamar: RingkasLokasiPeta
  bangsal: RingkasLokasiPeta
  lantai: RingkasLokasiPeta | null
  gedung: RingkasLokasiPeta | null
  kelas_rawat: RingkasLokasiPeta
  aturan_jenis_kelamin: AturanJenisKelamin
  isolasi: boolean
  ekstra: boolean
  /** TT ditutup selalu tidak_dipakai. */
  status: StatusTempatTidur
  status_sejak: string
  ditutup: boolean
  dapat_dipilih: boolean
  /** Hari rawat pasien di TT terisi; null sampai penempatan (2.29). */
  hari_rawat: number | null
}

export interface PetaTempatTidur {
  data: TempatTidurPeta[]
  meta: { terpotong: boolean }
}

export interface FilterPetaServer {
  gedung_id: string | null
  bangsal_id: string | null
}
