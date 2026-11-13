import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { ApiError } from '@/shared/api/klien'
import type { PrioritasPermintaan } from '@/shared/api/permintaanPemeriksaan'
import type { BarisDaftarKerjaLab, ButirDaftarKerjaLab, HasilLab, IsianHasilLab, LokasiPengambilanSpesimen, NilaiHasilLab, PenandaHasilLab, TahapDaftarKerjaLab } from './tipe'

/** Aturan layar Daftar kerja lab (tiket 2.30, kontrak v0.40.0) dan hasil lab (tiket 2.31, v0.41.0). Data sintetis di uji. */
export const KUNCI_LAB = {
  semua: ['lab', 'daftar-kerja'] as const,
  daftar: (prioritas: PrioritasPermintaan, tahap: TahapDaftarKerjaLab = 'spesimen') => ['lab', 'daftar-kerja', tahap, prioritas] as const,
  linimasa: (episodeId: string) => ['lab', 'hasil-episode', episodeId] as const,
}

export const LABEL_TAHAP: Record<TahapDaftarKerjaLab, string> = { spesimen: 'Spesimen', hasil: 'Hasil', otorisasi: 'Otorisasi' }

/**
 * Tahap yang tampil menurut izin sesi (server tetap penentu lewat `dapat`): Spesimen = ambil (input) atau terima
 * (verifikasi); Hasil = input atau validasi analis (sahkan); Otorisasi = Sp.PK (setujui). Hanya izin lihat → Spesimen
 * sebagai daftar baca.
 */
export function tahapTersedia(boleh: (kode: string) => boolean): TahapDaftarKerjaLab[] {
  const ada = (aksi: string) => boleh(`penunjang.laboratorium.${aksi}`)
  const tahap: TahapDaftarKerjaLab[] = []
  if (ada('input') || ada('verifikasi')) tahap.push('spesimen')
  if (ada('input') || ada('sahkan')) tahap.push('hasil')
  if (ada('setujui')) tahap.push('otorisasi')
  return tahap.length ? tahap : ['spesimen']
}

/** Penanda selalu berteks (PRD 5.1 butir 4); warna dari nada Lencana (token), bukan dihitung di klien. */
export const LABEL_PENANDA: Record<Exclude<PenandaHasilLab, null>, string> = {
  l: 'Rendah', h: 'Tinggi', ll: 'Sangat rendah', hh: 'Sangat tinggi', kritis: 'Kritis',
}
export const NADA_PENANDA: Record<Exclude<PenandaHasilLab, null>, 'peringatan' | 'bahaya'> = {
  l: 'peringatan', h: 'peringatan', ll: 'bahaya', hh: 'bahaya', kritis: 'bahaya',
}
export const TEKS_TANPA_RUJUKAN = 'Tanpa nilai rujukan RS, tidak ditandai'

const FORMAT_ANGKA = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 6 })
export const formatAngka = (n: number) => FORMAT_ANGKA.format(n)

/** Nilai + satuan apa adanya dari server. */
export function teksNilai(h: NilaiHasilLab): string {
  if (h.nilai !== null) return h.satuan ? `${formatAngka(h.nilai)} ${h.satuan}` : formatAngka(h.nilai)
  return h.nilai_teks ?? 'Tidak ada nilai'
}

/** Rentang rujukan yang dipakai server saat menghitung (salinan di hasil); null bila tidak ada batas. */
export function teksRujukan(h: NilaiHasilLab): string | null {
  const { rujukan_min: min, rujukan_maks: maks } = h
  const satuan = h.satuan ? ` ${h.satuan}` : ''
  if (min !== null && maks !== null) return `Rujukan ${formatAngka(min)}–${formatAngka(maks)}${satuan}`
  if (min !== null) return `Rujukan paling rendah ${formatAngka(min)}${satuan}`
  if (maks !== null) return `Rujukan paling tinggi ${formatAngka(maks)}${satuan}`
  return null
}

export function teksBatasKritis(h: NilaiHasilLab): string | null {
  const { kritis_min: min, kritis_maks: maks } = h
  const bagian = [min !== null ? `di bawah ${formatAngka(min)}` : null, maks !== null ? `di atas ${formatAngka(maks)}` : null].filter(Boolean)
  return bagian.length ? `Kritis bila ${bagian.join(' atau ')}` : null
}

/**
 * Isian hasil → badan permintaan. Jenis hasil diketahui dari hasil yang sudah ada; untuk input pertama daftar kerja
 * belum membawa jenisnya, jadi angka dikirim sebagai `nilai` dan teks lain sebagai `nilai_teks` (server menolak 422
 * bila jenisnya lain; layar mengulang sekali sebagai teks). Koma atau titik = pemisah desimal.
 */
export function bacaIsianHasil(teks: string, jenis: HasilLab['jenis_hasil'] | null): { isian: IsianHasilLab } | { galat: string } {
  const t = teks.trim()
  if (!t) return { galat: 'Isi hasil pemeriksaan.' }
  if (t.length > 500) return { galat: 'Hasil paling banyak 500 karakter.' }
  const angka = /^-?\d+(?:[.,]\d+)?$/.test(t) ? Number(t.replace(',', '.')) : null
  if (jenis === 'teks') return { isian: { nilai_teks: t } }
  if (angka !== null) return { isian: { nilai: angka } }
  if (jenis === 'numerik') return { galat: 'Hasil pemeriksaan ini berupa angka, mis. 4,5.' }
  return { isian: { nilai_teks: t } }
}

export function kurangAlasanAdendum(alasan: string): string | null {
  const t = alasan.trim()
  if (t.length < 5) return 'Tulis alasan koreksi, minimal 5 karakter.'
  if (t.length > 500) return 'Alasan paling banyak 500 karakter.'
  return null
}

export const bolehInput = (i: ButirDaftarKerjaLab) => i.dapat?.input === true && (i.status === 'diterima_lab' || i.status === 'menunggu_validasi')
/** Validasi analis (menunggu_validasi) atau otorisasi Sp.PK (menunggu_otorisasi); butuh hasil untuk id-nya. */
export const bolehValidasi = (i: ButirDaftarKerjaLab) => i.dapat?.validasi === true && !!i.hasil && (i.status === 'menunggu_validasi' || i.status === 'menunggu_otorisasi')
export const bolehAdendum = (h: HasilLab | null | undefined) => !!h && h.status === 'divalidasi' && h.dapat.adendum === true
export const labelValidasi = (i: Pick<ButirDaftarKerjaLab, 'status'>) => (i.status === 'menunggu_otorisasi' ? 'Otorisasi' : 'Validasi')

export const LABEL_LOKASI_PENGAMBILAN: Record<LokasiPengambilanSpesimen, string> = {
  instalasi: 'Di instalasi laboratorium',
  samping_tt: 'Di samping tempat tidur',
  kamar_operasi: 'Di kamar operasi',
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

/** 409/404 = data di layar sudah basi; 422 `pemeriksaan_lab_belum_diatur` = butir tidak bisa dikerjakan: daftar dimuat ulang. */
export const perluMuatUlang = (e: unknown) => e instanceof ApiError && (e.status === 409 || e.status === 404 || e.kode === 'pemeriksaan_lab_belum_diatur')

/** Pesan yang menjelaskan apa yang terjadi dan langkah berikutnya, untuk kode galat hasil lab (kontrak v0.41.0). */
const PESAN_KODE_LAB: Record<string, string> = {
  hasil_sudah_divalidasi: 'Hasil ini sudah divalidasi, jadi tidak bisa diubah lagi. Koreksi lewat adendum.',
  sudah_memvalidasi: 'Anda yang memvalidasi hasil ini. Otorisasi harus oleh Sp.PK lain.',
  pemeriksaan_lab_belum_diatur: 'Pemeriksaan ini belum punya data induk lab (jenis hasil dan nilai rujukan). Minta pengelola data induk RS mengisinya.',
}

/** Pesan galat aksi lab: 409, 404, dan data induk belum diatur ditambah keterangan daftar dimuat ulang; 403 = akses ditolak. */
export function pesanGalatLab(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  if (e.status === 403) return `Akses ditolak: ${e.message} Minta izin laboratorium cakupan RS lewat Pengaturan peran.`
  const pesan = PESAN_KODE_LAB[e.kode] ?? e.message
  if (perluMuatUlang(e)) return `${pesan} Daftar kerja sudah dimuat ulang.`
  return pesan
}
