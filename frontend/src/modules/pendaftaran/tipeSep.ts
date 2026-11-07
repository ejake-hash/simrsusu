import type { JenisKelaminPasien } from './tipe'

/** Bentuk respons kepesertaan, rujukan, dan SEP (docs/api/openapi.yaml v0.21.0, tiket 2.7). */

export type AsalRujukan = 'fktp' | 'rs'
export type StatusMasaRujukan = 'berlaku' | 'hampir_habis' | 'kedaluwarsa'
export type StatusSep = 'menunggu' | 'terbit' | 'ditolak' | 'batal'
export interface KodeNamaBpjs {
  kode: string
  nama: string
}
export interface PesertaBpjs {
  no_kartu: string
  nama: string
  nik?: string | null
  nik_samaran: string | null
  tanggal_lahir: string | null
  jenis_kelamin: JenisKelaminPasien
  status: { aktif: boolean; kode: string; keterangan: string }
  jenis_peserta: KodeNamaBpjs | null
  kelas_hak: KodeNamaBpjs | null
  faskes_tingkat_pertama: KodeNamaBpjs | null
  prolanis_prb: string | null
  pasien?: { id: string; no_rm: string } | null
  lingkungan: string | null
  dibaca_pada: string
}
export interface RujukanBpjs {
  no_rujukan: string
  asal: AsalRujukan
  tanggal_rujukan: string
  berlaku_sampai: string
  sisa_hari: number
  status_masa_berlaku: StatusMasaRujukan
  faskes_asal: KodeNamaBpjs
  poli_tujuan: { kode_poli_bpjs: string; nama: string; poliklinik: { id: string; kode: string; nama: string } | null }
  diagnosis: KodeNamaBpjs
  peserta: { no_kartu: string; nama: string }
  lingkungan: string | null
  dibaca_pada: string
}
export interface IsianSep {
  no_rujukan?: string | null
  asal_rujukan?: AsalRujukan | null
  diagnosis_awal?: string | null
  catatan?: string | null
  tunda?: boolean
}
export interface Sep {
  id: string
  kunjungan_id: string
  no_sep: string | null
  tanggal_sep: string
  status: StatusSep
  rujukan: { no_rujukan: string; berlaku_sampai: string | null; status_masa_berlaku: StatusMasaRujukan | null } | null
  peringatan: { kode: string; pesan: string }[]
  galat: { sumber: 'bpjs' | 'simrs'; kode: string; pesan: string } | null
  terbit_pada: string | null
}
