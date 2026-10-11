import { AxiosError, AxiosHeaders, getAdapter, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'
import { buatServerGabungan } from './serverGabungan'
import type { PermintaanTiruan, ResponsTiruan } from './serverTiruan'

/**
 * Memasang server tiruan 1.2c sebagai adapter axios: permintaan yang dikenali dijawab dari memori, sisanya
 * diteruskan ke backend. Hanya untuk `vite` dev (VITE_API_TIRUAN=true) dan unit test; tidak masuk build produksi.
 */
export function pasangTiruan<S extends { tangani: (r: PermintaanTiruan) => ResponsTiruan | Promise<ResponsTiruan> | null }>(klien: AxiosInstance, server: S = buatServerGabungan() as unknown as S): S {
  const asli = getAdapter(klien.defaults.adapter)

  klien.defaults.adapter = async (config: InternalAxiosRequestConfig) => {
    const url = new URL(config.url ?? '', 'http://tiruan')
    const kueri = new URLSearchParams(url.search)
    for (const [k, v] of Object.entries((config.params ?? {}) as Record<string, unknown>)) {
      if (v !== undefined && v !== null) kueri.set(k, String(v))
    }
    const header = Object.fromEntries(Object.entries(AxiosHeaders.from(config.headers).toJSON()).map(([k, v]) => [k.toLowerCase(), String(v)]))
    // FormData (unggah berkas) diteruskan apa adanya; JSON diurai.
    const isi = typeof config.data === 'string' && config.data ? JSON.parse(config.data) : config.data

    // Sebagian rute tiruan asinkron (mis. impor CSV membaca isi berkas).
    const hasil = await server.tangani({ metode: config.method ?? 'get', jalur: url.pathname, kueri, header, isi })
    if (!hasil) return asli(config)

    const respons = {
      data: hasil.isi ?? '',
      status: hasil.status,
      statusText: String(hasil.status),
      headers: new AxiosHeaders(Object.fromEntries(Object.entries(hasil.header ?? {}).map(([k, v]) => [k.toLowerCase(), v]))),
      config,
    }
    const lolos = config.validateStatus ? config.validateStatus(hasil.status) : hasil.status < 400
    if (!lolos) throw new AxiosError(`Status ${hasil.status}`, String(hasil.status), config, null, respons)
    return respons
  }
  return server
}
