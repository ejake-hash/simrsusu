import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { Pelaku, RingkasPasienKunjungan } from '@/modules/pendaftaran/tipeKunjungan'
import { klien } from '@/shared/api/klien'

/** Akses darurat (break-glass) RM satu pasien dan tinjauannya (docs/api/openapi.yaml v0.29.0, tiket 2.21; FR-26-12, FR-26-26, UC-33). */
export type KategoriAlasanAksesRm = 'audit' | 'klaim' | 'hukum' | 'pelayanan_lanjutan'
export type HasilTinjauan = 'wajar' | 'tidak_wajar'
export type StatusTinjauan = 'menunggu' | 'ditinjau'

export interface AksesDarurat {
  id: string
  pasien: RingkasPasienKunjungan
  pengguna: Pelaku
  kategori_alasan: KategoriAlasanAksesRm
  alasan: string
  mulai_pada: string
  berakhir_pada: string
  aktif: boolean
  jumlah_akses: number
  tinjauan: { hasil: HasilTinjauan; catatan: string; oleh: Pelaku; pada: string } | null
  dapat: { tinjau: boolean }
}

/** 201 = baru dibuka, 200 = masih ada akses aktif untuk pasien itu (tidak diperpanjang). */
export async function bukaAksesDarurat(pasienId: string, isian: { kategori_alasan: KategoriAlasanAksesRm; alasan: string }): Promise<AksesDarurat> {
  return (await klien.post<{ data: AksesDarurat }>(`/pasien/${pasienId}/akses-darurat`, isian)).data.data
}
export async function daftarAksesDarurat(status: StatusTinjauan, kursor: string | null = null, batas = 50): Promise<Halaman<AksesDarurat>> {
  return (await klien.get<Halaman<AksesDarurat>>('/akses-darurat', { params: { status, kursor: kursor ?? undefined, batas } })).data
}
export async function tinjauAksesDarurat(id: string, isian: { hasil: HasilTinjauan; catatan: string }): Promise<AksesDarurat> {
  return (await klien.post<{ data: AksesDarurat }>(`/akses-darurat/${id}/tinjau`, isian)).data.data
}
