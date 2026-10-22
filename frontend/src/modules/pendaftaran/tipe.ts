import type { Halaman } from '@/modules/akses/tipePengaturan'

/** Tipe layar Pendaftaran → Pendaftaran pasien (tiket 2.5, kontrak v0.19.0). */

export type JenisCariPasien = 'nik' | 'no_rm' | 'no_kartu' | 'nama_tanggal_lahir'
export type JenisKelaminPasien = 'laki_laki' | 'perempuan' | 'tidak_diketahui'
export type Kewarganegaraan = 'wni' | 'wna'

/** Kode + label referensi (agama, pendidikan, pekerjaan, status kawin); null = belum diisi. */
export interface KodeLabelReferensi {
  kode: string
  label: string
}

export interface KontakDaruratPasien {
  nama: string
  hubungan: string
  telepon: string
}

export interface WilayahPasien {
  kode: string
  kelurahan: string
  kecamatan: string
  kabupaten_kota: string
  provinsi: string
}

export interface AlamatPasien {
  jalan: string
  rt: string | null
  rw: string | null
  kode_pos: string | null
  wilayah: WilayahPasien
}

/** Pasien dari server (GET/POST/PATCH). `nik`/`no_paspor` hanya ada dengan izin bagian identitas; `*_samaran` selalu ada. */
export interface Pasien {
  id: string
  no_rm: string
  nama: string
  /** Hanya untuk akun berizin `pendaftaran.pasien.identitas.lihat`; selain itu field tidak ada. */
  nik?: string | null
  nik_samaran: string | null
  no_paspor?: string | null
  no_paspor_samaran: string | null
  no_kartu_bpjs: string | null
  tanggal_lahir: string | null
  tempat_lahir: string | null
  jenis_kelamin: JenisKelaminPasien
  agama: KodeLabelReferensi | null
  pendidikan: KodeLabelReferensi | null
  pekerjaan: KodeLabelReferensi | null
  status_kawin: KodeLabelReferensi | null
  kewarganegaraan: Kewarganegaraan
  bahasa: string | null
  kebutuhan_khusus: string | null
  alamat: AlamatPasien | null
  telepon: string | null
  kontak_darurat: KontakDaruratPasien[]
  tanpa_identitas: boolean
  ciri_pengenal: string | null
  meninggal_pada: string | null
  jumlah_kunjungan: number
  sejak_tahun: number
  versi: string
  dibuat_pada: string
  diubah_pada: string
}

export interface HalamanPasien extends Halaman<Pasien> {
  meta: { kursor_berikutnya: string | null; jenis_cari: JenisCariPasien }
}

/** Alamat yang dikirim ke server (POST/PATCH). */
export interface IsianAlamatPasien {
  jalan: string
  rt?: string | null
  rw?: string | null
  kode_pos?: string | null
  wilayah_kode: string
}

/** Isian yang dikirim POST/PATCH. Semua opsional di PATCH; POST mewajibkan sesuai aturan server. */
export interface IsianPasien {
  nama?: string | null
  nik?: string | null
  no_paspor?: string | null
  no_kartu_bpjs?: string | null
  tanggal_lahir?: string | null
  tempat_lahir?: string | null
  jenis_kelamin: JenisKelaminPasien
  agama?: string | null
  pendidikan?: string | null
  pekerjaan?: string | null
  status_kawin?: string | null
  kewarganegaraan?: Kewarganegaraan
  bahasa?: string | null
  kebutuhan_khusus?: string | null
  alamat?: IsianAlamatPasien | null
  telepon?: string | null
  kontak_darurat?: KontakDaruratPasien[]
  tanpa_identitas?: boolean
  ciri_pengenal?: string | null
}

/** PATCH menambah `alasan_perubahan` wajib dan `tanpa_identitas: false` untuk melengkapi Mr. X. */
export interface UbahPasien extends Partial<IsianPasien> {
  alasan_perubahan: string
}
