import type { Halaman } from '@/modules/akses/tipePengaturan'
import { berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { FilterInstrumenTriase, InstrumenTriaseDataInduk, IsianInstrumenTriase, UbahInstrumenTriase } from './tipe'

/**
 * Klien /data-induk/instrumen-triase (docs/api/openapi.yaml v0.32.0, tiket 2.24). Pola penjamin: edit langsung berizin
 * dengan dasar perubahan dan If-Match. Layar Triase membaca instrumen aktif lewat GET /instrumen-triase.
 */
const JALUR = '/data-induk/instrumen-triase'

export const KUNCI_INSTRUMEN_TRIASE = {
  semua: ['instrumen-triase'] as const,
  daftar: (filter: FilterInstrumenTriase) => ['instrumen-triase', 'daftar', filter] as const,
}

export async function daftarInstrumenTriaseDataInduk(filter: FilterInstrumenTriase, kursor: string | null = null, batas = 50): Promise<Halaman<InstrumenTriaseDataInduk>> {
  const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== undefined && v !== ''))
  const { data } = await klien.get<Halaman<InstrumenTriaseDataInduk>>(JALUR, { params: { ...params, kursor: kursor ?? undefined, batas } })
  return data
}
export const ambilInstrumenTriase = async (id: string) => berversi<InstrumenTriaseDataInduk>(await klien.get(`${JALUR}/${id}`))
export const buatInstrumenTriase = async (isian: IsianInstrumenTriase) => berversi<InstrumenTriaseDataInduk>(await klien.post(JALUR, isian))
export const ubahInstrumenTriase = async (id: string, etag: string, isian: UbahInstrumenTriase) =>
  berversi<InstrumenTriaseDataInduk>(await klien.patch(`${JALUR}/${id}`, isian, { headers: { 'If-Match': etag } }))
