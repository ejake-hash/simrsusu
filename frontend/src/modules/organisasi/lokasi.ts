import type { AturanJenisKelamin, FungsiRuang, JenisLokasi, Lokasi, StatusLokasi, StatusOperasionalTt } from './tipeLokasi'

/** Kunci cache, label, dan aturan bersama lokasi fisik, ruang, dan TT (tiket 1.8b, kontrak v0.5.0). */
export const KUNCI_LOKASI = {
  semua: ['organisasi', 'lokasi'] as const,
  anak: (induk: string | null) => ['organisasi', 'lokasi', 'anak', induk ?? 'akar'] as const,
  rinci: (id: string) => ['organisasi', 'lokasi', 'rinci', id] as const,
  cari: (kueri: string) => ['organisasi', 'lokasi', 'cari', kueri] as const,
  kamar: (id: string) => ['organisasi', 'lokasi', 'kamar', id] as const,
  ttKamar: (kamarId: string) => ['organisasi', 'lokasi', 'tt-kamar', kamarId] as const,
  tt: (id: string) => ['organisasi', 'lokasi', 'tt', id] as const,
  bangsal: ['organisasi', 'bangsal'] as const,
  daftarBangsal: (filter: object) => ['organisasi', 'bangsal', 'daftar', filter] as const,
  bangsalAktif: ['organisasi', 'bangsal', 'aktif'] as const,
  kamarMelebihi: ['organisasi', 'bangsal', 'kamar-melebihi'] as const,
  kelas: ['organisasi', 'kelas-rawat'] as const,
  daftarKelas: (filter: object) => ['organisasi', 'kelas-rawat', 'daftar', filter] as const,
  kelasAktif: ['organisasi', 'kelas-rawat', 'aktif'] as const,
  poli: ['organisasi', 'poliklinik'] as const,
  daftarPoli: (filter: object) => ['organisasi', 'poliklinik', 'daftar', filter] as const,
}

export const LABEL_JENIS_LOKASI: Record<JenisLokasi, string> = {
  situs: 'Situs',
  gedung: 'Gedung',
  sayap: 'Sayap',
  lantai: 'Lantai',
  ruang: 'Ruang',
  tempat_tidur: 'Tempat tidur',
}
export const LABEL_FUNGSI: Record<FungsiRuang, string> = {
  rawat_inap: 'Rawat inap',
  rawat_jalan: 'Rawat jalan',
  igd: 'IGD',
  kamar_operasi: 'Kamar operasi',
  intensif: 'Rawat intensif',
  penunjang: 'Penunjang',
  administrasi: 'Administrasi',
  gudang: 'Gudang',
  lainnya: 'Lainnya',
}
export const DAFTAR_FUNGSI = Object.entries(LABEL_FUNGSI) as [FungsiRuang, string][]
export const LABEL_STATUS_LOKASI: Record<StatusLokasi, string> = { aktif: 'Aktif', renovasi: 'Renovasi', ditutup: 'Ditutup' }
export const PIL_STATUS_LOKASI: Record<StatusLokasi, string> = { aktif: 'pil-ok', renovasi: 'pil-peringatan', ditutup: 'pil-netral' }
export const LABEL_STATUS_TT: Record<StatusOperasionalTt, string> = { belum_berlaku: 'Belum berlaku', aktif: 'Aktif', akan_ditutup: 'Akan ditutup', ditutup: 'Ditutup' }
export const PIL_STATUS_TT: Record<StatusOperasionalTt, string> = { belum_berlaku: 'pil-info', aktif: 'pil-ok', akan_ditutup: 'pil-peringatan', ditutup: 'pil-netral' }
export const LABEL_JENIS_KELAMIN: Record<AturanJenisKelamin, string> = { campur: 'Campur', laki_laki: 'Laki-laki', perempuan: 'Perempuan' }

/** Jenis anak yang boleh per jenis induk (kontrak v0.5.0); TT dikelola lewat panel kamar, bukan "tambah anak". */
export const JENIS_ANAK: Record<JenisLokasi | 'akar', Exclude<JenisLokasi, 'tempat_tidur'>[]> = {
  akar: ['situs', 'gedung'],
  situs: ['gedung'],
  gedung: ['sayap', 'lantai'],
  sayap: ['lantai'],
  lantai: ['ruang'],
  ruang: [],
  tempat_tidur: [],
}

/** Urutan alami untuk tampilan (kode_lengkap server leksikografis: 10 sebelum 2). */
export const urutAlami = (a: Pick<Lokasi, 'kode_lengkap'>, b: Pick<Lokasi, 'kode_lengkap'>) =>
  a.kode_lengkap.localeCompare(b.kode_lengkap, 'id', { numeric: true, sensitivity: 'base' })

/** Hari ini menurut jam perangkat (YYYY-MM-DD) untuk nilai bawaan isian tanggal; server memakai zona RS. */
export function hariIniLokal(tambahHari = 0): string {
  const t = new Date()
  t.setDate(t.getDate() + tambahHari)
  return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`
}
