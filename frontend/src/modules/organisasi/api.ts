import type { AxiosResponse } from 'axios'
import { klien } from '@/shared/api/klien'
import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { FilterUnit, IsianProfilRs, IsianUnitOrganisasi, JenisBerkas, Ksm, ProfilRsApi, UbahUnitOrganisasi, UnitOrganisasi } from './tipe'

/** Klien Profil RS, unit organisasi, KSM (docs/api/openapi.yaml v0.3.0, tiket 1.8a). */

export interface Berversi<T> {
  data: T
  etag: string
}

function berversi<T extends { versi: string }>(r: AxiosResponse<{ data: T }>): Berversi<T> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}
const bersihkan = <T extends object>(f: T) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>

// ── Profil RS ──
export async function ambilProfilRs(): Promise<Berversi<ProfilRsApi>> {
  return berversi(await klien.get('/profil-rs', { tangani401Sendiri: true }))
}
export async function ubahProfilRs(etag: string, isian: IsianProfilRs): Promise<Berversi<ProfilRsApi>> {
  return berversi(await klien.put('/profil-rs', isian, { headers: { 'If-Match': etag } }))
}
export async function unggahBerkasProfilRs(jenis: JenisBerkas, berkas: File, etag: string): Promise<Berversi<ProfilRsApi>> {
  const isi = new FormData()
  isi.append('berkas', berkas)
  return berversi(await klien.put(`/profil-rs/berkas/${jenis}`, isi, { headers: { 'If-Match': etag } }))
}

// ── Unit organisasi ──
export async function daftarUnit(filter: FilterUnit, kursor: string | null = null, batas = 50): Promise<Halaman<UnitOrganisasi>> {
  const { data } = await klien.get<Halaman<UnitOrganisasi>>('/unit-organisasi', { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
/** Semua anak langsung satu induk (atau akar), mengikuti kursor sampai habis; batas 200 per halaman. */
export async function semuaAnakUnit(indukId: string | null): Promise<UnitOrganisasi[]> {
  const hasil: UnitOrganisasi[] = []
  let kursor: string | null = null
  do {
    const h: Halaman<UnitOrganisasi> = await daftarUnit(indukId ? { induk_id: indukId } : { akar: true }, kursor, 200)
    hasil.push(...h.data)
    kursor = h.meta.kursor_berikutnya
  } while (kursor)
  return hasil
}
export async function ambilUnit(id: string): Promise<Berversi<UnitOrganisasi>> {
  return berversi(await klien.get(`/unit-organisasi/${id}`))
}
export async function buatUnit(isian: IsianUnitOrganisasi): Promise<Berversi<UnitOrganisasi>> {
  return berversi(await klien.post('/unit-organisasi', isian))
}
export async function ubahUnit(id: string, etag: string, isian: UbahUnitOrganisasi): Promise<Berversi<UnitOrganisasi>> {
  return berversi(await klien.patch(`/unit-organisasi/${id}`, isian, { headers: { 'If-Match': etag } }))
}

// ── KSM ──
export async function daftarKsm(filter: { cari?: string; status?: 'aktif' | 'nonaktif' }, kursor: string | null = null, batas = 50): Promise<Halaman<Ksm>> {
  const { data } = await klien.get<Halaman<Ksm>>('/ksm', { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
export async function ambilKsm(id: string): Promise<Berversi<Ksm>> {
  return berversi(await klien.get(`/ksm/${id}`))
}
export async function buatKsm(isian: { kode: string; nama: string; dasar_perubahan: string }): Promise<Berversi<Ksm>> {
  return berversi(await klien.post('/ksm', isian))
}
export async function ubahKsm(id: string, etag: string, isian: { kode?: string; nama?: string; status?: 'aktif' | 'nonaktif'; ketua_pegawai_id?: string | null; dasar_perubahan: string }): Promise<Berversi<Ksm>> {
  return berversi(await klien.patch(`/ksm/${id}`, isian, { headers: { 'If-Match': etag } }))
}
