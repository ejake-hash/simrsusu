/**
 * Bentuk respons lokasi fisik, bangsal, kamar, tempat tidur, kelas rawat, poliklinik, dan kapasitas
 * (docs/api/openapi.yaml v0.5.0, tiket 1.8b). Tanpa impor nilai agar data tiruan bisa dipakai e2e (tsconfig.node).
 */
export type JenisLokasi = 'situs' | 'gedung' | 'sayap' | 'lantai' | 'ruang' | 'tempat_tidur'
export type StatusLokasi = 'aktif' | 'renovasi' | 'ditutup'
export type FungsiRuang = 'rawat_inap' | 'rawat_jalan' | 'igd' | 'kamar_operasi' | 'intensif' | 'penunjang' | 'administrasi' | 'gudang' | 'lainnya'
export type StatusOperasionalTt = 'belum_berlaku' | 'aktif' | 'akan_ditutup' | 'ditutup'
export type AturanJenisKelamin = 'campur' | 'laki_laki' | 'perempuan'

export interface RingkasUnit {
  id: string
  kode: string
  nama: string
}
export interface RingkasLokasi {
  id: string
  kode_lengkap: string
  nama: string
}
export interface RingkasBangsal {
  id: string
  kode: string
  nama: string
}
export interface RingkasKamar {
  id: string
  nomor: string
  kode_lengkap: string
  nama: string
}
export interface RingkasKelasRawat {
  id: string
  kode: string
  nama: string
}

export interface Lokasi {
  id: string
  induk_id: string | null
  jenis: JenisLokasi
  kode: string
  kode_lengkap: string
  nama: string
  fungsi: FungsiRuang | null
  unit: RingkasUnit | null
  status: StatusLokasi
  status_berlaku_dari: string
  satusehat_lokasi_id: string | null
  /** Ruang yang menjadi kamar rawat. */
  kamar_id: string | null
  /** Untuk jenis tempat_tidur. */
  tempat_tidur_id: string | null
  jumlah_anak: number
  jalur: RingkasLokasi[]
  versi: string
  diubah_pada: string
}

export interface IsianLokasi {
  induk_id: string | null
  jenis: Exclude<JenisLokasi, 'tempat_tidur'>
  kode: string
  nama: string
  fungsi: FungsiRuang | null
  unit_organisasi_id: string | null
  dasar_perubahan: string
}
export interface UbahLokasi {
  induk_id?: string | null
  kode?: string
  nama?: string
  fungsi?: FungsiRuang | null
  unit_organisasi_id?: string | null
  status?: StatusLokasi
  status_berlaku_dari?: string | null
  dasar_perubahan: string
}

export interface KesalahanBarisImpor {
  baris: number
  kolom: string | null
  kode: string
  pesan: string
}
export interface HasilImporLokasi {
  mode: 'pratinjau' | 'terapkan'
  diterapkan: boolean
  jumlah_baris: number
  jumlah_salah: number
  per_jenis: Record<JenisLokasi | 'kamar', number>
  kesalahan: KesalahanBarisImpor[]
  kesalahan_terpotong: boolean
}

/**
 * TT per kelas, dilihat hari ini (kontrak v0.5.0 sesudah #46). Kelompok saling lepas: (jumlah_tersedia + jumlah_ekstra),
 * belum_berlaku, ditutup (kumulatif). `akan_ditutup` ⊂ yang dihitung hari ini.
 */
export interface RingkasanTtKelas {
  kelas_rawat: RingkasKelasRawat
  jumlah_tersedia: number
  jumlah_ekstra: number
  akan_ditutup: number
  belum_berlaku: number
  ditutup: number
}
export interface Bangsal {
  id: string
  kode: string
  nama: string
  unit: RingkasUnit
  status: 'aktif' | 'nonaktif'
  jumlah_kamar: number
  ringkasan_tt: RingkasanTtKelas[]
  versi: string
  diubah_pada: string
}

export type AtributKamar = Record<string, boolean | number | string>
export interface Kamar {
  id: string
  /** Kode ruang (segmen terakhir kode_lengkap). */
  nomor: string
  bangsal: RingkasBangsal
  lokasi: RingkasLokasi
  kapasitas_maksimum: number | null
  atribut_standar: AtributKamar
  jumlah_tempat_tidur_aktif: number
  melebihi_kapasitas: boolean
  versi: string
  diubah_pada: string
}

export interface TempatTidur {
  id: string
  /** Tidak pernah berubah atau dipakai ulang. */
  kode: string
  kamar: RingkasKamar
  bangsal: RingkasBangsal
  lokasi_id: string
  kelas_rawat: RingkasKelasRawat
  aturan_jenis_kelamin: AturanJenisKelamin
  isolasi: boolean
  ekstra: boolean
  status_operasional: StatusOperasionalTt
  berlaku_dari: string
  /** Hari terakhir TT dihitung; null = tanpa batas. */
  berlaku_sampai: string | null
  alasan_tutup: string | null
  satusehat_lokasi_id: string | null
  versi: string
  diubah_pada: string
}
export interface IsianTempatTidur {
  kamar_id: string
  kode: string
  kelas_rawat_id: string
  aturan_jenis_kelamin?: AturanJenisKelamin
  isolasi?: boolean
  ekstra?: boolean
  berlaku_dari?: string | null
  dasar_perubahan: string
}

export interface KelasRawat {
  id: string
  kode: string
  nama: string
  kode_aplicares: string | null
  berlaku_dari: string
  status: 'aktif' | 'nonaktif'
  versi: string
  diubah_pada: string
}

export interface Poliklinik {
  id: string
  kode: string
  nama: string
  unit: RingkasUnit
  lokasi: RingkasLokasi | null
  kode_poli_bpjs: string | null
  status: 'aktif' | 'nonaktif'
  versi: string
  diubah_pada: string
}

export interface KapasitasBangsal {
  tanggal: string
  sumber: 'tersimpan' | 'dihitung'
  bangsal: RingkasBangsal
  kelas: { kelas_rawat: RingkasKelasRawat; jumlah_tersedia: number; jumlah_ekstra: number }[]
}

export interface FilterLokasi {
  induk_id?: string
  akar?: boolean
  cari?: string
  jenis?: JenisLokasi
  status?: StatusLokasi
}
export interface FilterTempatTidur {
  kamar_id?: string
  bangsal_id?: string
  lokasi_id?: string
  kelas_rawat_id?: string
  status_operasional?: StatusOperasionalTt
  cari?: string
}
