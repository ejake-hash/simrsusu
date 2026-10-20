import type { AksiUsulan, FilterTahap, ObjekDataInduk, TahapUsulan } from './tipe'

/** Kunci kueri, label, dan pil layar usulan perubahan data induk (tiket 1.8c). */

export const KUNCI_USULAN = {
  semua: ['usulan'] as const,
  daftar: (f: { tahap?: FilterTahap; perlu_saya?: boolean }) => ['usulan', 'daftar', f] as const,
  rinci: (id: string) => ['usulan', 'rinci', id] as const,
  versi: (jenis: ObjekDataInduk, id: string) => ['usulan', 'versi', jenis, id] as const,
}

export const LABEL_JENIS_DATA: Record<string, string> = { ruang_tt: 'Ruang & tempat tidur', tarif: 'Buku tarif' }

export const LABEL_TAHAP: Record<TahapUsulan, string> = {
  diusulkan: 'Menunggu verifikasi',
  diverifikasi: 'Menunggu persetujuan',
  disetujui: 'Terjadwal berlaku',
  berlaku: 'Berlaku',
  dikembalikan: 'Dikembalikan',
  gagal_diterapkan: 'Gagal diterapkan',
}
export const PIL_TAHAP: Record<TahapUsulan, string> = {
  diusulkan: 'pil-info',
  diverifikasi: 'pil-peringatan',
  disetujui: 'pil-ok',
  berlaku: 'pil-netral',
  dikembalikan: 'pil-bahaya',
  gagal_diterapkan: 'pil-bahaya',
}

export const LABEL_AKSI_BUTIR: Record<`${ObjekDataInduk}.${AksiUsulan}`, string> = {
  'tempat_tidur.tambah': 'Tambah tempat tidur',
  'tempat_tidur.ubah': 'Ubah tempat tidur',
  'tempat_tidur.tutup': 'Tutup tempat tidur',
  'kamar.tambah': 'Jadikan ruang kamar rawat',
  'kamar.ubah': 'Ubah kamar',
  'kamar.tutup': 'Tutup kamar',
  'ruang.tambah': 'Tambah ruang',
  'ruang.ubah': 'Ubah ruang',
  'ruang.tutup': 'Tutup ruang',
  'tarif.tambah': 'Tambah tarif',
  'tarif.ubah': 'Ubah tarif',
  'tarif.tutup': 'Tutup tarif',
}
/** Kombinasi yang dikenal kontrak v0.9.0 (urutan pilihan di editor). */
export const PILIHAN_BUTIR: `${ObjekDataInduk}.${AksiUsulan}`[] = ['tempat_tidur.tambah', 'tempat_tidur.ubah', 'tempat_tidur.tutup', 'kamar.tambah', 'kamar.ubah', 'ruang.tambah', 'ruang.ubah']

export const LABEL_RIWAYAT_USULAN: Record<string, string> = {
  diajukan: 'Diajukan',
  diverifikasi: 'Diverifikasi',
  disetujui: 'Disetujui',
  dikembalikan: 'Dikembalikan',
  dikirim_ulang: 'Dikirim ulang',
  diterapkan: 'Berlaku',
  gagal_diterapkan: 'Gagal diterapkan',
}

/** Tahap alur untuk penanda langkah (dikembalikan/gagal ditandai di langkah tempat terhenti). */
export const LANGKAH_ALUR: { tahap: TahapUsulan; label: string }[] = [
  { tahap: 'diusulkan', label: 'Diusulkan' },
  { tahap: 'diverifikasi', label: 'Diverifikasi' },
  { tahap: 'disetujui', label: 'Disetujui' },
  { tahap: 'berlaku', label: 'Berlaku' },
]
