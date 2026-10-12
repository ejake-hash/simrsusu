import type { PersetujuanAkses } from '@/modules/akses/tipePersetujuan'
import { klien } from '@/shared/api/klien'
import type { HasilTandaTangan, JenisObjekTtd, StatusTtd, TandaTangan } from './tipe'

/**
 * Klien TTE T1 (docs/api/openapi.yaml v0.8.0, tiket 1.6). PIN dan sandi hanya di body (tidak di URL), tidak disimpan
 * klien, dan dikosongkan dari formulir sesudah dikirim. Isi yang ditandatangani dihitung server dari objeknya.
 */

export async function aktivasiTtd(isian: { sandi: string; pin: string; pin_ulang: string }): Promise<StatusTtd> {
  const { data } = await klien.post<{ data: StatusTtd }>('/saya/ttd/aktivasi', isian)
  return data.data
}
export async function gantiPinTtd(isian: { pin_lama: string; pin_baru: string; pin_ulang: string }): Promise<StatusTtd> {
  const { data } = await klien.put<{ data: StatusTtd }>('/saya/ttd/pin', isian)
  return data.data
}
/** Diajukan SDM (sdm.kredensial.perbaiki), disetujui atasan langsung pemilik: 202 = persetujuan menunggu. */
export async function ajukanResetTtd(akunId: string, isian: { alasan: string; dasar: string }): Promise<PersetujuanAkses> {
  const { data } = await klien.post<{ data: PersetujuanAkses }>(`/ttd/${akunId}/reset`, isian)
  return data.data
}
/** `pernyataanDisetujui` = pengguna mencentang kalimat persetujuan yang ditampilkan bersama ringkasan isi (PRD 7.57.2 f). */
export async function tandatangani(objek: { jenis: JenisObjekTtd; id: string }[], pin: string | null, pernyataanDisetujui: boolean): Promise<HasilTandaTangan> {
  const { data } = await klien.post<HasilTandaTangan>('/ttd/tandatangani', { objek, pin, pernyataan_disetujui: pernyataanDisetujui })
  return data
}
export async function verifikasiTtd(jenis: JenisObjekTtd, id: string): Promise<TandaTangan[]> {
  const { data } = await klien.get<{ data: TandaTangan[] }>(`/ttd/verifikasi/${jenis}/${id}`)
  return data.data
}
