import type { Halaman } from '@/modules/akses/tipePengaturan'
import { berversi, type Berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { Alergi, AsesmenAwal, BarisWorklist, FilterWorklist, IsianAlergi, IsianAsesmenAwal, Terbatas } from './tipe'

/** Klien worklist poli, asesmen awal perawat, dan alergi (docs/api/openapi.yaml v0.23.0, tiket 2.13). */
const ifMatch = (etag: string) => ({ headers: { 'If-Match': etag } })

export async function daftarWorklist(filter: FilterWorklist, kursor: string | null = null, batas = 50): Promise<Halaman<BarisWorklist>> {
  const params = { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas }
  return (await klien.get<Halaman<BarisWorklist>>('/rawat-jalan/worklist', { params })).data
}

/** 404 = belum ada asesmen; pemanggil menangani. Berlabel sensitif bagi bukan penulis → `{terbatas: true}` tanpa ETag. */
export async function ambilAsesmenKunjungan(kunjunganId: string): Promise<{ data: AsesmenAwal | Terbatas; etag: string | null }> {
  const r = await klien.get<{ data: AsesmenAwal | Terbatas }>(`/kunjungan/${kunjunganId}/asesmen-awal`)
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : 'versi' in r.data.data ? `"${r.data.data.versi}"` : null }
}
export async function mulaiAsesmen(kunjunganId: string, isian: IsianAsesmenAwal): Promise<Berversi<AsesmenAwal>> {
  return berversi(await klien.post(`/kunjungan/${kunjunganId}/asesmen-awal`, isian))
}
export async function ubahAsesmen(id: string, etag: string, isian: IsianAsesmenAwal & { alasan_perubahan?: string }): Promise<Berversi<AsesmenAwal>> {
  return berversi(await klien.patch(`/asesmen-awal/${id}`, isian, ifMatch(etag)))
}
export async function selesaikanAsesmen(id: string, etag: string): Promise<Berversi<AsesmenAwal>> {
  return berversi(await klien.post(`/asesmen-awal/${id}/selesai`, undefined, ifMatch(etag)))
}

export async function daftarAlergi(pasienId: string, status: 'aktif' | 'batal' = 'aktif'): Promise<Halaman<Alergi>> {
  return (await klien.get<Halaman<Alergi>>(`/pasien/${pasienId}/alergi`, { params: { status, batas: 200 } })).data
}
export async function catatAlergi(pasienId: string, isian: IsianAlergi): Promise<Alergi> {
  return (await klien.post<{ data: Alergi }>(`/pasien/${pasienId}/alergi`, isian)).data.data
}
export async function batalkanAlergi(id: string, alasan: string): Promise<Alergi> {
  return (await klien.post<{ data: Alergi }>(`/alergi/${id}/batal`, { alasan })).data.data
}
