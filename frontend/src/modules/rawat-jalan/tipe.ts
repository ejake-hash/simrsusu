import type { RingkasPegawai } from '@/modules/jadwal-praktik/tipe'
import type { Pelaku, RingkasPasienKunjungan } from '@/modules/pendaftaran/tipeKunjungan'
import type { StatusAntrean } from '@/modules/antrean/tipe'

/** Bentuk respons worklist poli, asesmen awal perawat, dan alergi (docs/api/openapi.yaml v0.23.0, tiket 2.13). */

export type TahapWorklist = 'perawat' | 'dokter' | 'selesai'
export type StatusAsesmen = 'draf' | 'selesai'
export type JenisAlergi = 'obat' | 'makanan' | 'lingkungan' | 'lainnya'
export type TingkatAlergi = 'ringan' | 'sedang' | 'berat'
export type RisikoJatuh = 'rendah' | 'sedang' | 'tinggi'

export interface BarisWorklist {
  antrean_id: string
  kunjungan_id: string
  nomor_tampil: string
  /** Dua penanda identitas: nama + No. RM/tanggal lahir. Bukan data berlabel, jadi tidak pernah `Terbatas`. */
  pasien: RingkasPasienKunjungan
  dokter: RingkasPegawai
  status_antrean: StatusAntrean
  tahap: TahapWorklist
  /** null = asesmen belum dimulai. */
  asesmen_awal: { id: string; status: StatusAsesmen } | null
  ada_alergi: boolean
  terdaftar_pada: string
  menunggu_sejak: string
}
export interface FilterWorklist {
  tanggal: string
  poli_id: string
  tahap?: TahapWorklist
}

/** Angka mentah tanpa penanda ambang (ambang klinis menunggu data induk RS). */
export interface TandaVital {
  sistolik?: number | null
  diastolik?: number | null
  nadi?: number | null
  napas?: number | null
  suhu?: number | null
  spo2?: number | null
  berat_badan?: number | null
  tinggi_badan?: number | null
}
export interface Skrining {
  skala_nyeri: number | null
  lokasi_nyeri: string | null
  risiko_jatuh: RisikoJatuh | null
  gizi_berisiko: boolean | null
  catatan: string | null
}
export interface IsianAsesmenAwal {
  keluhan_utama?: string | null
  riwayat_penyakit_sekarang?: string | null
  riwayat_penyakit_dahulu?: string | null
  tanda_vital?: TandaVital
  skrining?: Partial<Skrining>
  catatan?: string | null
}
export interface AsesmenAwal {
  id: string
  kunjungan_id: string
  episode_id: string
  status: StatusAsesmen
  keluhan_utama: string | null
  riwayat_penyakit_sekarang: string | null
  riwayat_penyakit_dahulu: string | null
  tanda_vital: TandaVital
  /** Dihitung server dari berat dan tinggi badan. */
  imt: number | null
  skrining: Skrining
  catatan: string | null
  penulis: Pelaku
  dibuat_pada: string
  selesai_pada: string | null
  /** selesai_pada + 2 × 24 jam. */
  batas_perbaikan_pada: string | null
  jumlah_versi: number
  versi: string
  diubah_pada: string
}
/** Asesmen berlabel sensitif bagi akun yang bukan penulisnya dan tidak lolos lapis 4. */
export interface Terbatas {
  terbatas: true
}
export const adalahTerbatas = (a: AsesmenAwal | Terbatas): a is Terbatas => 'terbatas' in a

export interface Alergi {
  id: string
  pasien_id: string
  zat: string
  jenis: JenisAlergi
  barang: { id: string; kode: string; nama: string } | null
  reaksi: string | null
  tingkat: TingkatAlergi | null
  status: 'aktif' | 'batal'
  dicatat_oleh: Pelaku
  dicatat_pada: string
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
}
export interface IsianAlergi {
  zat: string
  jenis: JenisAlergi
  reaksi?: string | null
  tingkat?: TingkatAlergi | null
}
