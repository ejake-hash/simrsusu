import type { AxiosResponse } from 'axios'
import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { FilterPenjamin, IsianPenjamin, Penjamin, UbahPenjamin } from './tipe'

/** Klien /data-induk/penjamin (docs/api/openapi.yaml v0.17.0, tiket 2.1). Kelas rawat tetap /kelas-rawat (1.8b). */
const JALUR = '/data-induk/penjamin'

function berversi(r: AxiosResponse<{ data: Penjamin }>): Berversi<Penjamin> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}
const bersihkan = <T extends object>(f: T) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>

export async function daftarPenjamin(filter: FilterPenjamin, kursor: string | null = null, batas = 50): Promise<Halaman<Penjamin>> {
  const { data } = await klien.get<Halaman<Penjamin>>(JALUR, { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
/** Pilihan penjamin untuk pendaftaran: hanya yang aktif (penjamin nonaktif tetap terbaca di riwayat). */
export async function semuaPenjaminAktif(): Promise<Penjamin[]> {
  const hasil: Penjamin[] = []
  let kursor: string | null = null
  do {
    const h: Halaman<Penjamin> = await daftarPenjamin({ status: 'aktif' }, kursor, 200)
    hasil.push(...h.data)
    kursor = h.meta.kursor_berikutnya
  } while (kursor)
  return hasil
}
export const ambilPenjamin = async (id: string) => berversi(await klien.get(`${JALUR}/${id}`))
export const buatPenjamin = async (isian: IsianPenjamin) => berversi(await klien.post(JALUR, isian))
export const ubahPenjamin = async (id: string, etag: string, isian: UbahPenjamin) =>
  berversi(await klien.patch(`${JALUR}/${id}`, isian, { headers: { 'If-Match': etag } }))
