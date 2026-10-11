import type { AxiosResponse } from 'axios'
import { klien } from '@/shared/api/klien'
import type { MenuSaya } from './tipeMenu'
import type {
  AkunRingkas,
  AkunRinci,
  FilterAkun,
  FilterPeran,
  Halaman,
  IsianBuatPeran,
  IsianIzinKhusus,
  IsianPenugasan,
  IsianUbahPeran,
  IzinKhusus,
  Penugasan,
  PeranRingkas,
  PeranRinci,
  PerubahanIzin,
  PohonMenu,
  StatusMfaAkun,
} from './tipePengaturan'

/** Klien Pengaturan → Peran & izin, Akun & akses (docs/api/openapi.yaml v0.2.x, tiket 1.2c). */

/** Peran beserta ETag-nya; ETag dikirim kembali sebagai If-Match saat mengubah (412 = diubah orang lain). */
export interface PeranBerversi {
  peran: PeranRinci
  etag: string
}

const BATAS = 50

function berversi(respons: AxiosResponse<{ data: PeranRinci }>): PeranBerversi {
  const etag = respons.headers.etag
  const peran = respons.data.data
  return { peran, etag: typeof etag === 'string' && etag ? etag : `"${peran.versi}"` }
}

/** Kunci kosong tidak dikirim agar kursor dan cache tidak berbeda hanya karena `cari=''`. */
function bersihkan<T extends object>(filter: T): Partial<T> {
  return Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>
}

export async function ambilPohonMenu(): Promise<PohonMenu> {
  const { data } = await klien.get<{ data: PohonMenu }>('/menu')
  return data.data
}

export async function ambilDaftarPeran(filter: FilterPeran, kursor: string | null = null, batas = BATAS): Promise<Halaman<PeranRingkas>> {
  const { data } = await klien.get<Halaman<PeranRingkas>>('/peran', { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}

export async function ambilPeran(id: string): Promise<PeranBerversi> {
  return berversi(await klien.get(`/peran/${id}`))
}

export async function buatPeran(isian: IsianBuatPeran): Promise<PeranBerversi> {
  return berversi(await klien.post('/peran', isian))
}

export async function ubahPeran(id: string, etag: string, isian: IsianUbahPeran): Promise<PeranBerversi> {
  return berversi(await klien.patch(`/peran/${id}`, isian, { headers: { 'If-Match': etag } }))
}

export async function gantiIzinPeran(
  id: string,
  etag: string,
  isian: { izin: string[]; dasar_perubahan: string; alasan: string | null },
): Promise<PeranBerversi & { perubahan: PerubahanIzin }> {
  const respons = await klien.put<{ data: PeranRinci; perubahan: PerubahanIzin }>(`/peran/${id}/izin`, isian, { headers: { 'If-Match': etag } })
  return { ...berversi(respons), perubahan: respons.data.perubahan }
}

export async function ambilDaftarAkun(filter: FilterAkun, kursor: string | null = null, batas = BATAS): Promise<Halaman<AkunRingkas>> {
  const { data } = await klien.get<Halaman<AkunRingkas>>('/akun', { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}

export async function ambilAkun(id: string): Promise<AkunRinci> {
  const { data } = await klien.get<{ data: AkunRinci }>(`/akun/${id}`)
  return data.data
}

/** Lihat sebagai: menu efektif akun lain (bentuk MenuSaya), hanya pratinjau. */
export async function ambilMenuAkun(id: string): Promise<MenuSaya> {
  const { data } = await klien.get<{ data: MenuSaya }>(`/akun/${id}/menu`)
  return data.data
}

export async function tugaskanPeran(akunId: string, isian: IsianPenugasan): Promise<Penugasan> {
  const { data } = await klien.post<{ data: Penugasan }>(`/akun/${akunId}/penugasan`, isian)
  return data.data
}

export async function batalkanPenugasan(akunId: string, penugasanId: string, alasanBatal: string): Promise<Penugasan> {
  const { data } = await klien.post<{ data: Penugasan }>(`/akun/${akunId}/penugasan/${penugasanId}/batal`, { alasan_batal: alasanBatal })
  return data.data
}

export async function buatIzinKhusus(akunId: string, isian: IsianIzinKhusus): Promise<IzinKhusus> {
  const { data } = await klien.post<{ data: IzinKhusus }>(`/akun/${akunId}/izin-khusus`, isian)
  return data.data
}

export async function batalkanIzinKhusus(akunId: string, izinKhususId: string, alasanBatal: string): Promise<IzinKhusus> {
  const { data } = await klien.post<{ data: IzinKhusus }>(`/akun/${akunId}/izin-khusus/${izinKhususId}/batal`, { alasan_batal: alasanBatal })
  return data.data
}

export async function aturWajibMfaAkun(akunId: string, wajibMfa: boolean, alasan: string): Promise<StatusMfaAkun> {
  const { data } = await klien.put<{ data: StatusMfaAkun }>(`/akun/${akunId}/wajib-mfa`, { wajib_mfa: wajibMfa, alasan })
  return data.data
}

export async function resetMfaAkun(akunId: string, alasan: string, dasar: string): Promise<StatusMfaAkun> {
  const { data } = await klien.post<{ data: StatusMfaAkun }>(`/akun/${akunId}/mfa/reset`, { alasan, dasar })
  return data.data
}
