import { klien } from '@/shared/api/klien'

/** Order penunjang bersama (docs/api/openapi.yaml v0.26.0, tiket 2.16; 7.00.5). Dipakai semua pos lewat OrderPenunjang. */
export type JenisPermintaan = 'lab' | 'rad'
export type PrioritasPermintaan = 'rutin' | 'cito'
type Pelaku = { id: string; nama: string } | null

export interface PermintaanPemeriksaan {
  id: string
  kunjungan_id: string
  episode_id: string
  jenis: JenisPermintaan
  prioritas: PrioritasPermintaan
  diagnosis_kerja: string
  catatan: string | null
  alasan_ganda: string | null
  status: 'dipesan' | 'batal'
  pemesan: Pelaku
  dipesan_pada: string
  item: { id: string; tarif_id: string; kode_item: string; nama: string; status: 'dipesan' | 'batal' }[]
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku
  dibatalkan_pada: string | null
  dapat: { batal: boolean }
}
export interface IsianPermintaan {
  jenis: JenisPermintaan
  prioritas: PrioritasPermintaan
  diagnosis_kerja: string
  catatan: string | null
  alasan_ganda: string | null
  item: { tarif_id: string }[]
}
export interface ButirKatalog {
  id: string
  kode: string
  nama: string
}

export async function daftarPermintaanEpisode(episodeId: string): Promise<PermintaanPemeriksaan[]> {
  return (await klien.get<{ data: PermintaanPemeriksaan[] }>(`/episode/${episodeId}/permintaan-pemeriksaan`, { params: { batas: 200 } })).data.data
}
/** 409 `order_ganda`: `rincian` per butir yang sudah dipesan hari ini; kirim ulang dengan `alasan_ganda`. */
export async function buatPermintaan(kunjunganId: string, isian: IsianPermintaan): Promise<PermintaanPemeriksaan> {
  return (await klien.post<{ data: PermintaanPemeriksaan }>(`/kunjungan/${kunjunganId}/permintaan-pemeriksaan`, isian)).data.data
}
export async function batalkanPermintaan(id: string, alasan: string): Promise<PermintaanPemeriksaan> {
  return (await klien.post<{ data: PermintaanPemeriksaan }>(`/permintaan-pemeriksaan/${id}/batal`, { alasan_batal: alasan })).data.data
}
/** Katalog = butir buku tarif aktif ber-kategori jenis itu (izin master_data.tarif.lihat). */
export async function cariKatalog(jenis: JenisPermintaan, cari: string): Promise<ButirKatalog[]> {
  return (await klien.get<{ data: ButirKatalog[] }>('/data-induk/tarif', { params: { jenis, status: 'aktif', cari, batas: 20 } })).data.data
}
