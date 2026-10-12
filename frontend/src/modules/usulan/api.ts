import type { Halaman } from '@/modules/akses/tipePengaturan'
import { klien } from '@/shared/api/klien'
import type { FilterTahap, HalamanUsulan, IsianUsulan, JenisDataInduk, ObjekDataInduk, UsulanDataInduk, VersiDataInduk } from './tipe'

/** Klien usulan perubahan data induk dan riwayat versi (docs/api/openapi.yaml v0.9.0, tiket 1.8c). */

export async function daftarUsulan(filter: { tahap?: FilterTahap; jenis_data?: JenisDataInduk; perlu_saya?: boolean }, kursor: string | null = null, batas = 50): Promise<HalamanUsulan> {
  const { data } = await klien.get<HalamanUsulan>('/usulan-data-induk', { params: { ...filter, kursor: kursor ?? undefined, batas } })
  return data
}
export async function ambilUsulan(id: string): Promise<UsulanDataInduk> {
  const { data } = await klien.get<{ data: UsulanDataInduk }>(`/usulan-data-induk/${id}`)
  return data.data
}
export async function buatUsulan(isian: IsianUsulan): Promise<UsulanDataInduk> {
  const { data } = await klien.post<{ data: UsulanDataInduk }>('/usulan-data-induk', isian)
  return data.data
}
export async function tindakUsulan(id: string, aksi: 'verifikasi' | 'setujui' | 'kembalikan', catatan: string | null): Promise<UsulanDataInduk> {
  const { data } = await klien.post<{ data: UsulanDataInduk }>(`/usulan-data-induk/${id}/${aksi}`, { catatan })
  return data.data
}
export async function kirimUlangUsulan(id: string, isian: Omit<IsianUsulan, 'jenis_data'>): Promise<UsulanDataInduk> {
  const { data } = await klien.post<{ data: UsulanDataInduk }>(`/usulan-data-induk/${id}/kirim-ulang`, isian)
  return data.data
}
/** Satu halaman riwayat versi objek data induk (terbaru dulu). */
export async function riwayatVersi(jenis: ObjekDataInduk, id: string, kursor: string | null = null, batas = 20): Promise<Halaman<VersiDataInduk>> {
  const { data } = await klien.get<Halaman<VersiDataInduk>>(`/data-induk/${jenis}/${id}/riwayat-versi`, { params: { kursor: kursor ?? undefined, batas } })
  return data
}
