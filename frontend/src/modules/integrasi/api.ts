import { klien } from '@/shared/api/klien'
import type { FilterKiriman, FilterLog, Halaman, HasilUjiKoneksi, Kiriman, LingkunganIntegrasi, LogIntegrasi, SistemIntegrasi, StatusSistemIntegrasi } from './tipe'

/** Klien gateway integrasi (kontrak v0.13.0, tiket 1.9). */

export async function daftarSistem(): Promise<StatusSistemIntegrasi[]> {
  const { data } = await klien.get<{ data: StatusSistemIntegrasi[] }>('/integrasi/sistem')
  return data.data
}
export async function simpanKredensial(
  kode: SistemIntegrasi,
  isian: { lingkungan: LingkunganIntegrasi; kolom?: Record<string, string>; aktifkan?: boolean; nonaktifkan?: boolean },
): Promise<StatusSistemIntegrasi> {
  const { data } = await klien.put<{ data: StatusSistemIntegrasi }>(`/integrasi/sistem/${kode}/kredensial`, isian)
  return data.data
}
export async function ujiKoneksi(kode: SistemIntegrasi, lingkungan?: LingkunganIntegrasi): Promise<HasilUjiKoneksi> {
  const { data } = await klien.post<{ data: HasilUjiKoneksi }>(`/integrasi/sistem/${kode}/uji-koneksi`, lingkungan ? { lingkungan } : {})
  return data.data
}
export async function daftarKiriman(filter: FilterKiriman, kursor: string | null = null, batas = 50): Promise<Halaman<Kiriman>> {
  const { data } = await klien.get<Halaman<Kiriman>>('/integrasi/kotak-keluar', { params: { ...filter, kursor: kursor ?? undefined, batas } })
  return data
}
export async function kirimUlang(id: string): Promise<Kiriman> {
  const { data } = await klien.post<{ data: Kiriman }>(`/integrasi/kotak-keluar/${id}/kirim-ulang`)
  return data.data
}
/** Log: isi per baris sampai 2 × 16 KB, jadi halaman kecil (batas maksimum server 50). */
export async function daftarLog(filter: FilterLog, kursor: string | null = null, batas = 20): Promise<Halaman<LogIntegrasi>> {
  const { data } = await klien.get<Halaman<LogIntegrasi>>('/integrasi/log', { params: { ...filter, kursor: kursor ?? undefined, batas } })
  return data
}
