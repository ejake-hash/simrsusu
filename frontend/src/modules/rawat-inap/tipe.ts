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
   * v0.38.0: kunjungan rawat inap yang menempati TT; hanya dikirim bagi pemegang rawat_inap.pindah_ruang.input (dan sejak
   * v0.39.0 rawat_inap.pemulangan.lihat atau rawat_inap.rencana_pulang.lihat) di cakupan bangsal TT; tanpa izin kunci tidak ada.
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

/** Pemulangan rawat inap (kontrak v0.39.0, tiket 2.40; skema RencanaPulang, ButirDaftarPeriksaPulang, ResumeMedis). */
export type SumberButirPulang = 'manual' | 'kejadian'
export type StatusButirPulang = 'belum' | 'selesai' | 'menunggu_kasir'

export interface ButirDaftarPeriksaPulang {
  kode: string
  nama: string
  sumber: SumberButirPulang
  wajib: boolean
  /** menunggu_kasir = sumber kejadian (tagihan/jaminan) sampai kasir Tahap D; tidak menahan pulang. */
  status: StatusButirPulang
  selesai_pada: string | null
  selesai_oleh: Pelaku | null
  alasan_tidak_selesai: string | null
}

export interface RencanaPulang {
  kunjungan_id: string
  kunjungan_status: StatusKunjungan
  pasien: RingkasPasienKunjungan
  hari_rawat: number | null
  /** null = belum ada rencana; daftar periksa = pratinjau butir data induk aktif. */
  id: string | null
  status: 'direncanakan' | 'pulang' | 'batal' | null
  rencana_pulang_pada: string | null
  rencana_lama_rawat: number | null
  daftar_periksa: ButirDaftarPeriksaPulang[]
  /** If-Match PUT; null = belum ada rencana. */
  versi: string | null
}

export interface IsianRencanaPulang {
  rencana_pulang_pada: string
  rencana_lama_rawat?: number | null
  daftar_periksa?: { kode: string; selesai: boolean }[]
}

export type TujuanPulang = 'pulang' | 'aps' | 'rujuk_keluar' | 'meninggal'
export interface IsianPulang {
  tujuan: TujuanPulang
  alasan: string
  tanggapan: { kode: string; alasan: string }[]
}

export interface IsianResumeMedis {
  ringkasan_perjalanan: string | null
  kondisi_pulang: string | null
  instruksi_pulang: string | null
  obat_pulang: string | null
  kontrol: string | null
}

export interface CpptResume {
  id: string
  kunjungan_id: string
  profesi: string
  penulis: string
  disahkan_pada: string | null
  subjektif: string | null
  objektif: string | null
  asesmen: string | null
  plan: string | null
}

export interface ResumeMedis {
  id: string
  kunjungan_id: string
  status: 'draf' | 'sah'
  pasien: RingkasPasienKunjungan
  /** DPJP kunjungan, satu-satunya pengesah. */
  dpjp: Pelaku | null
  sumber: {
    cppt: CpptResume[]
    cppt_berlabel: number
    diagnosis: { kunjungan_id: string; sistem: string; kode: string; tampilan: string | null; jenis: 'utama' | 'sekunder' | 'komplikasi' | 'komorbid' }[]
    resep: {
      id: string
      kunjungan_id: string
      no_resep: string
      disahkan_pada: string | null
      butir: { barang: RingkasLokasiPeta; dosis: string | null; frekuensi_per_hari: number; jumlah_per_pakai: number; hari: number; jumlah: number; rute: string | null; aturan_pakai: string | null }[]
    }[]
    alergi: { zat: string; jenis: string; reaksi: string | null; tingkat: string | null }[]
    tindakan: { tersedia: false }
    terpotong: boolean
    dipotret_pada: string
  }
  isian_dpjp: IsianResumeMedis
  disusun_oleh: Pelaku | null
  disusun_pada: string
  disahkan_oleh: Pelaku | null
  disahkan_pada: string | null
  tanda_tangan_id: string | null
  /** If-Match POST /resume-medis/{id}/sahkan. */
  versi: string
}

/** Data induk daftar periksa pulang (v0.39.0, skema DaftarPeriksaPulangDataInduk). */
export interface DaftarPeriksaPulangDataInduk {
  id: string
  kode: string
  nama: string
  sumber: SumberButirPulang
  wajib: boolean
  urutan: number
  status: 'aktif' | 'nonaktif'
  versi: string
  diubah_pada: string
}
export interface UbahDaftarPeriksaPulang {
  nama?: string
  wajib?: boolean
  urutan?: number
  status?: 'aktif' | 'nonaktif'
  dasar_perubahan: string
}
