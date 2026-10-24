import type { AxiosResponse } from 'axios'
import type { Berversi } from '@/modules/organisasi/api'
import { klien } from '@/shared/api/klien'
import type { HalamanGabung, HalamanPasien, IsianPasien, Pasien, TahapGabung, UbahPasien, UsulanGabung } from './tipe'

/**
 * Klien /pasien (docs/api/openapi.yaml v0.19.0, tiket 2.5). Kata cari lewat header `X-Cari`, bukan query string,
 * supaya NIK/No. RM/nama pasien tidak pernah muncul di URL atau log (PRD 14.2). Respons tidak di-cache (no-store).
 */
const JALUR = '/pasien'

function berversi(r: AxiosResponse<{ data: Pasien }>): Berversi<Pasien> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}

/** Nilai `X-Cari`: di-encode persen UTF-8 (encodeURIComponent) seperti contoh kontrak. */
export async function cariPasien(kata: string, kursor: string | null = null, batas = 50): Promise<HalamanPasien> {
  const { data } = await klien.get<HalamanPasien>(JALUR, {
    params: { kursor: kursor ?? undefined, batas },
    headers: { 'X-Cari': encodeURIComponent(kata) },
  })
  return data
}

export const buatPasien = async (isian: IsianPasien) => berversi(await klien.post(JALUR, isian))
export const ubahPasien = async (id: string, etag: string, isian: UbahPasien) =>
  berversi(await klien.patch(`${JALUR}/${id}`, isian, { headers: { 'If-Match': etag } }))

/** Peringatan sebelum simpan (kontrak v0.21.0): pasien berlaku, tanggal lahir sama, nama mirip. Nama di badan, bukan URL. */
export async function cekDuplikatPasien(nama: string, tanggalLahir: string): Promise<Pasien[]> {
  const { data } = await klien.post<{ data: Pasien[] }>(`${JALUR}/cek-duplikat`, { nama, tanggal_lahir: tanggalLahir })
  return data.data
}

/** Usulan gabung RM (tiket 2.9): usul oleh pendaftaran, verifikasi oleh Rekam Medis. */
export async function daftarGabung(tahap: TahapGabung | null, kursor: string | null = null, batas = 50): Promise<HalamanGabung> {
  const { data } = await klien.get<HalamanGabung>('/gabung-pasien', { params: { tahap: tahap ?? undefined, kursor: kursor ?? undefined, batas } })
  return data
}
export async function usulkanGabung(sumberId: string, tujuanId: string, alasan: string): Promise<UsulanGabung> {
  const { data } = await klien.post<{ data: UsulanGabung }>('/gabung-pasien', { pasien_sumber_id: sumberId, pasien_tujuan_id: tujuanId, alasan })
  return data.data
}
export async function verifikasiGabung(id: string, keputusan: 'setuju' | 'tolak', catatan: string | null): Promise<UsulanGabung> {
  const { data } = await klien.post<{ data: UsulanGabung }>(`/gabung-pasien/${id}/verifikasi`, { keputusan, catatan })
  return data.data
}
