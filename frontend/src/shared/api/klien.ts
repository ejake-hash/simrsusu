import axios, { AxiosError, AxiosHeaders } from 'axios'

/** Error API dalam format tunggal PRD 10.3.1: {"error": {"kode", "pesan", "rincian"}}. */
export interface RincianError {
  /** Validasi kolom (ErrorHandler): nama kolom permintaan. */
  kolom?: string
  pesan?: string
  /** Penolakan izin (409/422 di /peran dan /akun): kode izin yang ditolak. */
  kode?: string
  [kunci: string]: unknown
}

export class ApiError extends Error {
  readonly status: number
  readonly kode: string
  readonly rincian: RincianError[]
  /** Dari header Retry-After (detik): akun_terkunci dan 429. */
  readonly cobaLagiDetik: number | null

  constructor(status: number, kode: string, pesan: string, rincian: RincianError[] = [], cobaLagiDetik: number | null = null) {
    super(pesan)
    this.name = 'ApiError'
    this.status = status
    this.kode = kode
    this.rincian = rincian
    this.cobaLagiDetik = cobaLagiDetik
  }
}

declare module 'axios' {
  interface AxiosRequestConfig {
    /** true = 401 ditangani pemanggil (mis. sandi salah), bukan dianggap sesi berakhir. */
    tangani401Sendiri?: boolean
    /**
     * Pengulangan sesudah MFA ulang. true = sesudah kode dimasukkan: 403 berikutnya tidak membuka dialog lagi. 'masa' =
     * diulang karena masa verifikasi masih berlaku menurut klien: bila tetap 403, dialog dibuka satu kali.
     */
    sudahMfaUlang?: boolean | 'masa'
  }
}

// Sesi memakai cookie Sanctum (same-origin lewat proksi). Axios membaca cookie XSRF-TOKEN dan
// mengirim header X-XSRF-TOKEN sendiri; tidak ada token yang disimpan di JavaScript.
export const klien = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

let penangan401: ((error: ApiError) => void) | null = null
let penanganWajibGantiSandi: (() => void) | null = null
let penanganMfaUlang: ((paksaDialog: boolean) => Promise<'dialog' | 'masa' | false>) | null = null

/** Dipasang sekali di main.ts: 401 di mana pun mengakhiri sesi di klien. */
export function aturPenangan401(penangan: (error: ApiError) => void): void {
  penangan401 = penangan
}

/** Dipasang sekali di main.ts: 403 wajib_ganti_sandi (akun bersandi awal, 1.8d) mengarahkan ke layar ganti sandi. */
export function aturPenanganWajibGantiSandi(penangan: () => void): void {
  penanganWajibGantiSandi = penangan
}

/**
 * Dipasang sekali di main.ts (tiket 1.5, K11): 403 mfa_ulang_diperlukan meminta kode MFA lewat dialog ('dialog') atau
 * memakai masa verifikasi yang masih berlaku ('masa'), lalu permintaan yang tadi ditolak diulang. false = dibatalkan.
 */
export function aturPenanganMfaUlang(penangan: (paksaDialog: boolean) => Promise<'dialog' | 'masa' | false>): void {
  penanganMfaUlang = penangan
}

klien.interceptors.response.use(undefined, async (error: unknown) => {
  const hasil = keApiError(error)
  const config = error instanceof AxiosError ? error.config : undefined
  if (hasil.status === 401 && !config?.tangani401Sendiri) {
    penangan401?.(hasil)
  }
  if (hasil.status === 403 && hasil.kode === 'wajib_ganti_sandi') {
    penanganWajibGantiSandi?.()
  }
  // Polling latar (X-Permintaan-Latar) tidak pernah membuka dialog: pengguna tidak sedang melakukan aksi itu.
  const latar = !!config?.headers && AxiosHeaders.from(config.headers).has('X-Permintaan-Latar')
  if (hasil.status === 403 && hasil.kode === 'mfa_ulang_diperlukan' && config && config.sudahMfaUlang !== true && !latar && penanganMfaUlang) {
    const jawaban = await penanganMfaUlang(config.sudahMfaUlang === 'masa')
    if (jawaban) return klien.request({ ...config, sudahMfaUlang: jawaban === 'masa' ? 'masa' : true })
    return Promise.reject(new ApiError(403, 'mfa_ulang_dibatalkan', 'Aksi dibatalkan karena verifikasi dua langkah tidak diselesaikan.'))
  }
  return Promise.reject(hasil)
})

export function keApiError(error: unknown): ApiError {
  if (error instanceof ApiError) return error
  if (!(error instanceof AxiosError) || !error.response) {
    return new ApiError(0, 'jaringan', 'Tidak dapat terhubung ke server. Periksa jaringan lalu coba lagi.')
  }

  const { status, data, headers } = error.response
  const isi = (data as { error?: { kode?: string; pesan?: string; rincian?: RincianError[] } } | null)?.error
  const cobaLagi = Number.parseInt(String(headers['retry-after'] ?? ''), 10)

  return new ApiError(
    status,
    isi?.kode ?? 'kesalahan_server',
    isi?.pesan ?? 'Terjadi kesalahan pada server.',
    isi?.rincian ?? [],
    Number.isFinite(cobaLagi) && cobaLagi > 0 ? cobaLagi : null,
  )
}
