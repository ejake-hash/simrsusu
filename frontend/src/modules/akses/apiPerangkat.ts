import { klien } from '@/shared/api/klien'
import type { Halaman } from './tipePengaturan'
import type { KebijakanKeamanan, PerangkatTepercaya } from './tipePerangkat'

/** Klien perangkat tepercaya dan kebijakan keamanan (kontrak v0.16.0, tiket 1.13). */
export type StatusDaftar = 'aktif' | 'semua'
const BATAS = 50

export async function daftarPerangkatSaya(status: StatusDaftar, kursor: string | null = null): Promise<Halaman<PerangkatTepercaya>> {
  const { data } = await klien.get<Halaman<PerangkatTepercaya>>('/saya/perangkat', { params: { status, kursor: kursor ?? undefined, batas: BATAS } })
  return data
}

/** Mencabut perangkat sesi ini juga membuat server mengirim Set-Cookie kedaluwarsa untuk token perangkat. */
export async function cabutPerangkatSaya(id: string): Promise<PerangkatTepercaya> {
  const { data } = await klien.post<{ data: PerangkatTepercaya }>(`/saya/perangkat/${id}/cabut`)
  return data.data
}

export async function daftarPerangkatAkun(akunId: string, status: StatusDaftar, kursor: string | null = null): Promise<Halaman<PerangkatTepercaya>> {
  const { data } = await klien.get<Halaman<PerangkatTepercaya>>(`/akun/${akunId}/perangkat`, { params: { status, kursor: kursor ?? undefined, batas: BATAS } })
  return data
}

export async function cabutPerangkatAkun(akunId: string, perangkatId: string, alasan: string): Promise<PerangkatTepercaya> {
  const { data } = await klien.post<{ data: PerangkatTepercaya }>(`/akun/${akunId}/perangkat/${perangkatId}/cabut`, { alasan })
  return data.data
}

export interface KebijakanBerversi {
  kebijakan: KebijakanKeamanan
  etag: string
}
function berversi(r: { headers: Record<string, unknown>; data: { data: KebijakanKeamanan } }): KebijakanBerversi {
  const etag = r.headers.etag
  return { kebijakan: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}

export async function ambilKebijakanKeamanan(): Promise<KebijakanBerversi> {
  return berversi(await klien.get('/kebijakan-keamanan'))
}

export async function ubahKebijakanKeamanan(etag: string, isian: { perangkat_tepercaya_hari: number; dasar_perubahan: string }): Promise<KebijakanBerversi> {
  return berversi(await klien.put('/kebijakan-keamanan', isian, { headers: { 'If-Match': etag } }))
}
