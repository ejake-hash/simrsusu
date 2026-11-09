import { berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type {
  FilterKunjungan,
  HalamanKunjungan,
  HalamanTriase,
  InstrumenTriase,
  IsianKunjungan,
  IsianKunjunganIgd,
  IsianTriase,
  Kunjungan,
  StatusKunjungan,
  Triase,
} from './tipeKunjungan'

/**
 * Klien /kunjungan (docs/api/openapi.yaml v0.20.0, tiket 2.6). Episode rawat jalan harian terbentuk/digabung otomatis
 * di server (7.00.2); event `KunjunganDibuat` dipakai SEP (2.7) dan antrean (2.8). Respons tidak di-cache (no-store).
 */
const JALUR = '/kunjungan'


export async function daftarKunjungan(filter: FilterKunjungan, kursor: string | null = null, batas = 50): Promise<HalamanKunjungan> {
  const { data } = await klien.get<HalamanKunjungan>(JALUR, {
    params: { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas },
  })
  return data
}

export const buatKunjungan = async (isian: IsianKunjungan) => berversi<Kunjungan>(await klien.post(JALUR, isian))

/**
 * /kunjungan-igd (kontrak v0.31.0, tiket 2.23). Bawaan daftar = kunjungan yang masih di IGD (terdaftar/dilayani), urut
 * jam tiba terlama dulu. Registrasi hanya isian minimal; jam tiba dari server. Pembatalan lewat batalkanKunjungan.
 */
export async function daftarKunjunganIgd(status: StatusKunjungan | null = null, kursor: string | null = null, batas = 50): Promise<HalamanKunjungan> {
  const { data } = await klien.get<HalamanKunjungan>('/kunjungan-igd', { params: { status: status ?? undefined, kursor: kursor ?? undefined, batas } })
  return data
}
export const buatKunjunganIgd = async (isian: IsianKunjunganIgd) => berversi<Kunjungan>(await klien.post('/kunjungan-igd', isian))
export const batalkanKunjungan = async (id: string, alasanBatal: string) =>
  berversi<Kunjungan>(await klien.post(`${JALUR}/${id}/batal`, { alasan_batal: alasanBatal }))

/**
 * Triase IGD (kontrak v0.32.0, tiket 2.24). Riwayat terbaru dulu; baris terbaru `berlaku`. Ulang triase = POST lagi
 * (baris baru, baris lama tidak berubah). Instrumen dan skala dari data induk RS.
 */
export async function daftarTriase(kunjunganId: string, kursor: string | null = null, batas = 50): Promise<HalamanTriase> {
  const { data } = await klien.get<HalamanTriase>(`${JALUR}/${kunjunganId}/triase`, { params: { kursor: kursor ?? undefined, batas } })
  return data
}
export async function catatTriase(kunjunganId: string, isian: IsianTriase): Promise<Triase> {
  const { data } = await klien.post<{ data: Triase }>(`${JALUR}/${kunjunganId}/triase`, isian)
  return data.data
}
export async function daftarInstrumenTriase(): Promise<InstrumenTriase[]> {
  const { data } = await klien.get<{ data: InstrumenTriase[] }>('/instrumen-triase')
  return data.data
}
