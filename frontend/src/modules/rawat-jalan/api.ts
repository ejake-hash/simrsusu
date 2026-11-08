import type { Halaman } from '@/modules/akses/tipePengaturan'
import { berversi, type Berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { Kunjungan } from '@/modules/pendaftaran/tipeKunjungan'
import type { Alergi, AsesmenAwal, BarisWorklist, Cppt, CpptTerbatas, Diagnosis, FilterCppt, FilterWorklist, IsianAlergi, IsianAsesmenAwal, IsiSoap, JenisDiagnosis, KodeReferensi, RingkasanKlinis, Terbatas } from './tipe'

/** Klien worklist poli, asesmen awal perawat, alergi (docs/api/openapi.yaml v0.23.0, tiket 2.13), CPPT (v0.24.0, tiket 2.14), dan pemeriksaan dokter (v0.25.0, tiket 2.15). */
const ifMatch = (etag: string) => ({ headers: { 'If-Match': etag } })

export async function daftarWorklist(filter: FilterWorklist, kursor: string | null = null, batas = 50): Promise<Halaman<BarisWorklist>> {
  const params = { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas }
  return (await klien.get<Halaman<BarisWorklist>>('/rawat-jalan/worklist', { params })).data
}

/** 404 = belum ada asesmen; pemanggil menangani. Berlabel sensitif bagi bukan penulis → `{terbatas: true}` tanpa ETag. */
export async function ambilAsesmenKunjungan(kunjunganId: string): Promise<{ data: AsesmenAwal | Terbatas; etag: string | null }> {
  const r = await klien.get<{ data: AsesmenAwal | Terbatas }>(`/kunjungan/${kunjunganId}/asesmen-awal`)
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : 'versi' in r.data.data ? `"${r.data.data.versi}"` : null }
}
export async function mulaiAsesmen(kunjunganId: string, isian: IsianAsesmenAwal): Promise<Berversi<AsesmenAwal>> {
  return berversi(await klien.post(`/kunjungan/${kunjunganId}/asesmen-awal`, isian))
}
export async function ubahAsesmen(id: string, etag: string, isian: IsianAsesmenAwal & { alasan_perubahan?: string }): Promise<Berversi<AsesmenAwal>> {
  return berversi(await klien.patch(`/asesmen-awal/${id}`, isian, ifMatch(etag)))
}
export async function selesaikanAsesmen(id: string, etag: string): Promise<Berversi<AsesmenAwal>> {
  return berversi(await klien.post(`/asesmen-awal/${id}/selesai`, undefined, ifMatch(etag)))
}

export async function daftarAlergi(pasienId: string, status: 'aktif' | 'batal' = 'aktif'): Promise<Halaman<Alergi>> {
  return (await klien.get<Halaman<Alergi>>(`/pasien/${pasienId}/alergi`, { params: { status, batas: 200 } })).data
}
export async function catatAlergi(pasienId: string, isian: IsianAlergi): Promise<Alergi> {
  return (await klien.post<{ data: Alergi }>(`/pasien/${pasienId}/alergi`, isian)).data.data
}
export async function batalkanAlergi(id: string, alasan: string): Promise<Alergi> {
  return (await klien.post<{ data: Alergi }>(`/alergi/${id}/batal`, { alasan })).data.data
}

// ── CPPT (tiket 2.14). PIN hanya di body, tidak disimpan klien. ──
type Ttd = { pin: string | null; pernyataan_disetujui: boolean }

/** Linimasa terbaru dulu: `episode` = satu episode, `pasien` = lintas episode pasien itu. */
export async function linimasaCppt(lingkup: 'episode' | 'pasien', id: string, filter: FilterCppt, kursor: string | null = null, batas = 50): Promise<Halaman<Cppt | CpptTerbatas>> {
  const params = { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), kursor: kursor ?? undefined, batas }
  return (await klien.get<Halaman<Cppt | CpptTerbatas>>(`/${lingkup}/${id}/cppt`, { params })).data
}
export async function tulisCppt(kunjunganId: string, isian: IsiSoap & { label?: string | null }): Promise<Berversi<Cppt>> {
  return berversi(await klien.post(`/kunjungan/${kunjunganId}/cppt`, isian))
}
/** Draf: cukup isi. Sah ≤ 2 × 24 jam: `alasan_perubahan` + tanda tangan ulang; lewat batas → 409 `batas_perbaikan_lewat`. */
export async function ubahCppt(id: string, etag: string, isian: Partial<IsiSoap> & { label?: string | null; alasan_perubahan?: string } & Partial<Ttd>): Promise<Berversi<Cppt>> {
  return berversi(await klien.patch(`/cppt/${id}`, isian, ifMatch(etag)))
}
export async function sahkanCppt(id: string, etag: string, ttd: Ttd): Promise<Berversi<Cppt>> {
  return berversi(await klien.post(`/cppt/${id}/sahkan`, ttd, ifMatch(etag)))
}
export async function adendumCppt(id: string, etag: string, isian: { isi: string; alasan: string } & Ttd): Promise<Berversi<Cppt>> {
  return berversi(await klien.post(`/cppt/${id}/adendum`, isian, ifMatch(etag)))
}

// ── Pemeriksaan dokter (tiket 2.15) ──
/** Dokter membuka RM: `terdaftar` → `dilayani` (task 4); sudah dilayani → tanpa perubahan. ETag untuk `selesaiPeriksa`. */
export async function mulaiPeriksa(kunjunganId: string): Promise<Berversi<Kunjungan>> {
  return berversi(await klien.post(`/kunjungan/${kunjunganId}/mulai-periksa`))
}
/** 409 `syarat_selesai_belum_lengkap`: `rincian` memuat semua yang kurang. */
export async function selesaiPeriksa(kunjunganId: string, etag: string): Promise<Berversi<Kunjungan>> {
  return berversi(await klien.post(`/kunjungan/${kunjunganId}/selesai`, undefined, ifMatch(etag)))
}
export async function daftarDiagnosis(kunjunganId: string): Promise<Halaman<Diagnosis>> {
  return (await klien.get<Halaman<Diagnosis>>(`/kunjungan/${kunjunganId}/diagnosis`, { params: { batas: 200 } })).data
}
export async function catatDiagnosis(kunjunganId: string, kode: string, jenis: JenisDiagnosis): Promise<Diagnosis> {
  return (await klien.post<{ data: Diagnosis }>(`/kunjungan/${kunjunganId}/diagnosis`, { kode, jenis })).data.data
}
export async function ubahDiagnosis(d: Diagnosis, isian: { jenis: JenisDiagnosis } | { status: 'batal'; alasan_batal: string }): Promise<Diagnosis> {
  return (await klien.patch<{ data: Diagnosis }>(`/diagnosis/${d.id}`, isian, ifMatch(`"${d.versi}"`))).data.data
}
export async function ringkasanKlinis(pasienId: string): Promise<RingkasanKlinis> {
  return (await klien.get<{ data: RingkasanKlinis }>(`/pasien/${pasienId}/ringkasan-klinis`)).data.data
}
/** 409 `paket_referensi_belum_dimuat` bila RS belum memuat paket ICD-10 (langkah 4 penyiapan). */
export async function cariKodeIcd10(q: string): Promise<KodeReferensi[]> {
  return (await klien.get<Halaman<KodeReferensi>>('/paket-referensi/icd10/kode', { params: { q, batas: 20 } })).data.data
}
