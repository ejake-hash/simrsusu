import type { AxiosResponse } from 'axios'
import type { HasilPolling } from '@/modules/notifikasi/api'
import type { Halaman } from '@/modules/akses/tipePengaturan'
import { klien } from '@/shared/api/klien'
import type {
  DaftarPeriksaPulangDataInduk,
  FilterPetaServer,
  IsianPindahRuang,
  IsianPulang,
  IsianRencanaPulang,
  IsianResumeMedis,
  IsianSpri,
  PermintaanTempatTidur,
  PetaTempatTidur,
  RawatInap,
  RencanaPulang,
  ResumeMedis,
  TempatTidurPeta,
  UbahDaftarPeriksaPulang,
} from './tipe'

/**
 * Klien peta tempat tidur (docs/api/openapi.yaml v0.35.0, tiket 2.27). Pola K1 sama dengan papan pasien IGD: If-None-Match
 * dengan ETag terakhir, 304 = data lama tetap; `latar` menambah X-Permintaan-Latar agar polling tidak memperpanjang sesi
 * yang diam (PRD 14.2). Hanya gedung dan bangsal yang disaring server; lantai, status, dan kode TT disaring di layar.
 */
export async function bacaPetaTempatTidur(filter: FilterPetaServer, polling: { etag: string | null; latar: boolean } = { etag: null, latar: false }): Promise<HasilPolling<PetaTempatTidur>> {
  const headers: Record<string, string> = {}
  if (polling.etag) headers['If-None-Match'] = polling.etag
  if (polling.latar) headers['X-Permintaan-Latar'] = '1'
  const r: AxiosResponse<PetaTempatTidur> = await klien.get('/peta-tempat-tidur', {
    params: { gedung_id: filter.gedung_id ?? undefined, bangsal_id: filter.bangsal_id ?? undefined },
    headers,
    validateStatus: (s) => (s >= 200 && s < 300) || s === 304,
  })
  if (r.status === 304) return { berubah: false }
  const e = r.headers.etag
  return { berubah: true, data: r.data, etag: typeof e === 'string' && e ? e : null }
}

/** GET /permintaan-tempat-tidur (v0.36.0): antrean aktif, terlama dulu, kursor keyset. */
export async function daftarPermintaanTempatTidur(status: 'menunggu' | 'dialokasikan' | null, kursor: string | null = null, batas = 50): Promise<Halaman<PermintaanTempatTidur>> {
  const { data } = await klien.get<Halaman<PermintaanTempatTidur>>('/permintaan-tempat-tidur', { params: { status: status ?? undefined, kursor: kursor ?? undefined, batas } })
  return data
}

/** POST /permintaan-tempat-tidur/{id}/alokasi: TT kosong siap → dipesan. */
export async function alokasikanTempatTidur(permintaanId: string, tempatTidurId: string): Promise<PermintaanTempatTidur> {
  const { data } = await klien.post<{ data: PermintaanTempatTidur }>(`/permintaan-tempat-tidur/${permintaanId}/alokasi`, { tempat_tidur_id: tempatTidurId })
  return data.data
}

/** POST /permintaan-tempat-tidur/{id}/tempatkan (v0.37.0): pasien tiba di bangsal, TT dipesan → terisi, kunjungan RI dibuka. */
export async function tempatkanPasien(permintaanId: string, serahTerima: string | null): Promise<RawatInap> {
  const { data } = await klien.post<{ data: RawatInap }>(`/permintaan-tempat-tidur/${permintaanId}/tempatkan`, { serah_terima: serahTerima })
  return data.data
}

/** POST /permintaan-tempat-tidur/{id}/batal (v0.37.0): permintaan menunggu/dialokasikan batal, TT pesanan kembali kosong siap. */
export async function batalkanPermintaanKamar(permintaanId: string, alasan: string): Promise<PermintaanTempatTidur> {
  const { data } = await klien.post<{ data: PermintaanTempatTidur }>(`/permintaan-tempat-tidur/${permintaanId}/batal`, { alasan })
  return data.data
}

/** POST /kunjungan/{id}/pindah-ruang (v0.37.0): TT lama → dibersihkan, TT tujuan → terisi; id kunjungan dari peta (v0.38.0). */
export async function pindahRuang(kunjunganId: string, isian: IsianPindahRuang): Promise<RawatInap> {
  const { data } = await klien.post<{ data: RawatInap }>(`/kunjungan/${kunjunganId}/pindah-ruang`, isian)
  return data.data
}

/** POST /kunjungan/{id}/spri: SPRI dokter poli → permintaan kamar menunggu (jam dan pemberi perintah dari server). */
export async function buatSpri(kunjunganId: string, isian: IsianSpri): Promise<PermintaanTempatTidur> {
  const { data } = await klien.post<{ data: PermintaanTempatTidur }>(`/kunjungan/${kunjunganId}/spri`, isian)
  return data.data
}

// ── Pemulangan (kontrak v0.39.0, tiket 2.40). If-Match = `"versi"` (ETag server sama dengan versi). ──
const etag = (versi: string) => `"${versi}"`

/** GET /kunjungan/{id}/rencana-pulang: rencana + daftar periksa + dua penanda identitas; `id` null = belum ada rencana. */
export async function bacaRencanaPulang(kunjunganId: string): Promise<RencanaPulang> {
  const { data } = await klien.get<{ data: RencanaPulang }>(`/kunjungan/${kunjunganId}/rencana-pulang`)
  return data.data
}

/** PUT /kunjungan/{id}/rencana-pulang: membuat (tanpa If-Match) atau mengubah (If-Match versi rencana). */
export async function simpanRencanaPulang(kunjunganId: string, versi: string | null, isian: IsianRencanaPulang): Promise<RencanaPulang> {
  const { data } = await klien.put<{ data: RencanaPulang }>(`/kunjungan/${kunjunganId}/rencana-pulang`, isian, versi ? { headers: { 'If-Match': etag(versi) } } : {})
  return data.data
}

/** POST /kunjungan/{id}/pulang: keluar lewat matriks; TT → dibersihkan, kunjungan dan episode selesai. */
export async function pulangkanPasien(kunjunganId: string, isian: IsianPulang): Promise<RawatInap> {
  const { data } = await klien.post<{ data: RawatInap }>(`/kunjungan/${kunjunganId}/pulang`, isian)
  return data.data
}

/** GET /kunjungan/{id}/resume-medis: null = belum disusun. */
export async function bacaResumeMedis(kunjunganId: string): Promise<ResumeMedis | null> {
  const { data } = await klien.get<{ data: ResumeMedis | null }>(`/kunjungan/${kunjunganId}/resume-medis`)
  return data.data
}

/** POST /kunjungan/{id}/resume-medis: susun/susun ulang draf (memotret ulang sumber); isian yang tidak dikirim tetap. */
export async function susunResumeMedis(kunjunganId: string, isian: Partial<IsianResumeMedis> = {}): Promise<ResumeMedis> {
  const { data } = await klien.post<{ data: ResumeMedis }>(`/kunjungan/${kunjunganId}/resume-medis`, isian)
  return data.data
}

/** POST /resume-medis/{id}/sahkan: PIN T1 DPJP, If-Match versi yang dibaca. */
export async function sahkanResumeMedis(resume: Pick<ResumeMedis, 'id' | 'versi'>, ttd: { pin: string | null; pernyataan_disetujui: boolean }): Promise<ResumeMedis> {
  const { data } = await klien.post<{ data: ResumeMedis }>(`/resume-medis/${resume.id}/sahkan`, ttd, { headers: { 'If-Match': etag(resume.versi) } })
  return data.data
}

/** GET /data-induk/daftar-periksa-pulang (kursor, urut kode). */
export async function daftarDaftarPeriksaPulang(status: 'aktif' | 'nonaktif' | null, kursor: string | null = null, batas = 50): Promise<Halaman<DaftarPeriksaPulangDataInduk>> {
  const { data } = await klien.get<Halaman<DaftarPeriksaPulangDataInduk>>('/data-induk/daftar-periksa-pulang', { params: { status: status ?? undefined, kursor: kursor ?? undefined, batas } })
  return data
}

/** PATCH /data-induk/daftar-periksa-pulang/{id}: If-Match `versi` baris daftar; dasar perubahan wajib. */
export async function ubahDaftarPeriksaPulang(butir: Pick<DaftarPeriksaPulangDataInduk, 'id' | 'versi'>, isian: UbahDaftarPeriksaPulang): Promise<DaftarPeriksaPulangDataInduk> {
  const { data } = await klien.patch<{ data: DaftarPeriksaPulangDataInduk }>(`/data-induk/daftar-periksa-pulang/${butir.id}`, isian, { headers: { 'If-Match': etag(butir.versi) } })
  return data.data
}

/** POST /tempat-tidur/{id}/status: satu-satunya transisi petugas di 2.27, dibersihkan → kosong siap. */
export async function tandaiKosongSiap(id: string): Promise<TempatTidurPeta> {
  const { data } = await klien.post<{ data: TempatTidurPeta }>(`/tempat-tidur/${id}/status`, { status: 'kosong_siap' })
  return data.data
}
