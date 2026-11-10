import type { NamaIkon } from '@/design-system/ikon/ikon'
import { INTERVAL_PAPAN } from '@/modules/igd/papan'
import { ApiError } from '@/shared/api/klien'
import type { FilterPetaServer, RingkasLokasiPeta, StatusTempatTidur, TempatTidurPeta } from './tipe'

/**
 * Aturan tampil peta tempat tidur (tiket 2.27; FR-08-01, FR-08-20, FR-08-04 bagian status). Urutan TT dari server
 * (gedung, lantai, bangsal, kode); layar hanya mengelompokkan dan menyaring tanpa mengurutkan ulang. Status selalu tampil
 * dengan teks dan ikon, bukan hanya warna (PRD 5.1 butir 4).
 */

/** Polling layar kerja K1: sama dengan papan pasien IGD (15 detik, jeda saat tab tidak terlihat). */
export const INTERVAL_PETA = INTERVAL_PAPAN

export const KUNCI_PETA = {
  semua: ['rawat-inap', 'peta-tempat-tidur'] as const,
  peta: (f: FilterPetaServer) => ['rawat-inap', 'peta-tempat-tidur', f.gedung_id ?? 'semua', f.bangsal_id ?? 'semua'] as const,
}

/** Status tampil: status kontrak, ditambah `ditutup` (TT ditutup 1.8b, status tersimpan tidak_dipakai). */
export type KunciStatus = StatusTempatTidur | 'ditutup'
export const URUTAN_STATUS: readonly KunciStatus[] = ['terisi', 'rencana_pulang', 'dipesan', 'dibersihkan', 'kosong_siap', 'tidak_dipakai', 'ditutup']

export type JenisLencana = 'ok' | 'peringatan' | 'bahaya' | 'info' | 'netral' | 'merek'
export interface TandaStatus {
  label: string
  jenis: JenisLencana
  ikon: NamaIkon
}

// Warna boleh berulang (terisi dan rencana pulang sama-sama berpasien); pembeda tetap teks dan ikon.
const TANDA: Record<KunciStatus, TandaStatus> = {
  terisi: { label: 'Terisi', jenis: 'info', ikon: 'pengguna' },
  rencana_pulang: { label: 'Rencana pulang', jenis: 'info', ikon: 'keluar' },
  dipesan: { label: 'Dipesan', jenis: 'merek', ikon: 'penanda' },
  dibersihkan: { label: 'Dibersihkan', jenis: 'peringatan', ikon: 'tetes' },
  kosong_siap: { label: 'Kosong siap', jenis: 'ok', ikon: 'centang' },
  tidak_dipakai: { label: 'Tidak dipakai', jenis: 'netral', ikon: 'tutup' },
  ditutup: { label: 'Ditutup', jenis: 'netral', ikon: 'gembok' },
}

export const kunciStatus = (tt: Pick<TempatTidurPeta, 'status' | 'ditutup'>): KunciStatus => (tt.ditutup ? 'ditutup' : tt.status)

/** Nilai di luar kontrak (kontrak lebih baru) tampil netral berteks, peta tidak gagal tampil. */
export const tandaKunci = (k: KunciStatus): TandaStatus => TANDA[k] ?? { label: 'Status tidak dikenal', jenis: 'netral', ikon: 'info' }
export const tandaStatus = (tt: Pick<TempatTidurPeta, 'status' | 'ditutup'>): TandaStatus => tandaKunci(kunciStatus(tt))
export const labelStatus = (k: KunciStatus) => TANDA[k].label

/** "Hari ke-3" bila server mengirim hari rawat (null sampai penempatan 2.29). */
export const teksHariRawat = (tt: Pick<TempatTidurPeta, 'hari_rawat'>) => (tt.hari_rawat === null ? null : `Hari ke-${tt.hari_rawat}`)

/** Tanda TT selain status: isolasi, ekstra, aturan jenis kelamin (campur tidak ditandai). */
export function tandaTambahan(tt: Pick<TempatTidurPeta, 'isolasi' | 'ekstra' | 'aturan_jenis_kelamin'>): string[] {
  const hasil: string[] = []
  if (tt.isolasi) hasil.push('Isolasi')
  if (tt.ekstra) hasil.push('TT ekstra')
  if (tt.aturan_jenis_kelamin === 'laki_laki') hasil.push('Khusus laki-laki')
  if (tt.aturan_jenis_kelamin === 'perempuan') hasil.push('Khusus perempuan')
  return hasil
}

/** Nilai pilihan lantai untuk TT yang ruangnya tidak di bawah lokasi lantai. */
export const TANPA_LANTAI = 'tanpa-lantai'
const kunciLantai = (tt: Pick<TempatTidurPeta, 'lantai'>) => tt.lantai?.id ?? TANPA_LANTAI

export interface FilterPetaKlien {
  lantai: string
  status: KunciStatus | ''
  cari: string
}

/** Filter di layar (API tidak punya parameter lantai, status, atau kode). Cari = bagian kode TT, tanpa beda huruf besar. */
export function saringPeta(data: readonly TempatTidurPeta[], f: Partial<FilterPetaKlien>): TempatTidurPeta[] {
  const cari = (f.cari ?? '').trim().toLowerCase()
  return data.filter(
    (tt) => (!f.lantai || kunciLantai(tt) === f.lantai) && (!f.status || kunciStatus(tt) === f.status) && (!cari || tt.kode.toLowerCase().includes(cari)),
  )
}

export interface KelompokKamar {
  id: string
  nama: string
  tt: TempatTidurPeta[]
}
export interface KelompokBangsal {
  id: string
  nama: string
  kamar: KelompokKamar[]
}
export interface KelompokLantai {
  kunci: string
  nama: string
  bangsal: KelompokBangsal[]
}
export interface KelompokGedung {
  kunci: string
  nama: string
  lantai: KelompokLantai[]
}

function ambilAtauBuat<T>(daftar: T[], cocok: (x: T) => boolean, buat: () => T): T {
  const ada = daftar.find(cocok)
  if (ada) return ada
  const baru = buat()
  daftar.push(baru)
  return baru
}

/** Gedung → lantai → bangsal → kamar, urut kemunculan pertama (= urutan server). */
export function kelompokkanPeta(data: readonly TempatTidurPeta[]): KelompokGedung[] {
  const gedung: KelompokGedung[] = []
  for (const tt of data) {
    const kg = tt.gedung?.id ?? 'tanpa-gedung'
    const g = ambilAtauBuat(gedung, (x) => x.kunci === kg, () => ({ kunci: kg, nama: tt.gedung?.nama ?? 'Tanpa gedung', lantai: [] }))
    const kl = kunciLantai(tt)
    const l = ambilAtauBuat(g.lantai, (x) => x.kunci === kl, () => ({ kunci: kl, nama: tt.lantai?.nama ?? 'Tanpa lantai', bangsal: [] }))
    const b = ambilAtauBuat(l.bangsal, (x) => x.id === tt.bangsal.id, () => ({ id: tt.bangsal.id, nama: tt.bangsal.nama, kamar: [] }))
    const k = ambilAtauBuat(b.kamar, (x) => x.id === tt.kamar.id, () => ({ id: tt.kamar.id, nama: tt.kamar.nama, tt: [] }))
    k.tt.push(tt)
  }
  return gedung
}

/** Jumlah TT per status tampil, urutan tetap; status tanpa TT tetap disebut dengan 0. */
export function ringkasStatus(data: readonly TempatTidurPeta[]): { kunci: KunciStatus; label: string; jumlah: number }[] {
  const jumlah = new Map<KunciStatus, number>()
  for (const tt of data) jumlah.set(kunciStatus(tt), (jumlah.get(kunciStatus(tt)) ?? 0) + 1)
  return URUTAN_STATUS.map((kunci) => ({ kunci, label: TANDA[kunci].label, jumlah: jumlah.get(kunci) ?? 0 }))
}

/** Pilihan lantai dari TT yang sedang dimuat. */
export function pilihanLantai(data: readonly TempatTidurPeta[]): { nilai: string; label: string }[] {
  const hasil: { nilai: string; label: string }[] = []
  for (const tt of data) {
    const nilai = kunciLantai(tt)
    if (hasil.some((x) => x.nilai === nilai)) continue
    hasil.push({ nilai, label: tt.lantai ? (tt.gedung ? `${tt.lantai.nama} · ${tt.gedung.nama}` : tt.lantai.nama) : 'Tanpa lantai' })
  }
  return hasil
}

/**
 * Pilihan gedung dan bangsal yang pernah terlihat di peta (tanpa endpoint daftar tersendiri): tetap ada selama peta
 * disaring, agar pilihan lain tidak hilang sesudah satu gedung atau bangsal dipilih.
 */
export function gabungPilihanLokasi(
  lama: { gedung: RingkasLokasiPeta[]; bangsal: RingkasLokasiPeta[] },
  data: readonly TempatTidurPeta[],
): { gedung: RingkasLokasiPeta[]; bangsal: RingkasLokasiPeta[] } {
  const gedung = [...lama.gedung]
  const bangsal = [...lama.bangsal]
  for (const tt of data) {
    if (tt.gedung && !gedung.some((g) => g.id === tt.gedung!.id)) gedung.push(tt.gedung)
    if (!bangsal.some((b) => b.id === tt.bangsal.id)) bangsal.push(tt.bangsal)
  }
  return { gedung, bangsal }
}

/** Aksi petugas 2.27: hanya TT dibersihkan yang tidak ditutup, dan hanya bagi pemegang izin perbaiki. */
export const bolehTandaiKosongSiap = (tt: Pick<TempatTidurPeta, 'status' | 'ditutup'>, izinPerbaiki: boolean) => izinPerbaiki && !tt.ditutup && tt.status === 'dibersihkan'

/** Pesan galat POST status TT per kode kontrak. */
export function pesanGalatStatus(e: unknown, kode: string): string {
  if (!(e instanceof ApiError)) return `Status TT ${kode} gagal diubah. Coba lagi.`
  if (e.status === 409 && e.kode === 'tempat_tidur_ditutup') return `TT ${kode} sudah ditutup, statusnya tidak bisa diubah.`
  if (e.status === 409) return `Status TT ${kode} sudah berubah dan bukan lagi dibersihkan. Periksa peta terbaru.`
  if (e.status === 403) return `Anda tidak punya akses mengubah status TT ${kode}.`
  if (e.status === 404) return `TT ${kode} tidak ditemukan. Periksa peta terbaru.`
  return e.message
}
