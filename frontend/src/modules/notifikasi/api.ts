import type { AxiosResponse } from 'axios'
import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { HalamanNotifikasi, HalamanTugas, JenisTugas, Notifikasi, PreferensiNotifikasi, TabTugas, TugasRinci, UbahJenisTugas } from './tipe'

/** Klien notifikasi, kotak kerja, preferensi, dan jenis tugas (docs/api/openapi.yaml v0.7.0, tiket 1.7). */

/**
 * Hasil permintaan yang bisa dijawab 304: `berubah = false` = isi sama dengan ETag yang dikirim (data tidak dikirim).
 * `latar = true` hanya untuk polling otomatis: header X-Permintaan-Latar membuat server tidak memperbarui waktu aktif
 * sesi (batas diam tetap berjalan di komputer bersama, PRD 14.2).
 */
export type HasilPolling<T> = { berubah: true; data: T; etag: string | null } | { berubah: false }

async function ambilBerEtag<T>(jalur: string, params: Record<string, unknown>, etag: string | null, latar: boolean): Promise<HasilPolling<T>> {
  const headers: Record<string, string> = {}
  if (etag) headers['If-None-Match'] = etag
  if (latar) headers['X-Permintaan-Latar'] = '1'
  const r: AxiosResponse<T> = await klien.get(jalur, { params, headers, validateStatus: (s) => (s >= 200 && s < 300) || s === 304 })
  if (r.status === 304) return { berubah: false }
  const e = r.headers.etag
  return { berubah: true, data: r.data, etag: typeof e === 'string' && e ? e : null }
}

// ── Notifikasi ──
export function daftarNotifikasi(
  filter: { status?: 'belum_dibaca' | 'semua' },
  kursor: string | null = null,
  batas = 20,
  polling: { etag: string | null; latar: boolean } = { etag: null, latar: false },
): Promise<HasilPolling<HalamanNotifikasi>> {
  return ambilBerEtag('/saya/notifikasi', { status: filter.status, kursor: kursor ?? undefined, batas }, polling.etag, polling.latar)
}
export async function tandaiDibaca(id: string): Promise<Notifikasi> {
  const { data } = await klien.post<{ data: Notifikasi }>(`/saya/notifikasi/${id}/dibaca`)
  return data.data
}
/** `sampai` = waktu notifikasi terbaru yang sudah ditampilkan; notifikasi kritis tidak ikut ditandai (server). */
export async function tandaiSemuaDibaca(sampai: string | null): Promise<number> {
  const { data } = await klien.post<{ data: { jumlah: number } }>('/saya/notifikasi/semua-dibaca', { sampai })
  return data.data.jumlah
}

// ── Preferensi ──
export async function ambilPreferensi(): Promise<PreferensiNotifikasi[]> {
  const { data } = await klien.get<{ data: PreferensiNotifikasi[] }>('/saya/preferensi-notifikasi')
  return data.data
}
export async function aturPreferensi(preferensi: { jenis: string; email?: boolean; pesan_singkat?: boolean }[]): Promise<PreferensiNotifikasi[]> {
  const { data } = await klien.put<{ data: PreferensiNotifikasi[] }>('/saya/preferensi-notifikasi', { preferensi })
  return data.data
}

// ── Kotak kerja ──
export function daftarTugas(
  tab: TabTugas,
  kursor: string | null = null,
  batas = 20,
  polling: { etag: string | null; latar: boolean } = { etag: null, latar: false },
): Promise<HasilPolling<HalamanTugas>> {
  return ambilBerEtag('/saya/tugas', { tab, kursor: kursor ?? undefined, batas }, polling.etag, polling.latar)
}
export async function ambilTugas(id: string): Promise<TugasRinci> {
  const { data } = await klien.get<{ data: TugasRinci }>(`/tugas/${id}`)
  return data.data
}

// ── Jenis tugas (data induk) ──
function berversi(r: AxiosResponse<{ data: JenisTugas }>): Berversi<JenisTugas> {
  const e = r.headers.etag
  return { data: r.data.data, etag: typeof e === 'string' && e ? e : `"${r.data.data.versi}"` }
}
export async function daftarJenisTugas(filter: { aktif?: boolean }, kursor: string | null = null, batas = 50): Promise<Halaman<JenisTugas>> {
  const { data } = await klien.get<Halaman<JenisTugas>>('/jenis-tugas', { params: { aktif: filter.aktif, kursor: kursor ?? undefined, batas } })
  return data
}
export async function ambilJenisTugas(id: string): Promise<Berversi<JenisTugas>> {
  return berversi(await klien.get(`/jenis-tugas/${id}`))
}
export async function ubahJenisTugas(id: string, etag: string, isian: UbahJenisTugas): Promise<Berversi<JenisTugas>> {
  return berversi(await klien.patch(`/jenis-tugas/${id}`, isian, { headers: { 'If-Match': etag } }))
}
