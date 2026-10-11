import type { PersetujuanAkses, StatusPermintaan, StatusPersetujuan } from './tipePersetujuan'

/** Label dan teks bersama persetujuan/permintaan akses (tiket 1.2d). */
export const LABEL_STATUS_PERSETUJUAN: Record<StatusPersetujuan, string> = {
  menunggu: 'Menunggu',
  disetujui: 'Disetujui',
  ditolak: 'Ditolak',
  kedaluwarsa: 'Kedaluwarsa',
  dibatalkan: 'Dibatalkan',
}
export const PIL_STATUS_PERSETUJUAN: Record<StatusPersetujuan, string> = {
  menunggu: 'pil-peringatan',
  disetujui: 'pil-ok',
  ditolak: 'pil-bahaya',
  kedaluwarsa: 'pil-netral',
  dibatalkan: 'pil-netral',
}
export const LABEL_STATUS_PERMINTAAN: Record<StatusPermintaan, string> = {
  menunggu: 'Menunggu',
  ditindaklanjuti: 'Ditindaklanjuti',
  ditolak: 'Ditolak',
}

/** "menunggu persetujuan Superadmin (PSA-2026-000012)"; grup berpenyetuju banyak disebut jumlahnya. */
export function teksMenunggu(p: PersetujuanAkses): string {
  const siapa = p.grup.jumlah > 1 ? `${p.grup.jumlah} penyetuju` : (p.penyetuju_peran?.nama ?? 'pemilik data')
  return `menunggu persetujuan ${siapa} (${p.nomor})`
}
