import type { Halaman } from '@/modules/akses/tipePengaturan'
import { berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type {
  Barang,
  FilterBarang,
  FilterItem,
  Formularium,
  HasilImpor,
  IsianBarang,
  IsianFormularium,
  IsianItem,
  ItemFormularium,
  JenisFormularium,
  UbahBarang,
  UbahFormularium,
  UbahItem,
} from './tipe'

/** Klien /data-induk/barang dan /data-induk/formularium (docs/api/openapi.yaml v0.23.0, tiket 2.11). */
const BARANG = '/data-induk/barang'
const FORMULARIUM = '/data-induk/formularium'

const bersihkan = <T extends object>(f: T) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>

export async function daftarBarang(filter: FilterBarang, kursor: string | null = null, batas = 50): Promise<Halaman<Barang>> {
  const { data } = await klien.get<Halaman<Barang>>(BARANG, { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
export const ambilBarang = async (id: string) => berversi<Barang>(await klien.get(`${BARANG}/${id}`))
export const buatBarang = async (isian: IsianBarang) => berversi<Barang>(await klien.post(BARANG, isian))
export const ubahBarang = async (id: string, etag: string, isian: UbahBarang) =>
  berversi<Barang>(await klien.patch(`${BARANG}/${id}`, isian, { headers: { 'If-Match': etag } }))

/** Impor CSV: `pratinjau` hanya memeriksa, `terapkan` menyimpan semua baris atau tidak sama sekali. */
export async function imporCsv(jalur: string, berkas: File, mode: 'pratinjau' | 'terapkan', dasar: string): Promise<HasilImpor> {
  const isi = new FormData()
  isi.append('berkas', berkas)
  isi.append('mode', mode)
  isi.append('dasar_perubahan', dasar)
  const { data } = await klien.post<{ data: HasilImpor }>(jalur, isi)
  return data.data
}
export const imporBarang = (berkas: File, mode: 'pratinjau' | 'terapkan', dasar: string) => imporCsv(`${BARANG}/impor`, berkas, mode, dasar)
export const imporItem = (formulariumId: string, berkas: File, mode: 'pratinjau' | 'terapkan', dasar: string) =>
  imporCsv(`${FORMULARIUM}/${formulariumId}/item/impor`, berkas, mode, dasar)

export async function daftarFormularium(filter: { jenis?: JenisFormularium } = {}, kursor: string | null = null, batas = 50): Promise<Halaman<Formularium>> {
  const { data } = await klien.get<Halaman<Formularium>>(FORMULARIUM, { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
export const ambilFormularium = async (id: string) => berversi<Formularium>(await klien.get(`${FORMULARIUM}/${id}`))
export const buatFormularium = async (isian: IsianFormularium) => berversi<Formularium>(await klien.post(FORMULARIUM, isian))
export const ubahFormularium = async (id: string, etag: string, isian: UbahFormularium) =>
  berversi<Formularium>(await klien.patch(`${FORMULARIUM}/${id}`, isian, { headers: { 'If-Match': etag } }))

export async function daftarItem(formulariumId: string, filter: FilterItem, kursor: string | null = null, batas = 50): Promise<Halaman<ItemFormularium>> {
  const { data } = await klien.get<Halaman<ItemFormularium>>(`${FORMULARIUM}/${formulariumId}/item`, { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
/** Item tak punya GET rincian: If-Match diambil dari ETag/versi baris daftar. */
export const tambahItem = async (formulariumId: string, isian: IsianItem) => berversi<ItemFormularium>(await klien.post(`${FORMULARIUM}/${formulariumId}/item`, isian))
export const ubahItem = async (formulariumId: string, itemId: string, etag: string, isian: UbahItem) =>
  berversi<ItemFormularium>(await klien.patch(`${FORMULARIUM}/${formulariumId}/item/${itemId}`, isian, { headers: { 'If-Match': etag } }))
