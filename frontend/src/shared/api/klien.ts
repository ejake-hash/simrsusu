import axios, { AxiosError } from 'axios'

/** Galat API dalam format tunggal PRD 10.3.1: {"galat": {"kode", "pesan", "rincian"}}. */
export interface RincianGalat {
  kolom?: string
  pesan?: string
}

export class GalatApi extends Error {
  readonly status: number
  readonly kode: string
  readonly rincian: RincianGalat[]
  /** Dari header Retry-After (detik): akun_terkunci dan 429. */
  readonly cobaLagiDetik: number | null

  constructor(status: number, kode: string, pesan: string, rincian: RincianGalat[] = [], cobaLagiDetik: number | null = null) {
    super(pesan)
    this.name = 'GalatApi'
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

let penangan401: ((galat: GalatApi) => void) | null = null

/** Dipasang sekali di main.ts: 401 di mana pun mengakhiri sesi di klien. */
export function aturPenangan401(penangan: (galat: GalatApi) => void): void {
  penangan401 = penangan
}

klien.interceptors.response.use(undefined, (galat: unknown) => {
  const hasil = keGalatApi(galat)
  const config = galat instanceof AxiosError ? galat.config : undefined
  if (hasil.status === 401 && !config?.tangani401Sendiri) {
    penangan401?.(hasil)
  }
  return Promise.reject(hasil)
})

export function keGalatApi(galat: unknown): GalatApi {
  if (galat instanceof GalatApi) return galat
  if (!(galat instanceof AxiosError) || !galat.response) {
    return new GalatApi(0, 'jaringan', 'Tidak dapat terhubung ke server. Periksa jaringan lalu coba lagi.')
  }

  const { status, data, headers } = galat.response
  const isi = (data as { galat?: { kode?: string; pesan?: string; rincian?: RincianGalat[] } } | null)?.galat
  const cobaLagi = Number.parseInt(String(headers['retry-after'] ?? ''), 10)

  return new GalatApi(
    status,
    isi?.kode ?? 'kesalahan_server',
    isi?.pesan ?? 'Terjadi kesalahan pada server.',
    isi?.rincian ?? [],
    Number.isFinite(cobaLagi) && cobaLagi > 0 ? cobaLagi : null,
  )
}
