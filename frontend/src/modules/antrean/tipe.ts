import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { RingkasPegawai } from '@/modules/jadwal-praktik/tipe'
import type { Pelaku, RingkasPasienKunjungan } from '@/modules/pendaftaran/tipeKunjungan'

/** Bentuk respons antrean petugas dan display (docs/api/openapi.yaml, tiket 2.8). */

export type StatusAntrean = 'belum_check_in' | 'menunggu' | 'dipanggil' | 'dilayani' | 'dilewati' | 'kembali' | 'selesai' | 'batal'
/** Titik yang punya layar petugas di tahap ini: poli (2.8) dan loket admisi (2.20). */
export type TitikLayar = 'poli' | 'loket'
export type AlasanPrioritas = 'lansia' | 'disabilitas' | 'ibu_hamil' | 'anak_khusus'

export interface Antrean {
  id: string
  tanggal: string
  titik: string
  area: string
  nomor_tampil: string
  /** Dua penanda identitas (nama + No. RM/tanggal lahir); null bila belum tertaut ke pasien (mis. nomor loket). */
  pasien: RingkasPasienKunjungan | null
  poliklinik: { id: string; kode: string; nama: string } | null
  dokter: RingkasPegawai | null
  lokasi_panggil: { id: string; nama: string } | null
  sumber: 'loket' | 'mobile_jkn' | 'apm' | 'web'
  prioritas: boolean
  /** Hanya titik loket: kategori pilihan pasien di anjungan, untuk dinilai petugas loket. */
  alasan_prioritas?: AlasanPrioritas | null
  status: StatusAntrean
  panggilan_ke: number
  panggilan_maks: number
  /** Jeda panggilan belum lewat sampai waktu ini; null = tidak ada jeda berjalan. */
  boleh_panggil_pada: string | null
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
}
export type AksiAntrean = 'panggil' | 'lewati' | 'sisip' | 'batal'
/** `titik=loket`: tanpa `poli_id`/`pegawai_id` (server menolak 422 bila dikirim). */
export interface FilterAntrean {
  tanggal: string
  titik?: TitikLayar
  poli_id?: string
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
/** Anjungan tahap 1 (tiket 2.20, kontrak v0.30.0). Tanpa data pasien. */
export type LayananAnjungan = 'nomor_loket' | 'jadwal_praktik'
export interface AnjunganPerangkat {
  kode: string
  lokasi: string
  mode: 'pendaftaran' | 'farmasi'
  batas_diam_detik: number
  layanan: LayananAnjungan[]
}
export interface TiketLoket {
  nomor_tampil: string
  prioritas: boolean
  tanggal: string
  diambil_pada: string
  menunggu_di_depan: number
  lokasi: string
}
export interface JadwalAnjungan {
  poliklinik: { id: string; nama: string }
  dokter: string
  jam_mulai: string
  jam_selesai: string
  status: 'tersedia' | 'penuh' | 'tidak_praktik'
}

export interface DisplayAntrean {
  area: { kode: string; nama: string }
  dipanggil: PanggilanDisplay[]
  berikutnya: { nomor_tampil: string; inisial: string | null }[]
  interval_detik: number
  waktu_server: string
}
