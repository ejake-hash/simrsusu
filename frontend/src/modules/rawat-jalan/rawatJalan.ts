import { ApiError } from '@/shared/api/klien'
import type { JenisAlergi, JenisDiagnosis, ProfesiCatatan, StatusAsesmen, TahapWorklist, TatananCatatan, TingkatAlergi } from './tipe'

export const KUNCI_WORKLIST = {
  semua: ['rawat-jalan'] as const,
  daftar: (filter: object) => ['rawat-jalan', 'worklist', filter] as const,
  asesmen: (kunjunganId: string) => ['rawat-jalan', 'asesmen', kunjunganId] as const,
  alergi: (pasienId: string) => ['rawat-jalan', 'alergi', pasienId] as const,
  cppt: ['rawat-jalan', 'cppt'] as const,
  diagnosis: (kunjunganId: string) => ['rawat-jalan', 'diagnosis', kunjunganId] as const,
  ringkasan: (pasienId: string) => ['rawat-jalan', 'ringkasan', pasienId] as const,
}

export const LABEL_TAHAP: Record<TahapWorklist, string> = { perawat: 'Menunggu perawat', dokter: 'Menunggu dokter', selesai: 'Selesai' }
export const NADA_TAHAP: Record<TahapWorklist, 'info' | 'ok' | 'netral'> = { perawat: 'info', dokter: 'ok', selesai: 'netral' }
export const LABEL_ASESMEN: Record<StatusAsesmen, string> = { draf: 'Draf', selesai: 'Selesai' }
export const LABEL_JENIS_ALERGI: Record<JenisAlergi, string> = { obat: 'Obat', makanan: 'Makanan', lingkungan: 'Lingkungan', lainnya: 'Lainnya' }
export const LABEL_PROFESI: Record<ProfesiCatatan, string> = { dokter: 'Dokter', perawat: 'Perawat', bidan: 'Bidan', apoteker: 'Apoteker' }
export const LABEL_TATANAN: Record<TatananCatatan, string> = { rj: 'Rawat jalan', igd: 'IGD', ri: 'Rawat inap', ok: 'Kamar operasi', icu: 'ICU', hd: 'Hemodialisis' }
export const LABEL_JENIS_DIAGNOSIS: Record<JenisDiagnosis, string> = { utama: 'Utama', sekunder: 'Sekunder' }
export const LABEL_TINGKAT: Record<TingkatAlergi, string> = { ringan: 'Ringan', sedang: 'Sedang', berat: 'Berat' }

const MENIT = 60_000

/** "7 menit", "1 jam 5 menit"; di bawah semenit "kurang dari 1 menit". */
export function lamaMenunggu(sejak: string, sekarang: number): string {
  const menit = Math.max(0, Math.floor((sekarang - new Date(sejak).getTime()) / MENIT))
  if (menit < 1) return 'kurang dari 1 menit'
  if (menit < 60) return `${menit} menit`
  const jam = Math.floor(menit / 60)
  return menit % 60 ? `${jam} jam ${menit % 60} menit` : `${jam} jam`
}

/** Sisa waktu perbaikan (B-14): "1 hari 4 jam", "35 menit", null bila batas sudah lewat. */
export function sisaPerbaikan(batas: string | null, sekarang: number): string | null {
  if (!batas) return null
  const menit = Math.floor((new Date(batas).getTime() - sekarang) / MENIT)
  if (menit <= 0) return null
  const hari = Math.floor(menit / 1440)
  const jam = Math.floor((menit % 1440) / 60)
  if (hari) return jam ? `${hari} hari ${jam} jam` : `${hari} hari`
  return jam ? `${jam} jam ${menit % 60} menit` : `${menit} menit`
}

/** Pesan galat asesmen/alergi: pesan server ditambah petunjuk langkah berikutnya dari kodenya. */
export function pesanKlinis(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  switch (e.kode) {
    case 'batas_perbaikan_lewat':
      return 'Batas perbaikan 2 × 24 jam sudah lewat, asesmen ini tidak bisa diubah lagi. Koreksi dicatat lewat adendum pada CPPT.'
    case 'alergi_sudah_tercatat':
      return 'Alergi aktif dengan zat yang sama sudah tercatat. Periksa daftar di atas.'
    case 'asesmen_sudah_ada':
      return 'Asesmen kunjungan ini sudah dimulai petugas lain. Muat ulang untuk melihatnya.'
    case 'status_tidak_sesuai':
      return `${e.message} Muat ulang untuk melihat keadaan terbaru.`
    default:
      if (e.status === 412 || e.status === 428) return 'Asesmen sudah berubah sejak Anda membukanya. Muat ulang, lalu ulangi perubahan.'
      return e.message
  }
}

/** Pesan galat CPPT (tulis, ubah, adendum; galat TTE ditafsirkan TtdModal). */
export function pesanCppt(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  switch (e.kode) {
    case 'batas_perbaikan_lewat':
      return 'Batas perbaikan 2 × 24 jam sudah lewat. Koreksi dicatat lewat adendum.'
    case 'masih_bisa_diperbaiki':
      return 'Catatan ini masih dalam batas perbaikan 2 × 24 jam. Perbaiki isinya, bukan menambah adendum.'
    case 'profesi_tidak_dikenali':
      return `${e.message} Hubungi bagian SDM untuk melengkapi profesi pada data pegawai Anda.`
    case 'validasi_gagal':
      return e.rincian.some((r) => r.kolom === 'label') ? 'Label sensitivitas tidak dikenal atau sudah nonaktif.' : e.message
    default:
      if (e.status === 412 || e.status === 428) return 'Catatan sudah berubah sejak Anda membukanya. Muat ulang linimasa, lalu ulangi.'
      return e.message
  }
}

/** Pesan galat pemeriksaan dokter (mulai, diagnosis, selesai): kode server diterjemahkan ke langkah berikutnya. */
export function pesanPemeriksaan(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  switch (e.kode) {
    case 'paket_referensi_belum_dimuat':
      return 'Paket ICD-10 RS belum dimuat. Admin data induk memuatnya di Pengaturan → Penyiapan instalasi, langkah 4.'
    case 'diagnosis_utama_sudah_ada':
      return 'Kunjungan ini sudah punya diagnosis utama. Jadikan yang lama sekunder atau batalkan dulu.'
    case 'diagnosis_sudah_tercatat':
      return 'Kode ini sudah tercatat di kunjungan ini.'
    case 'syarat_selesai_belum_lengkap':
      return `Kunjungan belum bisa diselesaikan: ${e.rincian.map((r) => (r.kolom === 'diagnosis_utama' ? 'diagnosis utama belum dicatat' : r.kolom === 'cppt_dokter_draf' ? `${String(r.jumlah ?? '')} CPPT dokter masih draf, sahkan dulu` : String(r.pesan ?? ''))).join('; ')}.`
    case 'validasi_gagal':
      return e.rincian.some((r) => r.kolom === 'kode') ? 'Kode tidak ada di paket ICD-10 RS. Pilih dari hasil pencarian.' : e.message
    case 'status_tidak_sesuai':
      return `${e.message} Muat ulang untuk melihat keadaan terbaru.`
    default:
      if (e.status === 412 || e.status === 428) return 'Data sudah berubah sejak Anda membukanya. Muat ulang, lalu ulangi.'
      return e.message
  }
}

/** Isian formulir SOAP polos + label ('' = kosong). */
export interface IsianSoap {
  subjektif: string
  objektif: string
  asesmen: string
  plan: string
  label: string
}
export const soapKosong = (): IsianSoap => ({ subjektif: '', objektif: '', asesmen: '', plan: '', label: '' })
export const adaIsi = (f: IsianSoap) => [f.subjektif, f.objektif, f.asesmen, f.plan].some((v) => v.trim())
const teks = (v: string) => v.trim() || null
/** Isian → badan permintaan: bagian kosong dikirim null, label kosong null. */
export const isiSoap = (f: IsianSoap) => ({ subjektif: teks(f.subjektif), objektif: teks(f.objektif), asesmen: teks(f.asesmen), plan: teks(f.plan), label: f.label || null })
