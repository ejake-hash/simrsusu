import type { AxiosResponse } from 'axios'
import { klien } from '@/shared/api/klien'
import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import type { FilterJadwal, IsianJadwal, IsianPengecualian, JadwalPengecualian, JadwalPraktik, UbahJadwal } from './tipe'

/** Klien jadwal praktik dan pengecualian (kontrak v0.17.0, tiket 2.4). */
function berversi<T extends { versi: string }>(r: AxiosResponse<{ data: T }>): Berversi<T> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}
const ifMatch = (etag: string) => ({ headers: { 'If-Match': etag } })

export async function daftarJadwal(filter: FilterJadwal, kursor: string | null = null, batas = 50): Promise<Halaman<JadwalPraktik>> {
  const params = { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas }
  return (await klien.get<Halaman<JadwalPraktik>>('/jadwal-praktik', { params })).data
}
export const buatJadwal = async (isian: IsianJadwal) => berversi<JadwalPraktik>(await klien.post('/jadwal-praktik', isian))
export const ubahJadwal = async (id: string, etag: string, isian: UbahJadwal) => berversi<JadwalPraktik>(await klien.patch(`/jadwal-praktik/${id}`, isian, ifMatch(etag)))
export const buatPengecualian = async (isian: IsianPengecualian) => berversi<JadwalPengecualian>(await klien.post('/jadwal-pengecualian', isian))
export const ubahPengecualian = async (id: string, etag: string, isian: { dari_tanggal?: string; sampai_tanggal?: string; alasan?: string; status?: 'batal'; alasan_batal?: string }) =>
  berversi<JadwalPengecualian>(await klien.patch(`/jadwal-pengecualian/${id}`, isian, ifMatch(etag)))
