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
  IsianIzinLabel,
  IsianPenugasan,
  IsianUbahPeran,
  IzinKhusus,
  IzinLabelAkun,
  LabelSensitivitas,
  Penugasan,
  PeranRingkas,
  PeranRinci,
  PerubahanIzin,
  PohonMenu,
  StatusMfaAkun,
} from './tipePengaturan'
import type { HasilPemberian, PersetujuanAkses } from './tipePersetujuan'

/** Klien Pengaturan → Peran & izin, Akun & akses (docs/api/openapi.yaml v0.2.x, tiket 1.2c; 202 menunggu persetujuan sejak 1.2d). */

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

/** 201/200 = langsung berlaku; 202 = pemberian berisiko dicatat sebagai persetujuan menunggu (kontrak v0.4.0). */
function hasilPemberian<T>(r: AxiosResponse<{ data: T | PersetujuanAkses }>): HasilPemberian<T> {
  return r.status === 202 ? { menunggu: true, persetujuan: r.data.data as PersetujuanAkses } : { menunggu: false, data: r.data.data as T }
}

export async function tugaskanPeran(akunId: string, isian: IsianPenugasan): Promise<HasilPemberian<Penugasan>> {
  return hasilPemberian(await klien.post(`/akun/${akunId}/penugasan`, isian))
}

export async function batalkanPenugasan(akunId: string, penugasanId: string, alasanBatal: string): Promise<HasilPemberian<Penugasan>> {
  return hasilPemberian(await klien.post(`/akun/${akunId}/penugasan/${penugasanId}/batal`, { alasan_batal: alasanBatal }))
}

export async function buatIzinKhusus(akunId: string, isian: IsianIzinKhusus): Promise<HasilPemberian<IzinKhusus>> {
  return hasilPemberian(await klien.post(`/akun/${akunId}/izin-khusus`, isian))
}

export async function batalkanIzinKhusus(akunId: string, izinKhususId: string, alasanBatal: string): Promise<IzinKhusus> {
  const { data } = await klien.post<{ data: IzinKhusus }>(`/akun/${akunId}/izin-khusus/${izinKhususId}/batal`, { alasan_batal: alasanBatal })
  return data.data
}

export async function aturWajibMfaAkun(akunId: string, wajibMfa: boolean, alasan: string): Promise<StatusMfaAkun> {
  const { data } = await klien.put<{ data: StatusMfaAkun }>(`/akun/${akunId}/wajib-mfa`, { wajib_mfa: wajibMfa, alasan })
  return data.data
}

/** Nonaktifkan/aktifkan akun dengan alasan (1.8d). Nonaktif memutus sesi berjalan akun itu (server). */
export async function ubahStatusAkun(akunId: string, aksi: 'nonaktifkan' | 'aktifkan', alasan: string): Promise<AkunRinci> {
  const { data } = await klien.post<{ data: AkunRinci }>(`/akun/${akunId}/${aksi}`, { alasan })
  return data.data
}

export async function resetMfaAkun(akunId: string, alasan: string, dasar: string): Promise<StatusMfaAkun> {
  const { data } = await klien.post<{ data: StatusMfaAkun }>(`/akun/${akunId}/mfa/reset`, { alasan, dasar })
  return data.data
}

// ── Lapis 4: label sensitivitas (kontrak v0.14.0, tiket 1.5) ──

/** Daftar label beserta penyetujunya. Jumlah label sedikit (data induk PRD 11.2): satu halaman berbatas 200. */
export async function ambilLabelSensitivitas(): Promise<LabelSensitivitas[]> {
  const { data } = await klien.get<Halaman<LabelSensitivitas>>('/label-sensitivitas', { params: { batas: 200 } })
  return data.data
}

export async function ambilLabelAkun(akunId: string, kursor: string | null = null, batas = BATAS): Promise<Halaman<IzinLabelAkun>> {
  const { data } = await klien.get<Halaman<IzinLabelAkun>>(`/akun/${akunId}/label`, { params: { kursor: kursor ?? undefined, batas } })
  return data
}

/** Selalu 202: menunggu persetujuan pejabat PDP (persetujuan jenis izin_label). */
export async function ajukanLabelAkun(akunId: string, isian: IsianIzinLabel): Promise<PersetujuanAkses> {
  const { data } = await klien.post<{ data: PersetujuanAkses }>(`/akun/${akunId}/label`, isian)
  return data.data
}

/** Cabut izin label yang berlaku; langsung mengurangi akses. */
export async function batalkanLabelAkun(akunId: string, labelAkunId: string, alasanBatal: string): Promise<IzinLabelAkun> {
  const { data } = await klien.post<{ data: IzinLabelAkun }>(`/akun/${akunId}/label/${labelAkunId}/batal`, { alasan_batal: alasanBatal })
  return data.data
}
