import { klien } from '@/shared/api/klien'
import type { HasilMuatReferensi, KodeLangkah, SistemReferensi, StatusPenyiapan } from './tipe'

/** Klien penyiapan instalasi (kontrak v0.12.0) dan pemuat paket referensi (v0.11.0). */

export async function ambilPenyiapan(): Promise<StatusPenyiapan> {
  const { data } = await klien.get<{ data: StatusPenyiapan }>('/penyiapan')
  return data.data
}
export async function konfirmasiLangkah(langkah: KodeLangkah, lewati: { alasan: string } | null = null): Promise<StatusPenyiapan> {
  const { data } = await klien.post<{ data: StatusPenyiapan }>(`/penyiapan/${langkah}/selesai`, lewati ? { dilewati: true, alasan: lewati.alasan } : {})
  return data.data
}
export async function muatPaket(
  sistem: SistemReferensi,
  isian: { berkas: File; mode: 'pratinjau' | 'terapkan'; versi: string; sumber: string; catatan_lisensi: string | null },
): Promise<HasilMuatReferensi> {
  const isi = new FormData()
  isi.append('berkas', isian.berkas)
  isi.append('mode', isian.mode)
  isi.append('versi', isian.versi)
  isi.append('sumber', isian.sumber)
  if (isian.catatan_lisensi) isi.append('catatan_lisensi', isian.catatan_lisensi)
  const { data } = await klien.post<{ data: HasilMuatReferensi }>(`/paket-referensi/${sistem}/muat`, isi)
  return data.data
}
