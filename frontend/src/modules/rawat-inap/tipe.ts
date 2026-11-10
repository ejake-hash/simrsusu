import type { Pelaku, RingkasPasienKunjungan, StatusKunjungan } from '@/modules/pendaftaran/tipeKunjungan'

/** Kontrak peta tempat tidur (docs/api/openapi.yaml v0.35.0, tiket 2.27; skema TempatTidurPeta). Tanpa data pasien. */

/** `melewati_rencana_lama_rawat` sejak v0.37.0 (FR-08-01; disetel bersama rencana pulang, 2.40). */
export type StatusTempatTidur = 'terisi' | 'kosong_siap' | 'dibersihkan' | 'dipesan' | 'tidak_dipakai' | 'rencana_pulang' | 'melewati_rencana_lama_rawat'
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
  /** Hari rawat pasien yang menempati TT (v0.37.0, dari penempatan pertama; pindah ruang tidak mengulang); null = kosong. */
  hari_rawat: number | null
  /**
   * v0.38.0: kunjungan rawat inap yang menempati TT; hanya dikirim bagi pemegang rawat_inap.pindah_ruang.input di cakupan
   * bangsal TT (tanpa izin kunci tidak ada).
   */
  kunjungan_rawat_inap_id?: string
  /** v0.38.0 (keputusan pemilik 11 Nov 2026): dua penanda identitas, syarat sama dengan id kunjungan. Hanya untuk dialog. */
  pasien_rawat_inap?: { nama: string; no_rm: string; tanggal_lahir: string | null }
}

export interface IsianPindahRuang {
  tempat_tidur_id: string
  alasan: string
  serah_terima: string
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

/** Kunjungan rawat inap sesudah penempatan (kontrak v0.37.0, tiket 2.29; skema RawatInap). Serah terima tidak dikirim. */
export interface RawatInap {
  kunjungan: { id: string; nomor: string; status: StatusKunjungan }
  pasien: RingkasPasienKunjungan
  episode: { id: string; jenis: string; mulai_tanggal: string }
  permintaan_tempat_tidur_id: string
  asal: AsalPermintaanKamar
  kelas_dirawat: RingkasLokasiPeta
  mulai_pada: string
  /** null = tidak menempati TT (pulang, 2.40). */
  penempatan: { id: string; tempat_tidur: RingkasTempatTidurDipesan; mulai_pada: string; ditempatkan_oleh: Pelaku | null } | null
  hari_rawat: number | null
}

export interface FilterPetaServer {
  gedung_id: string | null
  bangsal_id: string | null
}
