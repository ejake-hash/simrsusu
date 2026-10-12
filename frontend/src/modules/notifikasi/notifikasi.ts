import type { TabTugas, Tugas } from './tipe'

/** Kunci kueri dan label notifikasi, kotak kerja, dan jenis tugas (tiket 1.7). */

export const KUNCI_NOTIF = {
  semua: ['notifikasi'] as const,
  lonceng: ['notifikasi', 'lonceng'] as const,
  daftar: (status: 'belum_dibaca' | 'semua') => ['notifikasi', 'daftar', status] as const,
  preferensi: ['notifikasi', 'preferensi'] as const,
  tugas: ['tugas'] as const,
  tabTugas: (tab: TabTugas) => ['tugas', 'tab', tab] as const,
  rinciTugas: (id: string) => ['tugas', 'rinci', id] as const,
  jenisTugas: ['jenis-tugas'] as const,
  daftarJenisTugas: (aktif: boolean | undefined) => ['jenis-tugas', 'daftar', aktif ?? 'semua'] as const,
}

export const TAB_TUGAS: { nilai: TabTugas; label: string; kosong: string }[] = [
  { nilai: 'saya', label: 'Tugas saya', kosong: 'Tidak ada tugas terbuka untuk Anda.' },
  { nilai: 'dieskalasi', label: 'Sudah dieskalasi', kosong: 'Tidak ada tugas Anda yang sudah ditegur atau dieskalasi.' },
  { nilai: 'unit', label: 'Unit saya', kosong: 'Tidak ada tugas terbuka di unit yang Anda kepalai.' },
  { nilai: 'selesai', label: 'Selesai bulan ini', kosong: 'Belum ada tugas yang selesai bulan ini.' },
]

export const LABEL_TINGKAT: Record<number, string> = { 0: 'Baru', 1: 'Pengingat', 2: 'Teguran', 3: 'Eskalasi' }
export const PIL_TINGKAT: Record<number, string> = { 0: 'pil-netral', 1: 'pil-info', 2: 'pil-peringatan', 3: 'pil-bahaya' }
export const LABEL_RIWAYAT: Record<string, string> = {
  dibuat: 'Dibuat',
  pengingat: 'Pengingat',
  teguran: 'Teguran',
  eskalasi: 'Eskalasi',
  selesai: 'Selesai',
  batal: 'Dibatalkan',
}
export const LABEL_KANAL: Record<string, string> = { in_app: 'aplikasi', email: 'email', pesan_singkat: 'pesan singkat' }

/** "2 jam lagi", "lewat 3 hari", relatif terhadap `sekarang` (ms). Satuan terbesar yang ≥ 1. */
export function waktuRelatif(iso: string, sekarang = Date.now()): string {
  const selisih = new Date(iso).getTime() - sekarang
  const mutlak = Math.abs(selisih)
  const menit = Math.round(mutlak / 60_000)
  const teks = menit < 1 ? 'kurang dari 1 menit' : menit < 60 ? `${menit} menit` : menit < 60 * 24 ? `${Math.round(menit / 60)} jam` : `${Math.round(menit / 60 / 24)} hari`
  return selisih >= 0 ? `${teks} lagi` : `lewat ${teks}`
}

/** Keterangan tenggat untuk satu tugas (terbuka: relatif; selesai: tepat waktu atau terlambat). */
export function keteranganTenggat(t: Tugas, sekarang = Date.now()): string {
  if (t.status !== 'terbuka') return t.terlambat ? 'Selesai sesudah tenggat' : 'Selesai tepat waktu'
  return `Tenggat ${waktuRelatif(t.tenggat_pada, sekarang)}`
}

/** Menit → teks jam/hari untuk layar jenis tugas (60 → "1 jam", 1440 → "1 hari", 90 → "90 menit"). */
export function teksMenit(menit: number): string {
  const m = Math.abs(menit)
  if (m === 0) return 'saat itu juga'
  if (m % 1440 === 0) return `${m / 1440} hari`
  if (m % 60 === 0) return `${m / 60} jam`
  return `${m} menit`
}
