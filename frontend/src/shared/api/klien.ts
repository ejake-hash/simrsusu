import axios, { AxiosError } from 'axios'

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

/** Dipasang sekali di main.ts: 401 di mana pun mengakhiri sesi di klien. */
export function aturPenangan401(penangan: (error: ApiError) => void): void {
  penangan401 = penangan
}

/** Dipasang sekali di main.ts: 403 wajib_ganti_sandi (akun bersandi awal, 1.8d) mengarahkan ke layar ganti sandi. */
export function aturPenanganWajibGantiSandi(penangan: () => void): void {
  penanganWajibGantiSandi = penangan
}

klien.interceptors.response.use(undefined, (error: unknown) => {
  const hasil = keApiError(error)
  const config = error instanceof AxiosError ? error.config : undefined
  if (hasil.status === 401 && !config?.tangani401Sendiri) {
    penangan401?.(hasil)
  }
  if (hasil.status === 403 && hasil.kode === 'wajib_ganti_sandi') {
    penanganWajibGantiSandi?.()
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
