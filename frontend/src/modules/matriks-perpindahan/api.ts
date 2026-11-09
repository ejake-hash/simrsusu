import type { Halaman } from '@/modules/akses/tipePengaturan'
import { klien } from '@/shared/api/klien'
import type { AturanPerpindahan, FilterMatriks } from './tipe'

/**
 * Klien GET /data-induk/matriks-perpindahan (docs/api/openapi.yaml v0.33.0, tiket 2.26). Hanya baca: perubahan sel lewat
 * usulan data induk (`jenis_data` `matriks_perpindahan`). Izin: master_data.matriks_perpindahan.lihat atau
 * igd.disposisi.lihat (layar Disposisi memilih tujuan).
 */
export async function daftarMatriks(filter: FilterMatriks, kursor: string | null = null, batas = 200): Promise<Halaman<AturanPerpindahan>> {
  const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== undefined && v !== ''))
  const { data } = await klien.get<Halaman<AturanPerpindahan>>('/data-induk/matriks-perpindahan', { params: { ...params, kursor: kursor ?? undefined, batas } })
  return data
}

/** Semua sel yang cocok (matriks kecil: puluhan sel). Halaman berikutnya diikuti sampai habis. */
export async function semuaMatriks(filter: FilterMatriks): Promise<AturanPerpindahan[]> {
  const hasil: AturanPerpindahan[] = []
  let kursor: string | null = null
  do {
    const h: Halaman<AturanPerpindahan> = await daftarMatriks(filter, kursor)
    hasil.push(...h.data)
    kursor = h.meta.kursor_berikutnya
  } while (kursor)
  return hasil
}
