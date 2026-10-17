/**
 * Mesin status halaman masuk (PRD 10.3.2):
 *   sandi → (perlu_mfa → kode) | (perlu_daftar_mfa → pindai QR → konfirmasi → kode cadangan) → masuk
 * Tiap status punya satu komponen langkah (MasukPage); langkah MFA dari tiket 1.1b.
 */

export type MetodeMfa = 'totp' | 'kode_cadangan'

/**
 * Tawaran "Percayai perangkat ini" (kontrak v0.16.0, tiket 1.13): `tersedia` false dari luar jaringan RS atau bila masa
 * percaya akun 0; `hari` = masa percaya efektif. Backend sebelum 1.13-be tidak mengirimnya = tidak tersedia.
 */
export interface PercayaiPerangkat {
  tersedia: boolean
  hari: number
}
const TIDAK_TERSEDIA: PercayaiPerangkat = { tersedia: false, hari: 0 }
function percayaiDari(isi: unknown): PercayaiPerangkat {
  const p = (isi ?? {}) as Record<string, unknown>
  const hari = typeof p.hari === 'number' && p.hari > 0 ? Math.floor(p.hari) : 0
  return p.tersedia === true && hari > 0 ? { tersedia: true, hari } : TIDAK_TERSEDIA
}

export type Langkah =
  | { status: 'sandi' }
  | { status: 'perlu_mfa'; challenge_id: string; metode: MetodeMfa[]; kedaluwarsa_pada: string; percayai_perangkat: PercayaiPerangkat }
  | { status: 'perlu_daftar_mfa'; challenge_id: string; kedaluwarsa_pada: string; percayai_perangkat: PercayaiPerangkat }
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
  if (status === 'perlu_mfa' && typeof respons.challenge_id === 'string') {
    return {
      status,
      challenge_id: respons.challenge_id,
      metode: Array.isArray(respons.metode) ? (respons.metode as MetodeMfa[]) : [],
      kedaluwarsa_pada: String(respons.kedaluwarsa_pada ?? ''),
      percayai_perangkat: percayaiDari(respons.percayai_perangkat),
    }
  }
  if (status === 'perlu_daftar_mfa' && typeof respons.challenge_id === 'string') {
    return { status, challenge_id: respons.challenge_id, kedaluwarsa_pada: String(respons.kedaluwarsa_pada ?? ''), percayai_perangkat: percayaiDari(respons.percayai_perangkat) }
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
