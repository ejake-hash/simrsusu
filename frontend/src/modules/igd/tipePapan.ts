import type { Pelaku, RingkasPasienKunjungan, RingkasTriase } from '@/modules/pendaftaran/tipeKunjungan'

/**
 * Bentuk GET /igd/papan dan PUT /kunjungan/{id}/area-igd (docs/api/openapi.yaml v0.34.0, tiket 2.25). Semua angka dan
 * tanda waktu dihitung server. Tanpa impor nilai agar dipakai data tiruan.
 */
export interface RingkasAreaIgd {
  id: string
  kode: string
  nama: string
}

/** Tanpa tahap peringatan (keputusan pemilik 9 Nov 2026): menunggu → lewat_batas; sesudah asesmen: tercapai/terlambat. */
export type TandaTanggap = 'menunggu' | 'lewat_batas' | 'tercapai' | 'terlambat'
export type TandaLamaIgd = 'aman' | 'mendekati_6_jam' | 'lebih_6_jam'
export type StatusDisposisiMenunggu = 'menunggu_kamar' | 'menunggu_kamar_operasi'

export interface TanggapPapanIgd {
  /** Saat dokter mulai mengisi asesmen awal IGD; null = belum. */
  asesmen_dokter_pada: string | null
  /** Menit jam tiba → mulai asesmen, dibulatkan ke atas; null selama menunggu. */
  menit: number | null
  menunggu_asesmen: boolean
  tanda: TandaTanggap
}

export interface BarisPapanIgd {
  kunjungan_id: string
  nomor: string
  status: 'terdaftar' | 'dilayani'
  pasien: RingkasPasienKunjungan
  keluhan: string | null
  /** null = belum ditriase. */
  triase: RingkasTriase | null
  /** null = belum ditempatkan. */
  area: RingkasAreaIgd | null
  tiba_pada: string
  /** Menit sejak tiba, dibulatkan ke atas. */
  lama_di_igd_menit: number
  tanda_lama_igd: TandaLamaIgd
  lebih_6_jam: boolean
  tanggap: TanggapPapanIgd
  disposisi_menunggu: { status: StatusDisposisiMenunggu; sejak: string } | null
  /** Opsional (v0.36.0, FR-03-06): permintaan kamar aktif; null/tidak ada = tidak ada. TT dari sumber yang sama dengan peta TT. */
  permintaan_kamar?: PermintaanKamarPapan | null
}

export interface PermintaanKamarPapan {
  id: string
  status: 'menunggu' | 'dialokasikan'
  /** null = belum dialokasikan. */
  tempat_tidur: { id: string; kode: string; bangsal: { id: string; kode: string; nama: string } } | null
}

export interface MetaPapanIgd {
  /** Area aktif urut tampil (pilihan penempatan). */
  area: RingkasAreaIgd[]
  aturan: { batas_tanggap_menit: number; batas_lama_igd_menit: number; peringatan_lama_igd_menit: number | null }
  /** true bila pasien aktif lebih dari 200 (hanya 200 pertama dikirim). */
  terpotong: boolean
  waktu_server: string
}

export interface PapanIgd {
  data: BarisPapanIgd[]
  meta: MetaPapanIgd
}

export interface PenempatanAreaIgd {
  kunjungan_id: string
  area: RingkasAreaIgd
  ditempatkan_pada: string
  ditempatkan_oleh: Pelaku | null
}
