import type { FilterPegawai, JenisIzinPraktik, Profesi, StatusIzinPraktik, StatusKewenangan, StatusPegawai } from './tipe'

/** Kunci kueri, label, dan kelas pil layar SDM (tiket 1.8d). */

export const KUNCI_SDM = {
  semua: ['sdm'] as const,
  pegawai: ['sdm', 'pegawai'] as const,
  daftarPegawai: (f: FilterPegawai) => ['sdm', 'pegawai', 'daftar', f] as const,
  rinciPegawai: (id: string) => ['sdm', 'pegawai', 'rinci', id] as const,
  izinPraktik: (id: string) => ['sdm', 'pegawai', 'izin-praktik', id] as const,
  kewenangan: (id: string) => ['sdm', 'pegawai', 'kewenangan', id] as const,
}

export const LABEL_PROFESI: Record<Profesi, string> = {
  dokter: 'Dokter',
  dokter_gigi: 'Dokter gigi',
  perawat: 'Perawat',
  bidan: 'Bidan',
  apoteker: 'Apoteker',
  tenaga_kesehatan_lain: 'Tenaga kesehatan lain',
  non_kesehatan: 'Non-kesehatan',
}

export const LABEL_STATUS_PEGAWAI: Record<StatusPegawai, string> = { aktif: 'Aktif', akan_keluar: 'Akan keluar', keluar: 'Keluar' }
export const PIL_STATUS_PEGAWAI: Record<StatusPegawai, string> = { aktif: 'pil-ok', akan_keluar: 'pil-peringatan', keluar: 'pil-netral' }

export const LABEL_JENIS_IZIN: Record<JenisIzinPraktik, string> = { str: 'STR', sip: 'SIP', sik: 'SIK', spk: 'SPK' }

export const LABEL_STATUS_IZIN: Record<StatusIzinPraktik, string> = {
  belum_berlaku: 'Belum berlaku',
  berlaku: 'Berlaku',
  akan_habis: 'Akan habis',
  habis: 'Habis',
  batal: 'Dibatalkan',
}
export const PIL_STATUS_IZIN: Record<StatusIzinPraktik, string> = {
  belum_berlaku: 'pil-info',
  berlaku: 'pil-ok',
  akan_habis: 'pil-peringatan',
  habis: 'pil-bahaya',
  batal: 'pil-netral',
}

export const LABEL_STATUS_RKK: Record<StatusKewenangan, string> = { belum_berlaku: 'Belum berlaku', berlaku: 'Berlaku', habis: 'Habis', batal: 'Dibatalkan' }
export const PIL_STATUS_RKK: Record<StatusKewenangan, string> = { belum_berlaku: 'pil-info', berlaku: 'pil-ok', habis: 'pil-bahaya', batal: 'pil-netral' }

/** Profesi yang biasanya punya izin praktik dan RKK (bagian ditampilkan untuk semua; ini hanya urutan pilihan). */
export const PIL_PROFESI = Object.entries(LABEL_PROFESI) as [Profesi, string][]

/** Tanggal hari ini di zona perangkat (YYYY-MM-DD) untuk pesan awal; server tetap yang memutuskan (zona RS). */
export function hariIniLokal(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function ukuranBerkas(bait: number): string {
  if (bait < 1024) return `${bait} B`
  if (bait < 1024 * 1024) return `${Math.round(bait / 1024)} KB`
  return `${(bait / 1024 / 1024).toFixed(1).replace('.', ',')} MB`
}
