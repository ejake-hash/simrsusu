import type { JenisCariPasien, JenisKelaminPasien, Pasien } from './tipe'

/** Kunci cache dan label bersama layar Pendaftaran pasien (tiket 2.5, kontrak v0.19.0). */
export const KUNCI_PASIEN = {
  semua: ['pasien'] as const,
  cari: (kata: string) => ['pasien', 'cari', kata] as const,
}

export const LABEL_JENIS_KELAMIN: Record<JenisKelaminPasien, string> = {
  laki_laki: 'Laki-laki',
  perempuan: 'Perempuan',
  tidak_diketahui: 'Belum diketahui',
}

/** Bagaimana server menafsirkan kata cari (meta.jenis_cari), untuk konteks hasil pencarian. */
export const LABEL_JENIS_CARI: Record<JenisCariPasien, string> = {
  nik: 'NIK',
  no_rm: 'No. RM',
  no_kartu: 'No. kartu BPJS',
  nama_tanggal_lahir: 'nama & tanggal lahir',
}

/** No. RM dengan pemisah ribuan agar lebih mudah dibaca (tampilan saja; nilai tetap angka server). */
export function formatNoRm(noRm: string): string {
  return noRm.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
}

/** Tanggal lahir ISO → "12 Mar 1980"; null = em dash. */
const TANGGAL = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
export function formatTanggal(iso: string | null): string {
  if (!iso) return '—'
  const t = new Date(`${iso}T00:00:00`)
  return Number.isNaN(t.getTime()) ? '—' : TANGGAL.format(t)
}

/** NIK/paspor untuk ditampilkan: nilai utuh bila dikirim (berizin), selain itu versi termasking dari server. */
export function tampilanNik(p: Pasien): string | null {
  return p.nik ?? p.nik_samaran
}
export function tampilanPaspor(p: Pasien): string | null {
  return p.no_paspor ?? p.no_paspor_samaran
}
