/**
 * Bentuk respons Profil RS, unit organisasi, KSM (docs/api/openapi.yaml v0.3.0, tiket 1.8a; halaman masuk v0.15.0, tiket 1.15). Tanpa impor nilai agar
 * data tiruan bisa dipakai e2e (tsconfig.node).
 */
export interface WarnaIdentitas {
  brand: string
  accent: string
}

export interface ProfilRsApi {
  versi: string
  nama: string | null
  paket_identitas: { warna: WarnaIdentitas; logo_url: string | null; motif_url: string | null; foto_url: string | null }
  kontak_admin: { unit: string; kontak: string | null }
  instalasi_demo: boolean
  /**
   * Nilai efektif panel merek halaman masuk (kontrak v0.15.0, tiket 1.15); belum diatur = teks bawaan, `bawaan` true.
   * Sementara opsional: backend mengirimnya sejak 1.15-be (lalu wajib lagi); tidak ada = teks bawaan, tab Halaman masuk
   * tidak tampil.
   */
  halaman_masuk?: HalamanMasukApi
  /** Hanya untuk pemegang pengaturan.profil_rs.lihat. */
  rinci?: {
    kode_kemenkes: string | null
    /** Klasifikasi sesuai izin operasional (teks bebas, mis. B). */
    kelas: string | null
    status_blu: boolean | null
    kode_ppk_bpjs: string | null
    alamat: string | null
    status_penyiapan: 'belum' | 'sebagian' | 'selesai'
    diubah_pada: string | null
  }
}

export interface IsianProfilRs {
  nama: string
  kode_kemenkes: string | null
  kelas: string | null
  status_blu: boolean | null
  kode_ppk_bpjs: string | null
  alamat: string | null
  warna: WarnaIdentitas
  kontak_admin: { unit: string; kontak: string | null }
  /** Tidak dikirim = tetap; null = kembali ke teks bawaan; objek = ganti utuh (v0.15.0). */
  halaman_masuk?: HalamanMasuk | null
  dasar_perubahan: string
}

/** Isi panel merek halaman masuk (kontrak v0.15.0 IsianHalamanMasuk, tiket 1.15). Teks biasa, bukan HTML. */
export interface HalamanMasuk {
  lencana: string
  judul: string
  /** Ditempel di akhir judul (dipisah satu spasi), warna --penekanan-panel. */
  judul_sorot: string | null
  uraian: string
  /** Tepat tiga butir. */
  fitur: readonly { judul: string; uraian: string }[]
  /** null = nama RS. */
  foto_keterangan: string | null
  tampilkan_motif: boolean
}
export interface HalamanMasukApi extends HalamanMasuk {
  /** true = belum pernah diatur. */
  bawaan: boolean
}

/** RingkasPegawai (kontrak v0.6.0): kepala unit dan ketua KSM. */
export interface RingkasPegawai {
  id: string
  nama: string
  status: 'aktif' | 'akan_keluar' | 'keluar'
}

export type JenisBerkas = 'logo' | 'motif' | 'foto'
export type JenisUnit = 'instalasi' | 'bidang' | 'bagian' | 'ruang' | 'poli' | 'depo'

export interface UnitOrganisasi {
  id: string
  induk_id: string | null
  kode: string
  nama: string
  jenis: JenisUnit
  status: 'aktif' | 'nonaktif'
  berlaku_dari: string
  /** Kepala unit (pegawai, sejak 1.8d); `status` menandai yang sudah keluar. */
  kepala: RingkasPegawai | null
  jumlah_anak: number
  jalur: { id: string; kode: string; nama: string }[]
  versi: string
  diubah_pada: string
}

export interface IsianUnitOrganisasi {
  induk_id: string | null
  kode: string
  nama: string
  jenis: JenisUnit
  berlaku_dari: string | null
  dasar_perubahan: string
}

export interface UbahUnitOrganisasi {
  induk_id?: string | null
  kode?: string
  nama?: string
  jenis?: JenisUnit
  status?: 'aktif' | 'nonaktif'
  berlaku_dari?: string
  kepala_pegawai_id?: string | null
  dasar_perubahan: string
}

export interface Ksm {
  id: string
  kode: string
  nama: string
  status: 'aktif' | 'nonaktif'
  /** Ketua KSM (pegawai, sejak 1.8d). */
  ketua: RingkasPegawai | null
  versi: string
  diubah_pada: string
}

export interface FilterUnit {
  induk_id?: string
  akar?: boolean
  cari?: string
  jenis?: JenisUnit
  status?: 'aktif' | 'nonaktif'
  milik_saya?: boolean
}
