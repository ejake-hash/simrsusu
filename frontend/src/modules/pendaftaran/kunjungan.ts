import type { CaraBayar, StatusKunjungan } from './tipeKunjungan'

/** Kunci cache dan label bersama layar Pendaftaran kunjungan poli (tiket 2.6, kontrak v0.20.0). */
export const KUNCI_KUNJUNGAN = {
  semua: ['kunjungan'] as const,
  daftar: (filter: object) => ['kunjungan', 'daftar', filter] as const,
  /** Daftar IGD aktif (tiket 2.23). */
  igd: ['kunjungan', 'igd'] as const,
}

/**
 * Lama pasien di IGD sejak jam tiba, mis. "45 menit", "2 jam 5 menit". Waktu dari server; `sekarang` dari jam
 * perangkat hanya untuk tampilan (tanda > 6 jam tetap dari field `lebih_6_jam` server).
 */
export function lamaDiIgd(tibaPada: string | null | undefined, sekarang: Date): string {
  if (!tibaPada) return '—'
  const menit = Math.max(0, Math.floor((sekarang.getTime() - new Date(tibaPada).getTime()) / 60_000))
  const jam = Math.floor(menit / 60)
  const sisa = menit % 60
  if (jam === 0) return `${sisa} menit`
  return sisa === 0 ? `${jam} jam` : `${jam} jam ${sisa} menit`
}

export const LABEL_CARA_BAYAR: Record<CaraBayar, string> = {
  jkn: 'JKN (BPJS)',
  non_jkn: 'Umum / non-JKN',
}

export const LABEL_STATUS: Record<StatusKunjungan, string> = {
  terdaftar: 'Terdaftar',
  dilayani: 'Dilayani',
  selesai: 'Selesai',
  batal: 'Batal',
}

/** Nada lencana status di papan kunjungan. */
export const NADA_STATUS: Record<StatusKunjungan, 'ok' | 'info' | 'netral' | 'peringatan'> = {
  terdaftar: 'info',
  dilayani: 'ok',
  selesai: 'netral',
  batal: 'peringatan',
}
