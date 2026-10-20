import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { VersiDataInduk } from '@/modules/usulan/tipe'
import { klien } from '@/shared/api/klien'
import type { FilterTarif, Tarif } from './tipe'

/** Klien buku tarif (kontrak v0.18.0, tiket 2.2). Hanya baca: perubahan tarif lewat usulan (modul usulan). */
export async function daftarTarif(filter: FilterTarif, kursor: string | null = null, batas = 50): Promise<Halaman<Tarif>> {
  const params = { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas }
  return (await klien.get<Halaman<Tarif>>('/data-induk/tarif', { params })).data
}
export async function versiTarif(id: string, kursor: string | null = null, batas = 20): Promise<Halaman<VersiDataInduk>> {
  return (await klien.get<Halaman<VersiDataInduk>>(`/data-induk/tarif/${id}/versi`, { params: { kursor: kursor ?? undefined, batas } })).data
}
