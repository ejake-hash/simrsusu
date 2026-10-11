import type { AxiosResponse } from 'axios'
import { klien } from '@/shared/api/klien'
import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { Berversi } from './api'
import type {
  AtributKamar,
  Bangsal,
  FilterLokasi,
  FilterTempatTidur,
  HasilImporLokasi,
  IsianLokasi,
  IsianTempatTidur,
  Kamar,
  KapasitasBangsal,
  KelasRawat,
  Lokasi,
  Poliklinik,
  TempatTidur,
  UbahLokasi,
} from './tipeLokasi'

/** Klien lokasi fisik, bangsal, kamar, TT, kelas rawat, poliklinik, kapasitas (docs/api/openapi.yaml v0.5.0, tiket 1.8b). */

function berversi<T extends { versi: string }>(r: AxiosResponse<{ data: T }>): Berversi<T> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}
const bersihkan = <T extends object>(f: T) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>
const ifMatch = (etag: string) => ({ headers: { 'If-Match': etag } })
async function halaman<T>(jalur: string, filter: object, kursor: string | null, batas: number): Promise<Halaman<T>> {
  const { data } = await klien.get<Halaman<T>>(jalur, { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
/** Mengikuti kursor sampai habis (batas 200 per halaman), untuk anak satu induk atau daftar pilihan kecil. */
async function semua<T>(jalur: string, filter: object): Promise<T[]> {
  const hasil: T[] = []
  let kursor: string | null = null
  do {
    const h: Halaman<T> = await halaman<T>(jalur, filter, kursor, 200)
    hasil.push(...h.data)
    kursor = h.meta.kursor_berikutnya
  } while (kursor)
  return hasil
}

// ── Lokasi ──
export const daftarLokasi = (filter: FilterLokasi, kursor: string | null = null, batas = 50) => halaman<Lokasi>('/lokasi', filter, kursor, batas)
export const semuaAnakLokasi = (indukId: string | null) => semua<Lokasi>('/lokasi', indukId ? { induk_id: indukId } : { akar: true })
export const ambilLokasi = async (id: string) => berversi<Lokasi>(await klien.get(`/lokasi/${id}`))
export const buatLokasi = async (isian: IsianLokasi) => berversi<Lokasi>(await klien.post('/lokasi', isian))
export const ubahLokasi = async (id: string, etag: string, isian: UbahLokasi) => berversi<Lokasi>(await klien.patch(`/lokasi/${id}`, isian, ifMatch(etag)))
/** POST multipart; `pratinjau` tidak menyimpan apa pun, `terapkan` menyimpan semua atau tidak sama sekali (422 impor_tidak_valid). */
export async function imporLokasi(berkas: File, mode: 'pratinjau' | 'terapkan', dasar: string): Promise<HasilImporLokasi> {
  const isi = new FormData()
  isi.append('berkas', berkas)
  isi.append('mode', mode)
  isi.append('dasar_perubahan', dasar)
  const { data } = await klien.post<{ data: HasilImporLokasi }>('/lokasi/impor', isi)
  return data.data
}

// ── Bangsal ──
export const daftarBangsal = (filter: { cari?: string; status?: 'aktif' | 'nonaktif' }, kursor: string | null = null, batas = 50) =>
  halaman<Bangsal>('/bangsal', filter, kursor, batas)
export const semuaBangsalAktif = () => semua<Bangsal>('/bangsal', { status: 'aktif' })
export const ambilBangsal = async (id: string) => berversi<Bangsal>(await klien.get(`/bangsal/${id}`))
export const buatBangsal = async (isian: { kode: string; nama: string; unit_organisasi_id: string; dasar_perubahan: string }) =>
  berversi<Bangsal>(await klien.post('/bangsal', isian))
export const ubahBangsal = async (id: string, etag: string, isian: { kode?: string; nama?: string; unit_organisasi_id?: string; status?: 'aktif' | 'nonaktif'; dasar_perubahan: string }) =>
  berversi<Bangsal>(await klien.patch(`/bangsal/${id}`, isian, ifMatch(etag)))

// ── Kamar ──
export const daftarKamar = (filter: { bangsal_id?: string; cari?: string; melebihi_kapasitas?: boolean }, kursor: string | null = null, batas = 50) =>
  halaman<Kamar>('/kamar', filter, kursor, batas)
export const ambilKamar = async (id: string) => berversi<Kamar>(await klien.get(`/kamar/${id}`))
export const buatKamar = async (isian: { bangsal_id: string; lokasi_id: string; kapasitas_maksimum?: number | null; atribut_standar?: AtributKamar; dasar_perubahan: string }) =>
  berversi<Kamar>(await klien.post('/kamar', isian))
export const ubahKamar = async (id: string, etag: string, isian: { bangsal_id?: string; kapasitas_maksimum?: number | null; atribut_standar?: AtributKamar; dasar_perubahan: string }) =>
  berversi<Kamar>(await klien.patch(`/kamar/${id}`, isian, ifMatch(etag)))

// ── Tempat tidur ──
export const daftarTempatTidur = (filter: FilterTempatTidur, kursor: string | null = null, batas = 50) => halaman<TempatTidur>('/tempat-tidur', filter, kursor, batas)
export const semuaTempatTidurKamar = (kamarId: string) => semua<TempatTidur>('/tempat-tidur', { kamar_id: kamarId })
export const ambilTempatTidur = async (id: string) => berversi<TempatTidur>(await klien.get(`/tempat-tidur/${id}`))
/** 201 + `peringatan` (mis. melebihi_kapasitas): TT tetap dibuat. */
export async function buatTempatTidur(isian: IsianTempatTidur): Promise<Berversi<TempatTidur> & { peringatan: 'melebihi_kapasitas'[] }> {
  const r = await klien.post<{ data: TempatTidur; peringatan: 'melebihi_kapasitas'[] }>('/tempat-tidur', isian)
  return { ...berversi(r), peringatan: r.data.peringatan ?? [] }
}
export const ubahTempatTidur = async (
  id: string,
  etag: string,
  isian: { kelas_rawat_id?: string; aturan_jenis_kelamin?: TempatTidur['aturan_jenis_kelamin']; isolasi?: boolean; ekstra?: boolean; dasar_perubahan: string },
) => berversi<TempatTidur>(await klien.patch(`/tempat-tidur/${id}`, isian, ifMatch(etag)))
export const tutupTempatTidur = async (id: string, etag: string, isian: { berlaku_dari: string; alasan: string; dasar_perubahan: string }) =>
  berversi<TempatTidur>(await klien.post(`/tempat-tidur/${id}/tutup`, isian, ifMatch(etag)))

// ── Kelas rawat ──
export const daftarKelasRawat = (filter: { status?: 'aktif' | 'nonaktif' }, kursor: string | null = null, batas = 50) => halaman<KelasRawat>('/kelas-rawat', filter, kursor, batas)
export const semuaKelasRawatAktif = () => semua<KelasRawat>('/kelas-rawat', { status: 'aktif' })
export const ambilKelasRawat = async (id: string) => berversi<KelasRawat>(await klien.get(`/kelas-rawat/${id}`))
export const buatKelasRawat = async (isian: { kode: string; nama: string; kode_aplicares: string | null; berlaku_dari: string | null; dasar_perubahan: string }) =>
  berversi<KelasRawat>(await klien.post('/kelas-rawat', isian))
export const ubahKelasRawat = async (id: string, etag: string, isian: { kode?: string; nama?: string; kode_aplicares?: string | null; status?: 'aktif' | 'nonaktif'; dasar_perubahan: string }) =>
  berversi<KelasRawat>(await klien.patch(`/kelas-rawat/${id}`, isian, ifMatch(etag)))

// ── Poliklinik ──
export const daftarPoliklinik = (filter: { cari?: string; status?: 'aktif' | 'nonaktif' }, kursor: string | null = null, batas = 50) =>
  halaman<Poliklinik>('/poliklinik', filter, kursor, batas)
export const ambilPoliklinik = async (id: string) => berversi<Poliklinik>(await klien.get(`/poliklinik/${id}`))
export const buatPoliklinik = async (isian: { kode: string; nama: string; unit_organisasi_id: string; lokasi_id: string | null; kode_poli_bpjs: string | null; dasar_perubahan: string }) =>
  berversi<Poliklinik>(await klien.post('/poliklinik', isian))
export const ubahPoliklinik = async (
  id: string,
  etag: string,
  isian: { kode?: string; nama?: string; unit_organisasi_id?: string; lokasi_id?: string | null; kode_poli_bpjs?: string | null; status?: 'aktif' | 'nonaktif'; dasar_perubahan: string },
) => berversi<Poliklinik>(await klien.patch(`/poliklinik/${id}`, isian, ifMatch(etag)))

// ── Kapasitas ──
export const kapasitasTempatTidur = (filter: { tanggal?: string; bangsal_id?: string }, kursor: string | null = null, batas = 50) =>
  halaman<KapasitasBangsal>('/kapasitas-tempat-tidur', filter, kursor, batas)
