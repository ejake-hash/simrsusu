import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { ApiError } from '@/shared/api/klien'
import type { PrioritasPermintaan, StatusPermintaanItem } from '@/shared/api/permintaanPemeriksaan'
import type { BarisDaftarKerjaLab, ButirDaftarKerjaLab, LokasiPengambilanSpesimen } from './tipe'

/** Aturan layar Daftar kerja lab (tiket 2.30, kontrak v0.40.0). Data sintetis di uji. */
export const KUNCI_LAB = {
  semua: ['lab', 'daftar-kerja'] as const,
  daftar: (prioritas: PrioritasPermintaan) => ['lab', 'daftar-kerja', prioritas] as const,
}

export const LABEL_LOKASI_PENGAMBILAN: Record<LokasiPengambilanSpesimen, string> = {
  instalasi: 'Di instalasi laboratorium',
  samping_tt: 'Di samping tempat tidur',
  kamar_operasi: 'Di kamar operasi',
}

/** Nada lencana status butir: status tidak hanya warna (Lencana memberi ikon dan teks). */
export const NADA_STATUS_ITEM: Record<StatusPermintaanItem, 'netral' | 'info' | 'ok'> = {
  dipesan: 'netral',
  sampel_diambil: 'info',
  diterima_lab: 'ok',
  batal: 'netral',
}

const LABEL_JENIS_KUNJUNGAN = { poliklinik: 'Poliklinik', igd: 'IGD', rawat_inap: 'Rawat inap' } as const

/** Asal order: jenis kunjungan pemesan + nama poli bila ada. */
export function teksAsal(b: BarisDaftarKerjaLab): string {
  const jenis = b.asal.jenis_kunjungan ? LABEL_JENIS_KUNJUNGAN[b.asal.jenis_kunjungan] : 'Asal tidak diketahui'
  return b.asal.nama ? `${jenis} · ${b.asal.nama}` : jenis
}

/** Lokasi pasien saat ini menurut server: TT aktif → IGD → poli; null = "Tidak diketahui". */
export function teksLokasi(b: BarisDaftarKerjaLab): string {
  const l = b.lokasi_saat_ini
  if (!l) return 'Tidak diketahui'
  if (l.jenis === 'rawat_inap') return [l.nama ?? 'Rawat inap', l.tempat_tidur ? `TT ${l.tempat_tidur}` : null].filter(Boolean).join(' · ')
  if (l.jenis === 'igd') return l.nama ? `IGD · ${l.nama}` : 'IGD'
  return l.nama ?? 'Poliklinik'
}

/** Dua penanda identitas (PRD 5.1 butir 9): nama, No. RM, tanggal lahir. */
export function identitasPasien(b: Pick<BarisDaftarKerjaLab, 'pasien'>): string {
  return `${b.pasien.nama}, No. RM ${formatNoRm(b.pasien.no_rm)}, lahir ${formatTanggal(b.pasien.tanggal_lahir)}`
}

export const bolehAmbil = (i: ButirDaftarKerjaLab) => i.dapat?.ambil === true && i.status === 'dipesan'
export const bolehTerima = (i: ButirDaftarKerjaLab) => i.dapat?.terima === true && i.status === 'sampel_diambil' && i.spesimen !== null

/** Jenis spesimen 2–100 karakter (kontrak). null = isian sudah benar. */
export function kurangJenisSpesimen(jenis: string): string | null {
  const t = jenis.trim()
  if (t.length < 2) return 'Isi jenis spesimen, minimal 2 karakter.'
  if (t.length > 100) return 'Jenis spesimen paling banyak 100 karakter.'
  return null
}

/** 409/404 = data di layar sudah basi: daftar dimuat ulang. */
export const perluMuatUlang = (e: unknown) => e instanceof ApiError && (e.status === 409 || e.status === 404)

/** Pesan galat aksi lab: 409 dan 404 memakai pesan server ditambah keterangan daftar dimuat ulang; 403 = akses ditolak. */
export function pesanGalatLab(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  if (e.status === 403) return `Akses ditolak: ${e.message} Minta izin laboratorium cakupan RS lewat Pengaturan peran.`
  if (e.status === 409 || e.status === 404) return `${e.message} Daftar kerja sudah dimuat ulang.`
  return e.message
}
