import type { AxiosResponse } from 'axios'
import type { HasilPolling } from '@/modules/notifikasi/api'
import { klien } from '@/shared/api/klien'
import type { PapanIgd, PenempatanAreaIgd } from './tipePapan'

/**
 * Klien papan pasien IGD (docs/api/openapi.yaml v0.34.0, tiket 2.25). Papan memakai sesi petugas (cookie Sanctum lewat
 * `klien`), bukan token perangkat seperti display antrean. Pola K1 sama dengan lonceng notifikasi: If-None-Match dengan
 * ETag terakhir, 304 diterima sebagai jawaban sah (`validateStatus`) dan berarti data lama tetap; `latar` menambah
 * X-Permintaan-Latar agar polling tidak memperpanjang sesi yang diam (PRD 14.2) dan tidak membuka dialog MFA.
 */
export async function bacaPapanIgd(areaId: string | null, polling: { etag: string | null; latar: boolean } = { etag: null, latar: false }): Promise<HasilPolling<PapanIgd>> {
  const headers: Record<string, string> = {}
  if (polling.etag) headers['If-None-Match'] = polling.etag
  if (polling.latar) headers['X-Permintaan-Latar'] = '1'
  const r: AxiosResponse<PapanIgd> = await klien.get('/igd/papan', {
    params: { area_id: areaId ?? undefined },
    headers,
    validateStatus: (s) => (s >= 200 && s < 300) || s === 304,
  })
  if (r.status === 304) return { berubah: false }
  const e = r.headers.etag
  return { berubah: true, data: r.data, etag: typeof e === 'string' && e ? e : null }
}

/** PUT /kunjungan/{id}/area-igd: tempatkan atau pindahkan pasien ke area aktif. */
export async function tempatkanAreaIgd(kunjunganId: string, areaIgdId: string): Promise<PenempatanAreaIgd> {
  const { data } = await klien.put<{ data: PenempatanAreaIgd }>(`/kunjungan/${kunjunganId}/area-igd`, { area_igd_id: areaIgdId })
  return data.data
}
