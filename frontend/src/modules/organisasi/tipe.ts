/**
 * Bentuk respons Profil RS, unit organisasi, KSM (docs/api/openapi.yaml v0.3.0, tiket 1.8a). Tanpa impor nilai agar
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
  dasar_perubahan: string
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
