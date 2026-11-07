import { klien } from '@/shared/api/klien'
import type { AksiAntrean, Antrean, FilterAntrean, HalamanAntrean } from './tipe'

/** Klien /antrean (docs/api/openapi.yaml, tiket 2.8). Respons tidak di-cache (no-store). */
export async function daftarAntrean(filter: FilterAntrean, kursor: string | null = null, batas = 50): Promise<HalamanAntrean> {
  const { data } = await klien.get<HalamanAntrean>('/antrean', {
    params: { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas },
  })
  return data
}

/** `alasanBatal` hanya untuk aksi `batal` (wajib 5–500 karakter). */
export async function aksiAntrean(id: string, aksi: AksiAntrean, alasanBatal?: string): Promise<Antrean> {
  const { data } = await klien.post<{ data: Antrean }>(`/antrean/${id}/${aksi}`, aksi === 'batal' ? { alasan_batal: alasanBatal } : undefined)
  return data.data
}
