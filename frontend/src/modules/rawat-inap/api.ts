import type { AxiosResponse } from 'axios'
import type { HasilPolling } from '@/modules/notifikasi/api'
import { klien } from '@/shared/api/klien'
import type { FilterPetaServer, PetaTempatTidur, TempatTidurPeta } from './tipe'

/**
 * Klien peta tempat tidur (docs/api/openapi.yaml v0.35.0, tiket 2.27). Pola K1 sama dengan papan pasien IGD: If-None-Match
 * dengan ETag terakhir, 304 = data lama tetap; `latar` menambah X-Permintaan-Latar agar polling tidak memperpanjang sesi
 * yang diam (PRD 14.2). Hanya gedung dan bangsal yang disaring server; lantai, status, dan kode TT disaring di layar.
 */
export async function bacaPetaTempatTidur(filter: FilterPetaServer, polling: { etag: string | null; latar: boolean } = { etag: null, latar: false }): Promise<HasilPolling<PetaTempatTidur>> {
  const headers: Record<string, string> = {}
  if (polling.etag) headers['If-None-Match'] = polling.etag
  if (polling.latar) headers['X-Permintaan-Latar'] = '1'
  const r: AxiosResponse<PetaTempatTidur> = await klien.get('/peta-tempat-tidur', {
    params: { gedung_id: filter.gedung_id ?? undefined, bangsal_id: filter.bangsal_id ?? undefined },
    headers,
    validateStatus: (s) => (s >= 200 && s < 300) || s === 304,
  })
  if (r.status === 304) return { berubah: false }
  const e = r.headers.etag
  return { berubah: true, data: r.data, etag: typeof e === 'string' && e ? e : null }
}

/** POST /tempat-tidur/{id}/status: satu-satunya transisi petugas di 2.27, dibersihkan → kosong siap. */
export async function tandaiKosongSiap(id: string): Promise<TempatTidurPeta> {
  const { data } = await klien.post<{ data: TempatTidurPeta }>(`/tempat-tidur/${id}/status`, { status: 'kosong_siap' })
  return data.data
}
