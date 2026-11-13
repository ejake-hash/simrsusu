import type { PrioritasPermintaan, StatusPermintaanItem } from '@/shared/api/permintaanPemeriksaan'

/** Kontrak v0.40.0 (tiket 2.30): daftar kerja lab dan spesimen. */
type Pelaku = { id: string; nama: string } | null

export type LokasiPengambilanSpesimen = 'instalasi' | 'samping_tt' | 'kamar_operasi'

export interface Spesimen {
  id: string
  permintaan_item_id: string
  permintaan_pemeriksaan_id: string
  item_status: StatusPermintaanItem
  barcode: string
  jenis: string
  lokasi_pengambilan: LokasiPengambilanSpesimen
  diambil_pada: string
  diambil_oleh: Pelaku
  diterima_pada: string | null
  diterima_oleh: Pelaku
  kondisi: 'baik' | null
}

export interface ButirDaftarKerjaLab {
  id: string
  kode_item: string
  nama: string
  status: StatusPermintaanItem
  spesimen: Spesimen | null
  /** Opsional di kontrak; tidak ada = tidak boleh. */
  dapat?: { ambil: boolean; terima: boolean }
}

export interface BarisDaftarKerjaLab {
  id: string
  prioritas: PrioritasPermintaan
  status: 'dipesan' | 'batal'
  dipesan_pada: string
  diagnosis_kerja: string
  pemesan: Pelaku
  pasien: { id: string; no_rm: string; nama: string; tanggal_lahir: string | null }
  asal: { kunjungan_id: string; jenis_kunjungan: 'poliklinik' | 'igd' | 'rawat_inap' | null; nama: string | null }
  lokasi_saat_ini: { jenis: 'rawat_inap' | 'igd' | 'poli'; nama: string | null; tempat_tidur: string | null } | null
  item: ButirDaftarKerjaLab[]
}
