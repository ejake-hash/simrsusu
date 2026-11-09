import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { RingkasPegawai } from '@/modules/jadwal-praktik/tipe'
import type { JenisPenjamin } from '@/modules/penjamin/tipe'
import type { JenisKelaminPasien } from './tipe'

/**
 * Bentuk respons kunjungan poli (docs/api/openapi.yaml v0.20.0, tiket 2.6) dan IGD (v0.31.0, tiket 2.23). Tanpa impor
 * nilai agar dipakai data tiruan.
 */

export type JenisKunjungan = 'poliklinik' | 'igd' | 'rawat_inap_elektif' | 'penunjang_langsung' | 'mcu' | 'terjadwal_rutin' | 'telekonsultasi' | 'kemoterapi_sehari'
export type CaraBayar = 'jkn' | 'non_jkn'
export type StatusKunjungan = 'terdaftar' | 'dilayani' | 'selesai' | 'batal'

/** Dua penanda identitas untuk papan kunjungan (nama + No. RM/tanggal lahir, 5.1 butir 9); tanpa NIK/alamat. */
export interface RingkasPasienKunjungan {
  id: string
  no_rm: string
  nama: string
  jenis_kelamin: JenisKelaminPasien
  tanggal_lahir: string | null
}
export interface RingkasEpisode {
  id: string
  /** `igd_lebih_6_jam`: kunjungan IGD > 6 jam tanpa rawat inap dipindah ke episode ini (7.00.2, v0.31.0). */
  jenis: 'rawat_jalan_harian' | 'igd_lebih_6_jam'
  mulai_tanggal: string
}
export interface RingkasPenjaminKunjungan {
  id: string
  kode: string
  nama: string
  jenis: JenisPenjamin
}
/** Pelaku; null = konsol/sistem (mis. pembatalan otomatis jam tutup). */
export interface Pelaku {
  id: string
  nama: string
}
export interface Kunjungan {
  id: string
  nomor: string
  pasien: RingkasPasienKunjungan
  episode: RingkasEpisode
  jenis_kunjungan: JenisKunjungan
  /** null untuk kunjungan IGD (tanpa poli, v0.31.0). */
  poliklinik: { id: string; kode: string; nama: string } | null
  /** null untuk kunjungan IGD (dokter belum ditetapkan saat registrasi). */
  dokter: RingkasPegawai | null
  tanggal: string
  /** Jam tiba IGD = jam server saat registrasi; null untuk poliklinik (v0.31.0). */
  tiba_pada?: string | null
  /** IGD masih terdaftar/dilayani dan sudah lebih dari 6 jam sejak tiba; selalu false untuk poliklinik (v0.31.0). */
  lebih_6_jam?: boolean
  /**
   * Triase berlaku (terbaru); null = belum ditriase. Hanya di GET /kunjungan-igd untuk pemegang igd.triase.lihat;
   * tanpa izin itu field tidak ada (v0.32.0).
   */
  triase?: RingkasTriase | null
  cara_bayar: CaraBayar
  penjamin: RingkasPenjaminKunjungan | null
  status: StatusKunjungan
  keluhan: string | null
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
  didaftarkan_oleh: Pelaku | null
  versi: string
  dibuat_pada: string
  diubah_pada: string
}
export interface IsianKunjungan {
  pasien_id: string
  jadwal_praktik_id: string
  tanggal: string
  jenis_kunjungan?: JenisKunjungan
  cara_bayar: CaraBayar
  penjamin_id?: string | null
  keluhan?: string | null
}
/** Isian minimal registrasi cepat IGD (FR-03-01, v0.31.0). Jam tiba tidak diisi: jam server. */
export interface IsianKunjunganIgd {
  pasien_id: string
  cara_bayar: CaraBayar
  penjamin_id?: string | null
  keluhan?: string | null
}
/** Satu slot dokter lain di poli yang sama pada tanggal itu yang masih punya sisa (rincian 409 `kuota_habis`). */
export interface SlotTerdekat {
  jadwal_praktik_id: string
  pegawai: RingkasPegawai
  sisa_jkn: number
  sisa_non_jkn: number
  jam_mulai: string
}
export interface FilterKunjungan {
  tanggal: string
  poli_id: string
  pegawai_id?: string
  status?: StatusKunjungan
}
export type HalamanKunjungan = Halaman<Kunjungan>

/** Empat kategori warna FR-03-02 (v0.32.0, tiket 2.24). */
export type KategoriTriase = 'merah' | 'kuning' | 'hijau' | 'hitam'
export interface RingkasTriase {
  kategori: KategoriTriase
  ditriase_pada: string
}
export interface SkalaTriase {
  kode: string
  label: string
}
/** Instrumen triase dari data induk RS (GET /instrumen-triase). */
export interface InstrumenTriase {
  id: string
  kode: string
  nama: string
  /** [] = instrumen tanpa skala. */
  skala: SkalaTriase[]
}
export interface Triase {
  id: string
  kunjungan_id: string
  kategori: KategoriTriase
  instrumen: { id: string; kode: string; nama: string } | null
  skala: SkalaTriase | null
  keluhan: string | null
  /** Jam tiba dari kunjungan (tidak disalin ke baris triase). */
  tiba_pada: string | null
  ditriase_pada: string
  petugas: Pelaku | null
  /** true hanya untuk triase terbaru kunjungan ini. */
  berlaku: boolean
}
export interface IsianTriase {
  kategori: KategoriTriase
  instrumen_triase_id?: string | null
  skala?: string | null
  keluhan?: string | null
}
export type HalamanTriase = Halaman<Triase>

/** Disposisi IGD FR-03-09 (v0.33.0, tiket 2.26) = kode pos tujuan matriks perpindahan. */
export type TujuanDisposisi = 'pulang' | 'rawat_inap' | 'kamar_operasi' | 'rujuk_keluar' | 'aps' | 'meninggal' | 'doa'
/** Akibat pada kunjungan IGD saat perintah: selesai = kunjungan ditutup; menunggu = kunjungan tetap di IGD. */
export type StatusDisposisi = 'selesai' | 'menunggu_kamar' | 'menunggu_kamar_operasi'
export interface IsianDisposisi {
  tujuan: TujuanDisposisi
  alasan: string
  /** Wajib untuk rawat_inap dan kamar_operasi (bagian data RM-15). */
  serah_terima?: string | null
}
/** Perintah perpindahan (hanya ditambah). Pos asal/tujuan memakai kode matriks perpindahan. */
export interface Disposisi {
  id: string
  kunjungan_id: string
  /** Potret episode sesudah perintah. */
  episode: { id: string; jenis: 'rawat_jalan_harian' | 'igd_lebih_6_jam'; status: 'aktif' | 'selesai' }
  pos_asal: string
  tujuan: string
  aturan_perpindahan_id: string
  alasan: string
  serah_terima: string | null
  status: StatusDisposisi
  diputuskan_oleh: Pelaku | null
  diputuskan_pada: string
}
export type HalamanDisposisi = Halaman<Disposisi>
