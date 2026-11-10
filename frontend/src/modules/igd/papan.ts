import type { NamaIkon } from '@/design-system/ikon/ikon'
import { ApiError } from '@/shared/api/klien'
import { LABEL_STATUS_DISPOSISI } from './disposisi'
import { waktuTriase } from './triase'
import type { BarisPapanIgd, MetaPapanIgd, RingkasAreaIgd } from './tipePapan'

/**
 * Aturan tampil papan pasien IGD (tiket 2.25, kontrak v0.34.0; FR-03-03/04/05, FR-00-06). Urutan, menit, dan tanda waktu
 * dari server; layar tidak mengurutkan ulang dan tidak menghitung sendiri. Setiap tanda tampil dengan teks dan ikon,
 * bukan hanya warna (PRD 5.1 butir 4).
 */

/** Polling papan (K1, sampai Reverb): 15 detik, jeda saat tab tidak terlihat. */
export const INTERVAL_PAPAN = 15_000

export const KUNCI_PAPAN = {
  semua: ['igd', 'papan'] as const,
  papan: (areaId: string | null) => ['igd', 'papan', areaId ?? 'semua'] as const,
}

export type JenisLencana = 'ok' | 'peringatan' | 'bahaya' | 'info' | 'netral'
export interface TandaTampil {
  label: string
  jenis: JenisLencana
  ikon: NamaIkon
}

/** "45 menit", "2 jam", "6 jam 1 menit". */
export function formatMenit(menit: number): string {
  const m = Math.max(0, Math.floor(menit))
  const jam = Math.floor(m / 60)
  const sisa = m % 60
  if (jam === 0) return `${sisa} menit`
  return sisa === 0 ? `${jam} jam` : `${jam} jam ${sisa} menit`
}

/** Tanda waktu tanggap: menunggu dokter → lewat batas; sesudah dokter mulai mengisi asesmen awal: tercapai/terlambat. */
export function tandaTanggap(b: Pick<BarisPapanIgd, 'tanggap'>): TandaTampil {
  const t = b.tanggap
  const menit = t.menit === null ? '' : `, ${formatMenit(t.menit)}`
  switch (t.tanda) {
    case 'menunggu':
      return { label: 'Menunggu dokter', jenis: 'info', ikon: 'jam' }
    case 'lewat_batas':
      return { label: 'Lewat batas tanggap', jenis: 'bahaya', ikon: 'kritis' }
    case 'tercapai':
      return { label: `Tercapai${menit}`, jenis: 'ok', ikon: 'centang' }
    case 'terlambat':
      return { label: `Terlambat${menit}`, jenis: 'peringatan', ikon: 'peringatan' }
    // Nilai baru dari server (kontrak lebih baru) tidak boleh membuat tabel gagal tampil.
    default:
      return { label: 'Tanda tanggap tidak dikenal', jenis: 'netral', ikon: 'info' }
  }
}

/** Tanda lama di IGD (FR-00-06, 7.00.2). null = aman (tanpa lencana; lama tetap tertulis). */
export function tandaLamaIgd(b: Pick<BarisPapanIgd, 'tanda_lama_igd'>): TandaTampil | null {
  switch (b.tanda_lama_igd) {
    case 'aman':
      return null
    case 'mendekati_6_jam':
      return { label: 'Mendekati 6 jam', jenis: 'peringatan', ikon: 'jam' }
    case 'lebih_6_jam':
      return { label: 'Lebih dari 6 jam', jenis: 'bahaya', ikon: 'peringatan' }
    default:
      return { label: 'Tanda lama tidak dikenal', jenis: 'netral', ikon: 'info' }
  }
}

/** Jam tiba; tanggal (zona RS) ikut disebut bila bukan hari ini, sama dengan riwayat triase/disposisi. */
export const waktuTiba = (b: Pick<BarisPapanIgd, 'tiba_pada'>, sekarang: Date) => waktuTriase(b.tiba_pada, sekarang)

/** "Menunggu kamar sejak pukul 10.03 WIB" (tanggal disebut bila bukan hari ini). null = tidak ada. */
export function teksDisposisiMenunggu(b: Pick<BarisPapanIgd, 'disposisi_menunggu'>, sekarang: Date): string | null {
  const d = b.disposisi_menunggu
  if (!d) return null
  const waktu = waktuTriase(d.sejak, sekarang)
  return `${LABEL_STATUS_DISPOSISI[d.status]} sejak ${waktu.charAt(0).toLowerCase()}${waktu.slice(1)}`
}

/** Uraian batas yang dipakai server untuk tanda baris ini. */
export function uraianAturan(a: MetaPapanIgd['aturan']): string {
  const tanggap = `Batas tanggap ${formatMenit(a.batas_tanggap_menit)} sejak tiba sampai dokter mulai mengisi asesmen awal.`
  const enam = a.peringatan_lama_igd_menit === null
    ? 'Tanda mendekati 6 jam tidak dipakai.'
    : `Tanda mendekati 6 jam muncul ${formatMenit(a.peringatan_lama_igd_menit)} sebelum 6 jam.`
  return `${tanggap} ${enam}`
}

/**
 * Pilihan area per baris: area aktif dari `meta.area`; area penempatan yang sudah nonaktif tetap tampil (tidak bisa
 * dipilih lagi) agar pilihan tidak diam-diam berubah.
 */
export function pilihanArea(b: Pick<BarisPapanIgd, 'area'>, aktif: readonly RingkasAreaIgd[]): { area: RingkasAreaIgd; nonaktif: boolean }[] {
  const daftar = aktif.map((area) => ({ area, nonaktif: false }))
  if (b.area && !aktif.some((a) => a.id === b.area!.id)) daftar.push({ area: b.area, nonaktif: true })
  return daftar
}

/** Pesan galat PUT area-igd per kode kontrak. */
export function pesanGalatTempatkan(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Penempatan gagal disimpan. Coba lagi.'
  if (e.status === 422) return 'Area ini tidak lagi aktif. Pilih area lain dari papan terbaru.'
  if (e.status === 409 && e.kode === 'status_tidak_sesuai') return 'Pasien ini tidak lagi aktif di IGD. Periksa papan terbaru.'
  if (e.status === 404) return 'Kunjungan tidak ditemukan. Periksa papan terbaru.'
  return e.message
}
