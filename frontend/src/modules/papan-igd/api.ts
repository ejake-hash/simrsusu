import type { Halaman } from '@/modules/akses/tipePengaturan'
import { berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { AreaIgdDataInduk, AturanPapanIgd, IsianAreaIgd, StatusAreaIgd, UbahAreaIgd, UbahAturanPapanIgd } from './tipe'

/**
 * Klien data induk papan IGD (docs/api/openapi.yaml v0.34.0, tiket 2.25). Pola instrumen triase: edit langsung berizin
 * dengan dasar perubahan dan If-Match. Area tidak punya GET rincian: If-Match memakai `versi` baris daftar (412 →
 * muat ulang daftar).
 */
const JALUR_AREA = '/data-induk/area-igd'
const JALUR_ATURAN = '/data-induk/aturan-papan-igd'

export const KUNCI_DATA_INDUK_PAPAN = {
  semua: ['data-induk-papan-igd'] as const,
  area: (status: StatusAreaIgd | null) => ['data-induk-papan-igd', 'area', status ?? 'semua'] as const,
  aturan: ['data-induk-papan-igd', 'aturan'] as const,
}

/** ETag If-Match dari `versi` baris (sama dengan ETag respons server). */
export const etagArea = (a: Pick<AreaIgdDataInduk, 'versi'>) => `"${a.versi}"`

export async function daftarAreaIgd(status: StatusAreaIgd | null, kursor: string | null = null, batas = 50): Promise<Halaman<AreaIgdDataInduk>> {
  const { data } = await klien.get<Halaman<AreaIgdDataInduk>>(JALUR_AREA, { params: { status: status ?? undefined, kursor: kursor ?? undefined, batas } })
  return data
}
export const buatAreaIgd = async (isian: IsianAreaIgd) => berversi<AreaIgdDataInduk>(await klien.post(JALUR_AREA, isian))
export const ubahAreaIgd = async (id: string, etag: string, isian: UbahAreaIgd) =>
  berversi<AreaIgdDataInduk>(await klien.patch(`${JALUR_AREA}/${id}`, isian, { headers: { 'If-Match': etag } }))

export const ambilAturanPapanIgd = async () => berversi<AturanPapanIgd>(await klien.get(JALUR_ATURAN))
export const ubahAturanPapanIgd = async (etag: string, isian: UbahAturanPapanIgd) =>
  berversi<AturanPapanIgd>(await klien.patch(JALUR_ATURAN, isian, { headers: { 'If-Match': etag } }))
