import type { PrioritasPermintaan, StatusPermintaanItem } from '@/shared/api/permintaanPemeriksaan'

/** Kontrak v0.40.0 (tiket 2.30): daftar kerja lab dan spesimen. v0.41.0 (tiket 2.31): hasil, validasi, adendum. */
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

/** Tahap daftar kerja lab (v0.41.0): `spesimen` bawaan, `hasil` input dan validasi analis, `otorisasi` Sp.PK. */
export type TahapDaftarKerjaLab = 'spesimen' | 'hasil' | 'otorisasi'

/** Penanda dihitung server dari data induk (FR-19-06); null = dalam rujukan atau tanpa rujukan (lihat `peringatan`). */
export type PenandaHasilLab = 'l' | 'h' | 'll' | 'hh' | 'kritis' | null
export type PeringatanHasilLab = 'nilai_rujukan_tidak_ada' | null

/** Bagian yang sama di hasil dan adendum: nilai, satuan, batas yang dipakai saat dihitung, penanda. */
export interface NilaiHasilLab {
  nilai: number | null
  nilai_teks: string | null
  satuan: string | null
  rujukan_min: number | null
  rujukan_maks: number | null
  kritis_min: number | null
  kritis_maks: number | null
  penanda: PenandaHasilLab
  kritis: boolean
  peringatan: PeringatanHasilLab
}

export interface AdendumHasilLab extends NilaiHasilLab {
  id: string
  hasil_id: string
  alasan: string
  dibuat_oleh: Pelaku
  dibuat_pada: string
}

export interface HasilLab extends NilaiHasilLab {
  id: string
  permintaan_item_id: string
  permintaan_pemeriksaan_id: string
  episode_id: string
  kode_item: string
  nama: string
  status: 'menunggu_validasi' | 'menunggu_otorisasi' | 'divalidasi'
  jenis_hasil: 'numerik' | 'teks'
  kode_loinc: string | null
  catatan: string | null
  butuh_otorisasi_spk: boolean
  pasien: { id: string; no_rm: string; nama: string; tanggal_lahir: string | null }
  diinput_oleh: Pelaku
  diinput_pada: string
  divalidasi_oleh: Pelaku
  divalidasi_pada: string | null
  diotorisasi_oleh: Pelaku
  diotorisasi_pada: string | null
  dikeluarkan_pada: string | null
  /** Urut dibuat_pada naik. */
  adendum: AdendumHasilLab[]
  dapat: { input: boolean; validasi: boolean; adendum: boolean }
}

/** Isian input hasil: tepat satu dari `nilai` (numerik) atau `nilai_teks` (teks), sesuai jenis hasil data induk. */
export interface IsianHasilLab {
  nilai?: number
  nilai_teks?: string
  catatan?: string | null
}

export interface ButirDaftarKerjaLab {
  id: string
  kode_item: string
  nama: string
  status: StatusPermintaanItem
  spesimen: Spesimen | null
  /** Opsional (v0.41.0): hasil butir bila sudah diinput, status apa pun. */
  hasil?: HasilLab | null
  /** Opsional di kontrak; tidak ada = tidak boleh. `input`/`validasi` sejak v0.41.0, juga opsional. */
  dapat?: { ambil: boolean; terima: boolean; input?: boolean; validasi?: boolean }
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
