import type { Halaman } from '@/modules/akses/tipePengaturan'
import { klien } from '@/shared/api/klien'
import type { PrioritasPermintaan } from '@/shared/api/permintaanPemeriksaan'
import type { BarisDaftarKerjaLab, LokasiPengambilanSpesimen, Spesimen } from './tipe'

/** GET /lab/daftar-kerja: order lab aktif satu prioritas, terlama dulu (kontrak v0.40.0). */
export async function daftarKerjaLab(prioritas: PrioritasPermintaan, kursor: string | null = null, batas = 50): Promise<Halaman<BarisDaftarKerjaLab>> {
  const { data } = await klien.get<Halaman<BarisDaftarKerjaLab>>('/lab/daftar-kerja', { params: { prioritas, kursor: kursor ?? undefined, batas } })
  return data
}

/** POST /permintaan-item/{id}/spesimen: catat pengambilan sampel; barcode dibuat server. */
export async function ambilSpesimen(itemId: string, isian: { jenis: string; lokasi_pengambilan: LokasiPengambilanSpesimen }): Promise<Spesimen> {
  const { data } = await klien.post<{ data: Spesimen }>(`/permintaan-item/${itemId}/spesimen`, isian)
  return data.data
}

/** POST /spesimen/{id}/terima: spesimen diterima di lab (kondisi baik). */
export async function terimaSpesimen(spesimenId: string): Promise<Spesimen> {
  const { data } = await klien.post<{ data: Spesimen }>(`/spesimen/${spesimenId}/terima`)
  return data.data
}
