import type { AxiosResponse } from 'axios'
import type { Berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { FilterKunjungan, HalamanKunjungan, IsianKunjungan, Kunjungan } from './tipeKunjungan'

/**
 * Klien /kunjungan (docs/api/openapi.yaml v0.20.0, tiket 2.6). Episode rawat jalan harian terbentuk/digabung otomatis
 * di server (7.00.2); event `KunjunganDibuat` dipakai SEP (2.7) dan antrean (2.8). Respons tidak di-cache (no-store).
 */
const JALUR = '/kunjungan'

function berversi(r: AxiosResponse<{ data: Kunjungan }>): Berversi<Kunjungan> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}

export async function daftarKunjungan(filter: FilterKunjungan, kursor: string | null = null, batas = 50): Promise<HalamanKunjungan> {
  const { data } = await klien.get<HalamanKunjungan>(JALUR, {
    params: { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas },
  })
  return data
}

export const buatKunjungan = async (isian: IsianKunjungan) => berversi(await klien.post(JALUR, isian))
export const batalkanKunjungan = async (id: string, alasanBatal: string) =>
  berversi(await klien.post(`${JALUR}/${id}/batal`, { alasan_batal: alasanBatal }))
