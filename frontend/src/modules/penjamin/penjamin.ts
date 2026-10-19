import type { JenisPenjamin } from './tipe'

/** Kunci cache dan label bersama layar penjamin (tiket 2.1, kontrak v0.17.0). */
export const KUNCI_PENJAMIN = {
  semua: ['penjamin'] as const,
  daftar: (filter: object) => ['penjamin', 'daftar', filter] as const,
}

export const LABEL_JENIS_PENJAMIN: Record<JenisPenjamin, string> = {
  bpjs: 'BPJS Kesehatan',
  umum: 'Umum (bayar sendiri)',
  asuransi: 'Asuransi swasta',
  perusahaan: 'Perusahaan',
  jasa_raharja: 'Jasa Raharja',
  jamkesda: 'Jamkesda',
  sponsor_riset: 'Sponsor riset',
}
export const JENIS_PENJAMIN = Object.keys(LABEL_JENIS_PENJAMIN) as JenisPenjamin[]

/** Plafon string desimal → "Rp 1.500.000"; null = tanpa plafon tetap. */
export function formatPlafon(plafon: string | null): string {
  if (plafon === null) return 'Tanpa plafon'
  const [bulat = '0', sen] = plafon.split('.')
  const ribuan = bulat.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `Rp ${ribuan}${sen && Number(sen) ? `,${sen}` : ''}`
}
export const formatHari = (hari: number | null, kosong = 'Tidak dibatasi') => (hari === null ? kosong : `${hari} hari`)
