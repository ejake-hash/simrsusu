import type { Pelaku } from '@/modules/akses/tipePengaturan'

/** Tipe SDM → Data pegawai, izin praktik, RKK, akun dari pegawai (docs/api/openapi.yaml v0.6.0+, tiket 1.8d). */

export type StatusPegawai = 'aktif' | 'akan_keluar' | 'keluar'
export type Profesi = 'dokter' | 'dokter_gigi' | 'perawat' | 'bidan' | 'apoteker' | 'tenaga_kesehatan_lain' | 'non_kesehatan'
export type JenisIzinPraktik = 'str' | 'sip' | 'sik' | 'spk'
export type StatusIzinPraktik = 'belum_berlaku' | 'berlaku' | 'akan_habis' | 'habis' | 'batal'
export type StatusKewenangan = 'belum_berlaku' | 'berlaku' | 'habis' | 'batal'

export interface RingkasUnitPegawai {
  id: string
  kode: string
  nama: string
}

export interface PegawaiRingkas {
  id: string
  nip: string | null
  nama: string
  /** `1271 •••• •••• 0042`; null = NIK belum diisi. */
  nik_samaran: string | null
  profesi: Profesi | null
  jabatan: string | null
  unit: RingkasUnitPegawai | null
  status: StatusPegawai
  punya_akun: boolean
  /** Hanya berisi untuk pemegang pengaturan.akun.lihat; selain itu null (lihat punya_akun). */
  akun: { id: string; nama_pengguna: string; status: 'aktif' | 'nonaktif' } | null
  /** Keadaan terburuk di antara izin praktik yang tidak dibatalkan; null = belum ada. */
  izin_praktik_status: Exclude<StatusIzinPraktik, 'batal'> | null
}

export interface PegawaiRinci extends PegawaiRingkas {
  /** Hanya pada GET dengan tampilkan_nik=true dan izin sdm.pegawai.identitas.lihat; tidak pernah disimpan klien. */
  nik?: string
  nik_dapat_dilihat: boolean
  jenis_kepegawaian: string | null
  tmt: string | null
  tanggal_keluar: string | null
  alasan_keluar: string | null
  ihs_practitioner_id: string | null
  versi: string
  diubah_pada: string
}

export interface IsianPegawai {
  nip?: string | null
  nik?: string | null
  nama: string
  jenis_kepegawaian?: string | null
  profesi?: Profesi | null
  unit_organisasi_id?: string | null
  jabatan?: string | null
  tmt?: string | null
  dasar_perubahan: string
}

export type UbahPegawai = Partial<Omit<IsianPegawai, 'dasar_perubahan'>> & {
  tanggal_keluar?: string | null
  alasan_keluar?: string | null
  dasar_perubahan: string
}

export interface FilterPegawai {
  cari?: string
  status?: StatusPegawai
  profesi?: Profesi
  unit_organisasi_id?: string
  punya_akun?: boolean
}

export interface IzinPraktik {
  id: string
  jenis: JenisIzinPraktik
  nomor: string
  terbit: string
  /** null = seumur hidup (STR). */
  berlaku_sampai: string | null
  status: StatusIzinPraktik
  dokumen: { jenis_mime: 'application/pdf' | 'image/jpeg' | 'image/png'; ukuran: number } | null
  dicatat_oleh: Pelaku | null
  dicatat_pada: string
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
}

export interface IsianIzinPraktik {
  jenis: JenisIzinPraktik
  nomor: string
  terbit: string
  berlaku_sampai: string | null
  berkas: File | null
}

export interface KewenanganKlinis {
  id: string
  kategori: string
  kode_tindakan: string[]
  berlaku_dari: string
  berlaku_sampai: string
  komite: string
  dasar: string | null
  status: StatusKewenangan
  dicatat_oleh: Pelaku | null
  dicatat_pada: string
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
}

export interface IsianKewenangan {
  kategori: string
  kode_tindakan: string[]
  berlaku_dari: string
  berlaku_sampai: string
  komite: string
  dasar: string | null
}

export interface AkunDibuat {
  akun: { id: string; nama_pengguna: string; nama: string; status: 'aktif' | 'nonaktif' }
  /** Ditampilkan sekali dengan tombol salin; tidak disimpan di store, cache kueri, atau storage. */
  sandi_awal: string
}
