import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { StatusAntrean } from '@/modules/antrean/tipe'
import type { RingkasPegawai } from '@/modules/jadwal-praktik/tipe'
import { berversi, type Berversi } from '@/modules/organisasi/api'
import type { Pelaku, RingkasPasienKunjungan } from '@/modules/pendaftaran/tipeKunjungan'
import { klien } from '@/shared/api/klien'

/** E-resep rawat jalan (docs/api/openapi.yaml v0.27.0, tiket 2.17; FR-07-06, FR-07-07, FR-02-10). Dokter (panel Pemeriksaan) dan depo farmasi. */
export type StatusResep = 'draf' | 'sah' | 'batal'
export type JenisPeringatanResep = 'alergi' | 'duplikasi' | 'non_fornas'

export interface RingkasDepo {
  id: string
  kode: string
  nama: string
}
export interface IsiItemResep {
  barang_id: string
  dosis: string | null
  frekuensi_per_hari: number
  jumlah_per_pakai: number
  hari: number
  /** null = dihitung server (frekuensi × jumlah per pakai × hari, dibulatkan ke atas). */
  jumlah: number | null
  rute: string | null
  aturan_pakai: string | null
  catatan: string | null
}
export interface ItemResep extends Omit<IsiItemResep, 'barang_id' | 'jumlah'> {
  id: string
  barang: { id: string; kode: string; nama: string; zat_aktif: string | null; kekuatan: string | null; sediaan: string | null; satuan_terkecil: string }
  jumlah: number
}
export interface PeringatanResep {
  kunci: string
  resep_item_id: string
  jenis: JenisPeringatanResep
  tingkat: 'ringan' | 'sedang' | 'berat' | null
  pesan: string
  tanggapan: { alasan: string; oleh: Pelaku | null; pada: string } | null
}
export interface Resep {
  id: string
  no_resep: string
  kunjungan_id: string
  pasien: RingkasPasienKunjungan
  poliklinik: { nama: string }
  depo: RingkasDepo
  jenis: 'rj' | 'ri' | 'pulang' | 'igd'
  racikan: boolean
  catatan: string | null
  status: StatusResep
  penulis: Pelaku | null
  dokter: RingkasPegawai | null
  dibuat_pada: string
  disahkan_pada: string | null
  item: ItemResep[]
  peringatan: PeringatanResep[]
  antrean: { id: string; nomor_tampil: string; status: StatusAntrean } | null
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
  versi: string
  dapat: { ubah: boolean; sahkan: boolean; batal: boolean }
}
export interface IsianResep {
  depo_id: string
  racikan: boolean
  catatan: string | null
  item: IsiItemResep[]
}
export type TanggapanPeringatan = { kunci: string; alasan: string }

const ifMatch = (r: Resep) => ({ headers: { 'If-Match': `"${r.versi}"` } })

export async function daftarDepo(): Promise<RingkasDepo[]> {
  return (await klien.get<{ data: RingkasDepo[] }>('/depo')).data.data
}
export async function daftarResepKunjungan(kunjunganId: string): Promise<Resep[]> {
  return (await klien.get<Halaman<Resep>>(`/kunjungan/${kunjunganId}/resep`, { params: { batas: 200 } })).data.data
}
export async function buatResep(kunjunganId: string, isian: IsianResep): Promise<Berversi<Resep>> {
  return berversi(await klien.post(`/kunjungan/${kunjunganId}/resep`, isian))
}
export async function ubahResep(r: Resep, isian: Partial<IsianResep>): Promise<Berversi<Resep>> {
  return berversi(await klien.patch(`/resep/${r.id}`, isian, ifMatch(r)))
}
/** 409 `peringatan_belum_ditanggapi` sebelum PIN diperiksa; galat TTE ditafsirkan TtdModal. PIN hanya di body. */
export async function sahkanResep(r: Resep, ttd: { pin: string | null; pernyataan_disetujui: boolean; tanggapan: TanggapanPeringatan[] }): Promise<Berversi<Resep>> {
  return berversi(await klien.post(`/resep/${r.id}/sahkan`, ttd, ifMatch(r)))
}
export async function batalkanResep(id: string, alasan: string): Promise<Resep> {
  return (await klien.post<{ data: Resep }>(`/resep/${id}/batal`, { alasan })).data.data
}
/** Antrean resep satu depo per hari, terlama dulu (urutan antrean farmasi). */
export async function daftarResepDepo(filter: { depo_id: string; tanggal?: string; status?: 'sah' | 'batal' }, kursor: string | null = null, batas = 50): Promise<Halaman<Resep>> {
  return (await klien.get<Halaman<Resep>>('/resep', { params: { ...filter, kursor: kursor ?? undefined, batas } })).data
}

/** Satu baris butir resep untuk dibaca manusia: nama, kekuatan, aturan, jumlah. */
export function ringkasItemResep(i: ItemResep): string {
  const nama = [i.barang.nama, i.barang.kekuatan].filter(Boolean).join(' ')
  const pakai = `${i.frekuensi_per_hari}× sehari ${i.jumlah_per_pakai} ${i.barang.satuan_terkecil}, ${i.hari} hari`
  return `${nama} · ${[i.dosis, pakai, i.rute, i.aturan_pakai].filter(Boolean).join(' · ')} · jumlah ${i.jumlah} ${i.barang.satuan_terkecil}`
}
