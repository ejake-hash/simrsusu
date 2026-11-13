import type { NamaIkon } from '@/design-system/ikon/ikon'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import type { JenisLencana } from './peta'
import type { ButirDaftarPeriksaPulang, IsianRencanaPulang, IsianResumeMedis, RencanaPulang, ResumeMedis, TempatTidurPeta, TujuanPulang } from './tipe'

/**
 * Aturan layar Rawat Inap → Pemulangan (tiket 2.40, kontrak v0.39.0; FR-05-01..05). Rencana pulang dan daftar periksa
 * non-klinis (`rawat_inap.rencana_pulang.*`); pulang dan resume klinis (`rawat_inap.pemulangan.*`, SIP/SPK diperiksa server).
 * Layar hanya menyembunyikan aksi yang tidak diizinkan; server tetap memutus.
 */
export const IZIN_PEMULANGAN = {
  rencanaLihat: 'rawat_inap.rencana_pulang.lihat',
  rencanaInput: 'rawat_inap.rencana_pulang.input',
  rencanaPerbaiki: 'rawat_inap.rencana_pulang.perbaiki',
  lihat: 'rawat_inap.pemulangan.lihat',
  input: 'rawat_inap.pemulangan.input',
  sahkan: 'rawat_inap.pemulangan.sahkan',
} as const

export const KUNCI_PEMULANGAN = {
  semua: ['rawat-inap', 'pemulangan'] as const,
  rencana: (kunjunganId: string) => ['rawat-inap', 'pemulangan', kunjunganId, 'rencana'] as const,
  resume: (kunjunganId: string) => ['rawat-inap', 'pemulangan', kunjunganId, 'resume'] as const,
  dataInduk: (status: string | null) => ['data-induk-daftar-periksa-pulang', status ?? 'semua'] as const,
}

/**
 * Jalur layar menurut izin: pemegang lihat pemulangan ke /rawat-inap/pemulangan (juga bentuk tautan tugas
 * RESUME_BELUM_TTE_48J), selain itu ke /rawat-inap/rencana-pulang agar peran yang hanya memegang rencana pulang tidak
 * berakhir di "Akses ditolak".
 */
export const jalurPemulangan = () => (boleh(IZIN_PEMULANGAN.lihat) ? '/rawat-inap/pemulangan' : '/rawat-inap/rencana-pulang')
export const rutePemulangan = (kunjunganId: string, path: string = jalurPemulangan()) => ({ path, query: { kunjungan: kunjunganId } })

/**
 * Tautan "Pemulangan" di peta TT: TT berpasien yang membawa id kunjungan RI (server mengirimnya bagi pemegang pindah ruang,
 * lihat pemulangan, atau lihat rencana pulang di cakupan bangsal TT) dan layar memegang salah satu izin lihat pemulangan.
 */
export const bolehBukaPemulangan = (tt: Pick<TempatTidurPeta, 'ditutup' | 'kunjungan_rawat_inap_id'>, izinLihat: boolean) =>
  izinLihat && !tt.ditutup && typeof tt.kunjungan_rawat_inap_id === 'string' && tt.kunjungan_rawat_inap_id !== ''

/** Dua penanda identitas (PRD 5.1 butir 9): "Nama, No. RM 00-12-34, lahir 5 Mei 1965". */
export const teksIdentitas = (p: { nama: string; no_rm: string; tanggal_lahir: string | null }) =>
  `${p.nama}, No. RM ${formatNoRm(p.no_rm)}, lahir ${formatTanggal(p.tanggal_lahir)}`

export const LABEL_TUJUAN: Record<TujuanPulang, string> = {
  pulang: 'Pulang',
  aps: 'Pulang atas permintaan sendiri (APS)',
  rujuk_keluar: 'Rujuk keluar',
  meninggal: 'Meninggal',
}
export const URUTAN_TUJUAN: readonly TujuanPulang[] = ['pulang', 'aps', 'rujuk_keluar', 'meninggal']

export interface TandaButir {
  label: string
  jenis: JenisLencana
  ikon: NamaIkon
}
/** Status butir selalu berteks + ikon. Butir wajib yang belum selesai bernada peringatan (menahan pulang tanpa alasan). */
export function tandaButir(b: Pick<ButirDaftarPeriksaPulang, 'status' | 'wajib'>): TandaButir {
  if (b.status === 'selesai') return { label: 'Selesai', jenis: 'ok', ikon: 'centang' }
  if (b.status === 'menunggu_kasir') return { label: 'Menunggu kasir (Tahap D)', jenis: 'info', ikon: 'jam' }
  return b.wajib ? { label: 'Belum, wajib', jenis: 'peringatan', ikon: 'peringatan' } : { label: 'Belum', jenis: 'netral', ikon: 'info' }
}

/** Butir yang butuh alasan saat pulang: wajib, sumber manual, belum selesai (kontrak POST /pulang). */
export const butirPerluAlasan = (daftar: readonly ButirDaftarPeriksaPulang[]) => daftar.filter((b) => b.wajib && b.sumber === 'manual' && b.status === 'belum')

export interface ButirAlasan {
  kode: string
  nama: string
}

/** Rincian 422 `daftar_periksa_belum_lengkap`: pesan per kode butir. */
export function galatButirPulang(e: unknown): Record<string, string> {
  if (!(e instanceof ApiError) || e.kode !== 'daftar_periksa_belum_lengkap') return {}
  const hasil: Record<string, string> = {}
  for (const r of e.rincian) {
    if (typeof r.kode === 'string') hasil[r.kode] = typeof r.pesan === 'string' ? r.pesan : 'Tulis alasan butir ini belum selesai.'
  }
  return hasil
}

/**
 * Daftar butir yang diberi kolom alasan: dari rencana yang dibaca, ditambah butir di rincian 422 yang belum ada (rencana basi
 * atau akun tanpa izin lihat rencana). Urutan: rencana dulu, lalu rincian server.
 */
export function gabungButirAlasan(dariRencana: readonly ButirAlasan[], e: unknown): ButirAlasan[] {
  const hasil = dariRencana.map((b) => ({ kode: b.kode, nama: b.nama }))
  if (e instanceof ApiError && e.kode === 'daftar_periksa_belum_lengkap') {
    for (const r of e.rincian) {
      if (typeof r.kode === 'string' && !hasil.some((b) => b.kode === r.kode)) hasil.push({ kode: r.kode, nama: typeof r.nama === 'string' ? r.nama : r.kode })
    }
  }
  return hasil
}

/** Syarat kirim dialog pulang. null = siap dikirim. */
export function kurangPulang(isi: { identitasAda: boolean; tujuan: TujuanPulang | ''; alasan: string; butir: readonly ButirAlasan[]; tanggapan: Record<string, string> }): string | null {
  if (!isi.identitasAda) return 'Identitas pasien belum termuat; muat ulang halaman sebelum memulangkan.'
  if (!isi.tujuan) return 'Pilih cara keluar.'
  if (isi.alasan.trim().length < 5) return 'Isi alasan atau catatan keluar, minimal 5 karakter.'
  const kosong = isi.butir.filter((b) => (isi.tanggapan[b.kode] ?? '').trim().length < 5)
  if (kosong.length) return `Tulis alasan untuk ${kosong.length} butir wajib yang belum selesai (minimal 5 karakter).`
  return null
}

/** Galat yang berarti kunjungan sudah berubah (sudah pulang, pindah, tidak ada): halaman dimuat ulang, dialog ditutup. */
export const pulangBasi = (e: unknown) => e instanceof ApiError && (e.status === 404 || e.kode === 'status_tidak_sesuai')

/** Pesan galat POST /pulang per kode kontrak. null = galat per butir atau per kolom (tampil di kolomnya). */
export function pesanGalatPulang(e: unknown): string | null {
  if (!(e instanceof ApiError)) return 'Pasien gagal dipulangkan. Coba lagi.'
  switch (e.kode) {
    case 'daftar_periksa_belum_lengkap':
      return 'Masih ada butir wajib yang belum selesai dan belum diberi alasan. Lengkapi alasan di bawah.'
    case 'status_tidak_sesuai':
      return 'Pasien ini sudah tidak dirawat di TT (sudah pulang atau belum ditempatkan). Halaman sudah dimuat ulang.'
    case 'perpindahan_di_luar_matriks':
      return 'Cara keluar ini tidak aktif di matriks perpindahan rawat inap. Hubungi pengelola data induk.'
    case 'izin_praktik_tidak_berlaku':
      return 'SIP/SPK Anda tidak berlaku hari ini. Pemulangan hanya oleh tenaga dengan SIP/SPK berlaku.'
    case 'pasien_digabung':
      return 'Rekam medis pasien ini sudah digabung ke RM lain. Muat ulang halaman.'
    case 'instalasi_belum_siap':
      return 'Instalasi belum siap melayani. Hubungi pengelola sistem.'
  }
  if (e.status === 403) return 'Anda tidak punya akses memulangkan pasien di bangsal ini (izin atau cakupan bangsal).'
  if (e.status === 404) return 'Kunjungan rawat inap tidak ditemukan. Halaman sudah dimuat ulang.'
  if (e.status === 422 && e.rincian.some((r) => r.kolom === 'alasan' || r.kolom === 'tujuan')) return null
  return e.message
}

// ── Rencana pulang ──

export interface IsianRencanaLayar {
  tanggal: string
  /** Teks isian angka; kosong = belum ditetapkan. */
  lamaRawat: string
  /** Centang butir manual di layar. */
  selesai: Record<string, boolean>
}

export const isianAwalRencana = (r: RencanaPulang, hariIni: string): IsianRencanaLayar => ({
  tanggal: r.rencana_pulang_pada ?? hariIni,
  lamaRawat: r.rencana_lama_rawat === null ? '' : String(r.rencana_lama_rawat),
  selesai: Object.fromEntries(r.daftar_periksa.filter((b) => b.sumber === 'manual').map((b) => [b.kode, b.status === 'selesai'])),
})

/** Validasi layar; server tetap memeriksa tanggal terhadap tanggal masuk TT. null = siap. */
export function kurangRencana(isi: IsianRencanaLayar): Record<string, string> {
  const e: Record<string, string> = {}
  if (!/^\d{4}-\d{2}-\d{2}$/.test(isi.tanggal)) e.rencana_pulang_pada = 'Isi tanggal rencana pulang.'
  const lama = String(isi.lamaRawat ?? '').trim()
  if (lama !== '' && (!/^\d+$/.test(lama) || Number(lama) < 1 || Number(lama) > 365)) e.rencana_lama_rawat = 'Isi bilangan bulat 1 sampai 365 hari, atau kosongkan.'
  return e
}

/** Isian PUT: tanggal, lama rawat (null = kosongkan), dan centang butir manual yang berubah dari yang dibaca. */
export function isianPutRencana(r: RencanaPulang, isi: IsianRencanaLayar): IsianRencanaPulang {
  const lama = String(isi.lamaRawat ?? '').trim()
  const daftar = r.daftar_periksa
    .filter((b) => b.sumber === 'manual' && (isi.selesai[b.kode] ?? false) !== (b.status === 'selesai'))
    .map((b) => ({ kode: b.kode, selesai: isi.selesai[b.kode] ?? false }))
  return { rencana_pulang_pada: isi.tanggal, rencana_lama_rawat: lama === '' ? null : Number(lama), ...(daftar.length ? { daftar_periksa: daftar } : {}) }
}

/** Ada perubahan yang belum disimpan dibanding rencana yang dibaca (rencana baru selalu dianggap perlu disimpan). */
export function rencanaBerubah(r: RencanaPulang, isi: IsianRencanaLayar): boolean {
  if (r.id === null) return true
  const put = isianPutRencana(r, isi)
  return put.rencana_pulang_pada !== r.rencana_pulang_pada || put.rencana_lama_rawat !== r.rencana_lama_rawat || (put.daftar_periksa?.length ?? 0) > 0
}

/** Pesan galat GET/PUT rencana pulang. null = galat kolom (tampil di kolomnya). */
export function pesanGalatRencana(e: unknown): string | null {
  if (!(e instanceof ApiError)) return 'Rencana pulang gagal disimpan. Coba lagi.'
  if (e.status === 412 || e.kode === 'versi_berubah') return 'Rencana pulang ini diubah petugas lain sejak Anda membukanya. Isian terbaru sudah dimuat; periksa lalu simpan lagi.'
  if (e.kode === 'if_match_wajib') return 'Rencana pulang sudah dibuat petugas lain. Isian terbaru sudah dimuat; periksa lalu simpan lagi.'
  if (e.kode === 'status_tidak_sesuai') return 'Rencana pulang hanya untuk pasien rawat inap yang masih dirawat.'
  if (e.status === 403) return 'Anda tidak punya akses rencana pulang di bangsal ini (izin atau cakupan bangsal).'
  if (e.status === 404) return 'Kunjungan rawat inap tidak ditemukan.'
  if (e.status === 422 && e.rincian.some((r) => r.kolom === 'rencana_pulang_pada' || r.kolom === 'rencana_lama_rawat')) return null
  return e.message
}

// ── Resume medis ──

export const KOLOM_ISIAN_RESUME: readonly { kunci: keyof IsianResumeMedis; label: string; petunjuk: string }[] = [
  { kunci: 'ringkasan_perjalanan', label: 'Ringkasan perjalanan penyakit', petunjuk: 'Perjalanan penyakit dan terapi selama dirawat.' },
  { kunci: 'kondisi_pulang', label: 'Kondisi saat pulang', petunjuk: 'Keadaan umum, tanda vital, dan status fungsional saat keluar.' },
  { kunci: 'instruksi_pulang', label: 'Instruksi pulang', petunjuk: 'Edukasi, diet, aktivitas, dan tanda bahaya untuk pasien.' },
  { kunci: 'obat_pulang', label: 'Obat pulang', petunjuk: 'Nama obat, dosis, dan cara pakai di rumah.' },
  { kunci: 'kontrol', label: 'Rencana kontrol', petunjuk: 'Poli, tanggal, atau syarat kontrol berikutnya.' },
]

export const isianKosong = (): IsianResumeMedis => ({ ringkasan_perjalanan: '', kondisi_pulang: '', instruksi_pulang: '', obat_pulang: '', kontrol: '' })
export const isianDariResume = (r: ResumeMedis | null): IsianResumeMedis =>
  r ? { ringkasan_perjalanan: r.isian_dpjp.ringkasan_perjalanan ?? '', kondisi_pulang: r.isian_dpjp.kondisi_pulang ?? '', instruksi_pulang: r.isian_dpjp.instruksi_pulang ?? '', obat_pulang: r.isian_dpjp.obat_pulang ?? '', kontrol: r.isian_dpjp.kontrol ?? '' } : isianKosong()

/** Isian yang berubah dari draf terakhir (string kosong = kosongkan). Resume baru: semua yang terisi. */
export function isianResumeBerubah(r: ResumeMedis | null, isi: IsianResumeMedis): Partial<IsianResumeMedis> {
  const lama = isianDariResume(r)
  const hasil: Partial<IsianResumeMedis> = {}
  for (const { kunci } of KOLOM_ISIAN_RESUME) {
    const baru = (isi[kunci] ?? '').trim()
    if (baru !== (lama[kunci] ?? '').trim()) hasil[kunci] = baru
  }
  return hasil
}

/** Ringkasan untuk TtdModal: isian DPJP yang terisi, dipotong agar modal tetap terbaca. */
export function ringkasResumeTtd(r: ResumeMedis): string {
  const teks = KOLOM_ISIAN_RESUME.flatMap(({ kunci, label }) => (r.isian_dpjp[kunci] ? [`${label}: ${r.isian_dpjp[kunci]}`] : [])).join(' · ')
  if (!teks) return 'Isian DPJP masih kosong; hanya potret sumber yang ikut ditandatangani.'
  return teks.length > 240 ? `${teks.slice(0, 240)}…` : teks
}

/** Kode galat TTE ditafsirkan TtdModal sendiri; galat lain ditafsirkan layar resume. */
export const KODE_TTE = ['pin_wajib', 'pin_salah', 'ttd_terkunci', 'ttd_belum_aktif'] as const

/** Pesan galat susun/sahkan resume selain kode TTE. */
export function pesanGalatResume(e: unknown, aksi: 'muat' | 'susun' | 'sahkan'): string {
  if (!(e instanceof ApiError)) return { muat: 'Resume medis gagal dimuat. Coba lagi.', susun: 'Resume gagal disusun. Coba lagi.', sahkan: 'Resume gagal disahkan. Coba lagi.' }[aksi]
  switch (e.kode) {
    case 'bukan_pemutus':
      return 'Resume medis hanya bisa disahkan DPJP pasien ini dengan akunnya sendiri.'
    case 'izin_praktik_tidak_berlaku':
      return 'SIP/SPK Anda tidak berlaku hari ini, jadi resume tidak bisa disusun atau disahkan.'
    case 'sudah_disahkan':
      return 'Resume ini sudah disahkan dan tidak bisa diubah lagi. Tampilan sudah dimuat ulang.'
    case 'versi_berubah':
      return 'Resume ini disusun ulang sejak Anda membukanya. Isi terbaru sudah dimuat; baca lagi sebelum mengesahkan.'
    case 'status_tidak_sesuai':
      return 'Resume medis hanya untuk kunjungan rawat inap yang tidak batal.'
  }
  if (e.status === 403) return 'Anda tidak punya akses resume medis di bangsal ini (izin atau cakupan bangsal).'
  if (e.status === 404) return 'Resume atau kunjungan tidak ditemukan. Muat ulang halaman.'
  return e.message
}

/** Galat yang berarti isi resume di layar sudah basi: muat ulang dulu. */
export const resumeBasi = (e: unknown) => e instanceof ApiError && (e.kode === 'sudah_disahkan' || e.kode === 'versi_berubah' || e.status === 404)

/** "3 hari" atau "belum ditetapkan". */
export const teksLamaRawat = (n: number | null) => (n === null ? 'belum ditetapkan' : `${n} hari`)

/** Label status kunjungan untuk kepala pasien. */
export const LABEL_STATUS_KUNJUNGAN: Record<string, string> = { terdaftar: 'Terdaftar', dilayani: 'Dirawat', selesai: 'Sudah pulang', batal: 'Batal' }
