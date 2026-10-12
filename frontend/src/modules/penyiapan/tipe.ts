import type { KesalahanBarisImpor } from '@/modules/organisasi/tipeLokasi'

/** Penyiapan instalasi kosong (kontrak v0.12.0, tiket 1.8f) dan paket referensi (v0.11.0, tiket 1.8e). */
export type KodeLangkah =
  | 'superadmin'
  | 'profil_rs'
  | 'organisasi'
  | 'paket_referensi'
  | 'pegawai'
  | 'penjamin_tarif'
  | 'formularium'
  | 'kredensial_integrasi'
  | 'modul_aktif'
export type StatusLangkah = 'belum' | 'sebagian' | 'selesai' | 'dilewati' | 'menunggu_fase_2'
export type SistemReferensi = 'wilayah' | 'referensi' | 'icd10' | 'icd9cm' | 'loinc' | 'ucum'

export interface SyaratPenyiapan {
  kode: string
  label: string
  terpenuhi: boolean
  keterangan: string | null
}
export interface PaketTermuat {
  sistem: SistemReferensi
  wajib: boolean
  versi: string | null
  sumber: string | null
  dimuat_pada: string | null
  jumlah_kode_aktif: number
}
export interface LangkahPenyiapan {
  no: number
  kode: KodeLangkah
  judul: string
  status: StatusLangkah
  wajib_untuk_melayani: boolean
  syarat: SyaratPenyiapan[]
  dapat_dikonfirmasi: boolean
  dapat_dilewati: boolean
  konfirmasi_terakhir: { oleh: { id: string; nama: string } | null; pada: string; dilewati: boolean; alasan: string | null } | null
  paket: PaketTermuat[] | null
}
export interface StatusPenyiapan {
  status: 'belum' | 'sebagian' | 'selesai'
  siap_melayani: boolean
  siap_melayani_sejak: string | null
  langkah: LangkahPenyiapan[]
}
export interface HasilMuatReferensi {
  sistem: SistemReferensi
  versi: string
  mode: 'pratinjau' | 'terapkan'
  diterapkan: boolean
  jumlah_baris: number
  jumlah_salah: number
  jumlah_baru: number
  jumlah_diubah: number
  jumlah_dinonaktifkan: number
  jumlah_diaktifkan_lagi: number
  kesalahan: KesalahanBarisImpor[]
  kesalahan_terpotong: boolean
}
