import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { RingkasPegawai } from '@/modules/jadwal-praktik/tipe'
import type { Pelaku, RingkasPasienKunjungan } from '@/modules/pendaftaran/tipeKunjungan'

/** Bentuk respons antrean petugas dan display (docs/api/openapi.yaml, tiket 2.8). */

export type StatusAntrean = 'belum_check_in' | 'menunggu' | 'dipanggil' | 'dilayani' | 'dilewati' | 'kembali' | 'selesai' | 'batal'

export interface Antrean {
  id: string
  tanggal: string
  area: string
  nomor_tampil: string
  /** Dua penanda identitas (nama + No. RM/tanggal lahir); null bila belum tertaut ke pasien. */
  pasien: RingkasPasienKunjungan | null
  poliklinik: { id: string; kode: string; nama: string } | null
  dokter: RingkasPegawai
  lokasi_panggil: { id: string; nama: string } | null
  status: StatusAntrean
  panggilan_ke: number
  panggilan_maks: number
  /** Jeda panggilan belum lewat sampai waktu ini; null = tidak ada jeda berjalan. */
  boleh_panggil_pada: string | null
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
}
export type AksiAntrean = 'panggil' | 'lewati' | 'sisip' | 'batal'
export interface FilterAntrean {
  tanggal: string
  poli_id: string
  pegawai_id?: string
  status?: StatusAntrean
}
export type HalamanAntrean = Halaman<Antrean>

export interface PanggilanDisplay {
  nomor_tampil: string
  inisial: string | null
  tujuan: string
  panggilan_ke: number
  dipanggil_pada: string
  teks_suara: string
}
export interface DisplayAntrean {
  area: { kode: string; nama: string }
  dipanggil: PanggilanDisplay[]
  berikutnya: { nomor_tampil: string; inisial: string | null }[]
  interval_detik: number
  waktu_server: string
}
