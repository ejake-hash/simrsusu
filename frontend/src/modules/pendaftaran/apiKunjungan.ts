import { berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { FilterKunjungan, HalamanKunjungan, IsianKunjungan, IsianKunjunganIgd, Kunjungan, StatusKunjungan } from './tipeKunjungan'

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
