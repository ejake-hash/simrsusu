import type { CaraBayar, StatusKunjungan } from './tipeKunjungan'

/** Kunci cache dan label bersama layar Pendaftaran kunjungan poli (tiket 2.6, kontrak v0.20.0). */
export const KUNCI_KUNJUNGAN = {
  semua: ['kunjungan'] as const,
  daftar: (filter: object) => ['kunjungan', 'daftar', filter] as const,
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
