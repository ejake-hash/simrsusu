import type { IsianButir } from '@/modules/usulan/tipe'
import type { AturanPerpindahan, FilterMatriks, PosAsalPerpindahan, PosTujuanPerpindahan, StatusAturan } from './tipe'

/** Label pos, kunci kueri, dan pembentuk butir usulan matriks perpindahan (tiket 2.26, kontrak v0.33.0). */

export const KUNCI_MATRIKS = {
  semua: ['matriks-perpindahan'] as const,
  daftar: (filter: FilterMatriks) => ['matriks-perpindahan', 'daftar', filter] as const,
}

export const POS_ASAL: readonly PosAsalPerpindahan[] = ['pendaftaran', 'poliklinik', 'igd', 'penunjang', 'kamar_operasi', 'icu_hcu', 'rawat_inap', 'farmasi', 'kasir']
export const POS_TUJUAN: readonly PosTujuanPerpindahan[] = ['poli_lain', 'penunjang', 'igd', 'kamar_operasi', 'icu_hcu', 'rawat_inap', 'rujuk_keluar', 'pulang', 'meninggal', 'aps', 'doa']

/** Sama dengan ButirMatriksPerpindahan::LABEL_POS di server (teks diff usulan). */
export const LABEL_POS: Record<PosAsalPerpindahan | PosTujuanPerpindahan, string> = {
  pendaftaran: 'Pendaftaran',
  poliklinik: 'Poliklinik',
  igd: 'IGD',
  penunjang: 'Penunjang',
  kamar_operasi: 'Kamar operasi',
  icu_hcu: 'ICU/HCU',
  rawat_inap: 'Rawat inap',
  farmasi: 'Farmasi',
  kasir: 'Kasir',
  poli_lain: 'Poli lain',
  rujuk_keluar: 'Rujuk keluar',
  pulang: 'Pulang',
  meninggal: 'Meninggal',
  aps: 'APS (pulang atas permintaan sendiri)',
  doa: 'DOA',
}

export const LABEL_STATUS_ATURAN: Record<StatusAturan, string> = { aktif: 'Aktif', nonaktif: 'Nonaktif' }

/**
 * Sel asal IGD yang aktif tetapi perintahnya tidak lewat layar Disposisi (FR-03-09): kontrak v0.33.0 menolaknya di POST
 * /disposisi dengan 422 `tujuan_bukan_disposisi`.
 */
export const LEWAT_MODUL_LAIN: Partial<Record<PosTujuanPerpindahan, string>> = {
  poli_lain: 'Perintah lewat konsul ke poli lain, bukan layar Disposisi.',
  penunjang: 'Perintah lewat order penunjang, bukan layar Disposisi.',
  icu_hcu: 'Perintah lewat disposisi rawat inap; ruang ICU/HCU dipilih saat alokasi kamar.',
  igd: 'Bukan disposisi IGD.',
}
export function keteranganModulLain(a: Pick<AturanPerpindahan, 'pos_asal' | 'pos_tujuan'>): string | null {
  return a.pos_asal === 'igd' ? (LEWAT_MODUL_LAIN[a.pos_tujuan] ?? null) : null
}

export const labelSel = (a: Pick<AturanPerpindahan, 'pos_asal' | 'pos_tujuan'>) => `${LABEL_POS[a.pos_asal]} → ${LABEL_POS[a.pos_tujuan]}`

/** Pola kode izin di CHECK `aturan_perpindahan_cek` (server tetap memeriksa izinnya ada di akses.izin). */
export const POLA_IZIN = /^[a-z0-9_]+(\.[a-z0-9_]+){2,3}$/

export type AksiMatriks = 'tambah' | 'ubah' | 'tutup'
export interface DrafButirMatriks {
  aksi: AksiMatriks
  pos_asal: PosAsalPerpindahan | ''
  pos_tujuan: PosTujuanPerpindahan | ''
  izin_perintah: string
  keterangan: string
  status: StatusAturan
  alasan_tutup: string
}

export const adalahButir = (x: IsianButir | Record<string, string>): x is IsianButir => 'objek_jenis' in x && 'nilai_baru' in x

/**
 * Draf dialog → satu butir usulan objek `aturan_perpindahan` (IsianPerubahanUsulan v0.33.0). `ubah` hanya membawa isian
 * yang berbeda dari sel saat ini. Galat dikembalikan sebagai peta kolom → pesan.
 */
export function bentukButirMatriks(d: DrafButirMatriks, sel: AturanPerpindahan | null): IsianButir | Record<string, string> {
  const e: Record<string, string> = {}
  const izin = d.izin_perintah.trim()
  const ket = d.keterangan.trim()
  if (d.aksi === 'tambah') {
    if (!d.pos_asal) e.pos_asal = 'Pilih pos asal.'
    if (!d.pos_tujuan) e.pos_tujuan = 'Pilih pos tujuan.'
    if (!POLA_IZIN.test(izin)) e.izin_perintah = 'Tulis kode izin, mis. igd.disposisi.input.'
    if (ket.length > 500) e.keterangan = 'Keterangan maksimal 500 karakter.'
    if (Object.keys(e).length) return e
    return { objek_jenis: 'aturan_perpindahan', aksi: 'tambah', objek_id: null, nilai_baru: { pos_asal: d.pos_asal, pos_tujuan: d.pos_tujuan, izin_perintah: izin, keterangan: ket || null } }
  }
  if (!sel) return { aksi: 'Pilih sel matriks yang diubah.' }
  if (d.aksi === 'tutup') {
    if (d.alasan_tutup.trim().length < 5) return { alasan_tutup: 'Isi alasan penutupan, minimal 5 karakter.' }
    return { objek_jenis: 'aturan_perpindahan', aksi: 'tutup', objek_id: sel.id, nilai_baru: { alasan: d.alasan_tutup.trim() } }
  }
  const nilai: Record<string, unknown> = {}
  if (izin !== sel.izin_perintah) {
    if (!POLA_IZIN.test(izin)) return { izin_perintah: 'Tulis kode izin, mis. igd.disposisi.input.' }
    nilai.izin_perintah = izin
  }
  if (ket !== (sel.keterangan ?? '')) {
    if (ket.length > 500) return { keterangan: 'Keterangan maksimal 500 karakter.' }
    nilai.keterangan = ket || null
  }
  if (d.status !== sel.status) nilai.status = d.status
  if (!Object.keys(nilai).length) return { aksi: 'Belum ada yang diubah dari sel ini.' }
  return { objek_jenis: 'aturan_perpindahan', aksi: 'ubah', objek_id: sel.id, nilai_baru: nilai }
}
