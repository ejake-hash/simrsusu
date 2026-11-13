import type { Halaman } from '@/modules/akses/tipePengaturan'
import { klien } from '@/shared/api/klien'
import type { PrioritasPermintaan } from '@/shared/api/permintaanPemeriksaan'
import type { BarisDaftarKerjaLab, HasilLab, IsianHasilLab, LokasiPengambilanSpesimen, Spesimen, TahapDaftarKerjaLab } from './tipe'

/** GET /lab/daftar-kerja: order lab aktif satu prioritas dan satu tahap, terlama dulu (kontrak v0.40.0, `tahap` v0.41.0). */
export async function daftarKerjaLab(prioritas: PrioritasPermintaan, kursor: string | null = null, batas = 50, tahap: TahapDaftarKerjaLab = 'spesimen'): Promise<Halaman<BarisDaftarKerjaLab>> {
  const { data } = await klien.get<Halaman<BarisDaftarKerjaLab>>('/lab/daftar-kerja', { params: { prioritas, tahap, kursor: kursor ?? undefined, batas } })
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

/** POST /permintaan-item/{id}/hasil: input pertama (201) atau input ulang sebelum validasi (200). Penanda dihitung server. */
export async function inputHasilLab(itemId: string, isian: IsianHasilLab): Promise<HasilLab> {
  const { data } = await klien.post<{ data: HasilLab }>(`/permintaan-item/${itemId}/hasil`, isian)
  return data.data
}

/** POST /hasil-lab/{id}/validasi: validasi analis atau otorisasi Sp.PK; tahapnya ditentukan server dari status hasil. */
export async function validasiHasilLab(hasilId: string): Promise<HasilLab> {
  const { data } = await klien.post<{ data: HasilLab }>(`/hasil-lab/${hasilId}/validasi`)
  return data.data
}

/** POST /hasil-lab/{id}/adendum: koreksi hasil tervalidasi; hasil asli tetap, respons memuat semua adendum. */
export async function adendumHasilLab(hasilId: string, isian: Omit<IsianHasilLab, 'catatan'> & { alasan: string }): Promise<HasilLab> {
  const { data } = await klien.post<{ data: HasilLab }>(`/hasil-lab/${hasilId}/adendum`, isian)
  return data.data
}

/** GET /episode/{id}/hasil-lab: linimasa hasil tervalidasi, terbaru dulu, kursor. */
export async function linimasaHasilLab(episodeId: string, kursor: string | null = null, batas = 50): Promise<Halaman<HasilLab>> {
  const { data } = await klien.get<Halaman<HasilLab>>(`/episode/${episodeId}/hasil-lab`, { params: { kursor: kursor ?? undefined, batas } })
  return data
}
