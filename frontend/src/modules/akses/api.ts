import axios from 'axios'
import { keGalatApi, klien } from '@/shared/api/klien'
import { langkahDariRespons, type Langkah } from './alurMasuk'
import type { IsianSandi } from './validasi'

export interface Pengguna {
  id: string
  nama_pengguna: string
  surel: string | null
  login_terakhir_pada: string | null
  pegawai: { id: string; nama: string } | null
}

/** Langkah 1 kontrak 10.3.2: cookie CSRF dulu, lalu nama pengguna + sandi. */
export async function kirimSandi(isian: IsianSandi): Promise<Langkah> {
  try {
    // /sanctum ada di luar /api/v1, jadi tidak lewat baseURL klien.
    await axios.get('/sanctum/csrf-cookie', { headers: { Accept: 'application/json' } })
  } catch (galat) {
    throw keGalatApi(galat)
  }

  const { data } = await klien.post('/autentikasi/masuk', isian, { tangani401Sendiri: true })
  return langkahDariRespons(data)
}

export async function ambilSaya(): Promise<Pengguna> {
  const { data } = await klien.get<{ data: Pengguna }>('/saya', { tangani401Sendiri: true })
  return data.data
}

export async function keluar(): Promise<void> {
  await klien.post('/autentikasi/keluar')
}
