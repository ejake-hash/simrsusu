/**
 * Mesin status halaman masuk (PRD 10.3.2):
 *   sandi → (perlu_mfa → kode) | (perlu_daftar_mfa → pindai QR → konfirmasi → kode cadangan) → masuk
 * Tiap status punya satu komponen langkah (MasukPage); langkah MFA dari tiket 1.1b.
 */

export type MetodeMfa = 'totp' | 'kode_cadangan'

export type Langkah =
  | { status: 'sandi' }
  | { status: 'perlu_mfa'; tantangan_id: string; metode: MetodeMfa[]; kedaluwarsa_pada: string }
  | { status: 'perlu_daftar_mfa'; tantangan_id: string; kedaluwarsa_pada: string }
  | { status: 'masuk' }

/** Langkah yang punya tampilan; `masuk` langsung diarahkan keluar dari halaman masuk. */
export type LangkahTampil = Exclude<Langkah, { status: 'masuk' }>

export const LANGKAH_AWAL: LangkahTampil = { status: 'sandi' }

/**
 * Langkah berikut dari respons 200 langkah sandi/MFA. Sejak tiket 1.1b `status` wajib ada; status
 * yang tidak dikenal atau tidak ada ditolak, bukan dianggap masuk.
 */
export function langkahDariRespons(isi: unknown): Langkah {
  const respons = (isi ?? {}) as Record<string, unknown>
  const status = respons.status

  if (status === 'masuk') {
    return { status: 'masuk' }
  }
  if (status === 'perlu_mfa' && typeof respons.tantangan_id === 'string') {
    return {
      status,
      tantangan_id: respons.tantangan_id,
      metode: Array.isArray(respons.metode) ? (respons.metode as MetodeMfa[]) : [],
      kedaluwarsa_pada: String(respons.kedaluwarsa_pada ?? ''),
    }
  }
  if (status === 'perlu_daftar_mfa' && typeof respons.tantangan_id === 'string') {
    return { status, tantangan_id: respons.tantangan_id, kedaluwarsa_pada: String(respons.kedaluwarsa_pada ?? '') }
  }

  throw new Error('Respons masuk tidak dikenali.')
}

/** Tujuan setelah masuk: hanya jalur internal aplikasi, selain itu beranda (cegah open redirect). */
export function tujuanSetelahMasuk(kembali: unknown): string {
  if (typeof kembali !== 'string' || !kembali.startsWith('/') || kembali.startsWith('//') || kembali.startsWith('/\\')) {
    return '/'
  }
  return kembali.startsWith('/masuk') ? '/' : kembali
}
