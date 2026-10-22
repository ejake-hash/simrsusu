import type { AxiosResponse } from 'axios'
import type { Berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { HalamanPasien, IsianPasien, Pasien, UbahPasien } from './tipe'

/**
 * Klien /pasien (docs/api/openapi.yaml v0.19.0, tiket 2.5). Kata cari lewat header `X-Cari`, bukan query string,
 * supaya NIK/No. RM/nama pasien tidak pernah muncul di URL atau log (PRD 14.2). Respons tidak di-cache (no-store).
 */
const JALUR = '/pasien'

function berversi(r: AxiosResponse<{ data: Pasien }>): Berversi<Pasien> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}

/** Nilai `X-Cari`: di-encode persen UTF-8 (encodeURIComponent) seperti contoh kontrak. */
export async function cariPasien(kata: string, kursor: string | null = null, batas = 50): Promise<HalamanPasien> {
  const { data } = await klien.get<HalamanPasien>(JALUR, {
    params: { kursor: kursor ?? undefined, batas },
    headers: { 'X-Cari': encodeURIComponent(kata) },
  })
  return data
}

export const buatPasien = async (isian: IsianPasien) => berversi(await klien.post(JALUR, isian))
export const ubahPasien = async (id: string, etag: string, isian: UbahPasien) =>
  berversi(await klien.patch(`${JALUR}/${id}`, isian, { headers: { 'If-Match': etag } }))
