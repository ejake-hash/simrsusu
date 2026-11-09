import { klien } from '@/shared/api/klien'
import type { AksiAntrean, Antrean, FilterAntrean, HalamanAntrean } from './tipe'

/** Klien /antrean (docs/api/openapi.yaml, tiket 2.8). */
export async function daftarAntrean(filter: FilterAntrean, kursor: string | null = null, batas = 50): Promise<HalamanAntrean> {
  const { data } = await klien.get<HalamanAntrean>('/antrean', {
    params: { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas },
  })
  return data
}

/**
 * `alasanBatal` hanya untuk aksi `batal` (wajib 5–500 karakter). `lokasiId` hanya untuk `panggil`: ruang/loket tempat pasien
 * dipanggil; wajib untuk titik `loket` (loket admisi tidak punya lokasi bawaan, tiket 2.20), opsional untuk poli.
 */
export async function aksiAntrean(id: string, aksi: AksiAntrean, alasanBatal?: string, lokasiId?: string): Promise<Antrean> {
  const isi = aksi === 'batal' ? { alasan_batal: alasanBatal } : aksi === 'panggil' && lokasiId ? { lokasi_id: lokasiId } : undefined
  const { data } = await klien.post<{ data: Antrean }>(`/antrean/${id}/${aksi}`, isi)
  return data.data
}
