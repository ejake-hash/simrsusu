import type { AlasanCabut, PerangkatTepercaya } from './tipePerangkat'

/** Teks status perangkat tepercaya (tiket 1.13). Status selalu teks + ikon, bukan warna saja. */
export const LABEL_ALASAN_CABUT: Record<AlasanCabut, string> = {
  dicabut_pemilik: 'dicabut pemilik akun',
  dicabut_admin: 'dicabut admin',
  sandi_diganti: 'sandi diganti',
  sandi_direset: 'sandi direset admin',
  mfa_direset: 'MFA direset',
  akun_nonaktif: 'akun dinonaktifkan',
}

export function labelStatus(p: PerangkatTepercaya): string {
  if (p.status === 'aktif') return 'Aktif'
  if (p.status === 'kedaluwarsa') return 'Kedaluwarsa'
  return p.alasan_cabut ? `Dicabut: ${LABEL_ALASAN_CABUT[p.alasan_cabut]}` : 'Dicabut'
}

export const KUNCI_PERANGKAT = {
  saya: (status: string) => ['perangkat', 'saya', status] as const,
  akun: (akunId: string, status: string) => ['perangkat', 'akun', akunId, status] as const,
  kebijakan: ['kebijakan-keamanan'] as const,
}
