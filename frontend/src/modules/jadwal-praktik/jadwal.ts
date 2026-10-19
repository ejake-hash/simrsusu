import type { AlasanTidakTersedia } from './tipe'

export const KUNCI_JADWAL = {
  semua: ['jadwal-praktik'] as const,
  daftar: (filter: object) => ['jadwal-praktik', 'daftar', filter] as const,
}
/** Hari ISO 1 = Senin … 7 = Minggu (zona RS). */
export const NAMA_HARI = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']
export const labelHari = (h: number) => NAMA_HARI[h - 1] ?? String(h)
export const LABEL_ALASAN: Record<AlasanTidakTersedia, string> = { cuti: 'Dokter cuti', libur: 'Libur RS', nonaktif: 'Jadwal nonaktif' }

/** Hari ini menurut jam perangkat (YYYY-MM-DD); server memakai zona RS. */
export function hariIniLokal(): string {
  const t = new Date()
  return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`
}
/** Sisa kuota ≤ 20% (atau 0) ditandai agar petugas pendaftaran melihatnya tanpa menghitung. */
export function nadaSisa(sisa: number, kuota: number): 'bahaya' | 'peringatan' | 'ok' {
  if (kuota === 0 || sisa === 0) return 'bahaya'
  return sisa / kuota <= 0.2 ? 'peringatan' : 'ok'
}
