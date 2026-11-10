import type { Pelaku, RingkasPasienKunjungan } from '@/modules/pendaftaran/tipeKunjungan'

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

/**
 * Antrean permintaan kamar dan SPRI (kontrak v0.36.0, tiket 2.28; skema PermintaanTempatTidur, RingkasTempatTidurDipesan).
 * Lama menunggu dihitung server; alasan klinis SPRI tidak dikirim di antrean.
 */
export type StatusPermintaanKamar = 'menunggu' | 'dialokasikan' | 'ditempatkan' | 'batal'
export type AsalPermintaanKamar = 'igd' | 'poliklinik'

export interface RingkasTempatTidurDipesan {
  id: string
  kode: string
  bangsal: RingkasLokasiPeta
}

export interface PermintaanTempatTidur {
  id: string
  kunjungan: { id: string; nomor: string; jenis_kunjungan: 'poliklinik' | 'igd' }
  pasien: RingkasPasienKunjungan
  asal: AsalPermintaanKamar
  status: StatusPermintaanKamar
  /** null = belum ditentukan (disposisi IGD): alokasi menerima kelas apa pun. */
  kelas_diminta: RingkasLokasiPeta | null
  bangsal_pilihan: RingkasLokasiPeta | null
  spri: { id: string; tanggal_rencana: string; no_surat_bpjs: string | null }
  diminta_oleh: Pelaku | null
  diminta_pada: string
  menunggu_sejak: string
  lama_menunggu_menit: number
  /** null = belum dialokasikan. */
  tempat_tidur: RingkasTempatTidurDipesan | null
  dialokasikan_pada: string | null
}

export interface IsianSpri {
  alasan: string
  kelas_rawat_id: string
  bangsal_id: string | null
  tanggal_rencana: string | null
}

export interface FilterPetaServer {
  gedung_id: string | null
  bangsal_id: string | null
}
