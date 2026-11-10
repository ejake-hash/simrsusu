import type { NamaIkon } from '@/design-system/ikon/ikon'
import { ApiError } from '@/shared/api/klien'
import type { AsalPermintaanKamar, PermintaanTempatTidur, RingkasTempatTidurDipesan, TempatTidurPeta } from './tipe'

/**
 * Aturan tampil Admisi rawat inap dan SPRI (tiket 2.28, kontrak v0.36.0; FR-08-03, FR-03-06). Urutan antrean dan lama
 * menunggu dari server; layar hanya memformat. Pilihan TT alokasi dari sumber yang sama dengan peta TT (`dapat_dipilih`).
 * Status selalu berteks dan berikon, bukan hanya warna (PRD 5.1 butir 4).
 */

export const KUNCI_ADMISI = {
  semua: ['rawat-inap', 'permintaan-tempat-tidur'] as const,
  antrean: (status: string) => ['rawat-inap', 'permintaan-tempat-tidur', status || 'aktif'] as const,
}

export const LABEL_ASAL: Record<AsalPermintaanKamar, string> = { igd: 'IGD', poliklinik: 'Poliklinik' }
export const labelAsal = (a: string) => LABEL_ASAL[a as AsalPermintaanKamar] ?? a

export type JenisLencana = 'ok' | 'peringatan' | 'bahaya' | 'info' | 'netral' | 'merek'
export interface TandaPermintaan {
  label: string
  jenis: JenisLencana
  ikon: NamaIkon
}

/** Status permintaan: menunggu = belum ada TT; dialokasikan = TT dipesan (label sama dengan status TT di peta). */
export function tandaPermintaan(p: Pick<PermintaanTempatTidur, 'status'>): TandaPermintaan {
  switch (p.status) {
    case 'menunggu':
      return { label: 'Menunggu TT', jenis: 'peringatan', ikon: 'jam' }
    case 'dialokasikan':
      return { label: 'TT dipesan', jenis: 'merek', ikon: 'penanda' }
    case 'ditempatkan':
      return { label: 'Sudah ditempatkan', jenis: 'ok', ikon: 'centang' }
    case 'batal':
      return { label: 'Batal', jenis: 'netral', ikon: 'tutup' }
    default:
      return { label: 'Status tidak dikenal', jenis: 'netral', ikon: 'info' }
  }
}

/** 0 = tanggal rencana belum tiba; selain itu "45 menit", "3 jam 5 menit", "2 hari 4 jam" (menit dari server). */
export function formatLamaMenunggu(menit: number): string {
  const m = Math.max(0, Math.floor(menit))
  if (m === 0) return 'Belum mulai (rencana nanti)'
  const hari = Math.floor(m / 1440)
  const jam = Math.floor((m % 1440) / 60)
  const sisa = m % 60
  if (hari > 0) return jam === 0 ? `${hari} hari` : `${hari} hari ${jam} jam`
  if (jam === 0) return `${sisa} menit`
  return sisa === 0 ? `${jam} jam` : `${jam} jam ${sisa} menit`
}

/** "CTHG-2-202-3 · Bangsal Melati (contoh)". */
export const teksTtDipesan = (tt: RingkasTempatTidurDipesan) => `${tt.kode} · ${tt.bangsal.nama}`

/** Tanggal hari ini di zona RS (WIB, sama dengan layar lain); server tetap memeriksa. */
export function hariIniRs(sekarang: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(sekarang)
}

/**
 * Pilihan TT alokasi dari peta: hanya `dapat_dipilih` (kosong siap, tidak ditutup, tidak dijadwalkan tutup). Kelas
 * diminta menyaring; permintaan IGD tanpa kelas menerima kelas apa pun. Urutan server peta tetap.
 */
export function pilihanTtAlokasi(peta: readonly TempatTidurPeta[], p: Pick<PermintaanTempatTidur, 'kelas_diminta'>): TempatTidurPeta[] {
  return peta.filter((tt) => tt.dapat_dipilih && tt.status === 'kosong_siap' && !tt.ditutup && (!p.kelas_diminta || tt.kelas_rawat.id === p.kelas_diminta.id))
}

/** Alokasi hanya untuk permintaan menunggu dan pemegang pendaftaran.admisi.input. */
export const bolehAlokasi = (p: Pick<PermintaanTempatTidur, 'status'>, izinInput: boolean) => izinInput && p.status === 'menunggu'

/** Galat yang membuat pilihan TT atau antrean basi: muat ulang dulu, baru pesan tampil. */
export const perluMuatUlang = (e: unknown) => e instanceof ApiError && (e.status === 409 || e.status === 404)

/** Pesan galat POST alokasi per kode kontrak. */
export function pesanGalatAlokasi(e: unknown, kodeTt: string): string {
  if (!(e instanceof ApiError)) return `TT ${kodeTt} gagal dialokasikan. Coba lagi.`
  switch (e.kode) {
    case 'tempat_tidur_ditutup':
      return `TT ${kodeTt} sudah ditutup atau dijadwalkan tutup. Pilihan TT sudah dimuat ulang; pilih TT lain.`
    case 'tempat_tidur_tidak_tersedia':
      return `TT ${kodeTt} sudah tidak kosong siap (mungkin baru dipesan petugas lain). Pilihan TT sudah dimuat ulang; pilih TT lain.`
    case 'permintaan_kamar_tidak_menunggu':
      return 'Permintaan ini sudah tidak menunggu (sudah dialokasikan atau dibatalkan). Antrean sudah dimuat ulang.'
    case 'kelas_tidak_sesuai':
      return `Kelas TT ${kodeTt} berbeda dengan kelas yang diminta. Pilih TT dengan kelas yang sama; titip atau naik kelas belum tersedia.`
  }
  if (e.status === 403) return `Anda tidak punya akses mengalokasikan TT ${kodeTt} (izin atau cakupan bangsal).`
  if (e.status === 404) return 'Permintaan kamar tidak ditemukan. Antrean sudah dimuat ulang.'
  if (e.status === 422) return `TT ${kodeTt} tidak ditemukan atau tidak sah. Pilih TT dari daftar terbaru.`
  return e.message
}

/** Pesan galat POST SPRI per kode kontrak. null = galat kolom (tampil di kolomnya). */
export function pesanGalatSpri(e: unknown): string | null {
  if (!(e instanceof ApiError)) return 'SPRI gagal disimpan. Coba lagi.'
  switch (e.kode) {
    case 'izin_praktik_tidak_berlaku':
      return 'SIP/SPK Anda tidak berlaku hari ini. SPRI hanya dari dokter dengan SIP berlaku.'
    case 'perpindahan_di_luar_matriks':
      return 'Rawat inap dari poliklinik tidak aktif di matriks perpindahan. Hubungi pengelola data induk.'
    case 'permintaan_kamar_sudah_ada':
      return 'Kunjungan ini sudah punya permintaan kamar yang masih berjalan. Lihat statusnya di Admisi rawat inap.'
    case 'status_tidak_sesuai':
      return 'SPRI hanya untuk kunjungan yang sedang diperiksa. Kunjungan ini sudah selesai, dibatalkan, atau belum dimulai.'
    case 'spri_igd_lewat_disposisi':
      return 'Rawat inap pasien IGD diputuskan lewat IGD → Disposisi.'
  }
  if (e.status === 403) return 'Akun Anda tidak memegang izin memberi SPRI untuk poli ini. SPRI hanya dari dokter yang diizinkan matriks perpindahan.'
  if (e.status === 404) return 'Kunjungan tidak ditemukan.'
  if (e.status === 422 && e.rincian.some((r) => typeof r.kolom === 'string' && ['alasan', 'kelas_rawat_id', 'bangsal_id', 'tanggal_rencana'].includes(r.kolom))) return null
  return e.message
}
