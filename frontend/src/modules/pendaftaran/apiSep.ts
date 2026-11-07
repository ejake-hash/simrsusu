import { klien } from '@/shared/api/klien'
import type { AsalRujukan, IsianSep, PesertaBpjs, RujukanBpjs, Sep } from './tipeSep'

/**
 * Klien /bpjs/peserta, /bpjs/rujukan, dan POST /kunjungan/{id}/sep (docs/api/openapi.yaml v0.21.0, tiket 2.7).
 * No. kartu, NIK, dan nomor rujukan dikirim di header `X-Nomor` (di-encode persen), tidak di URL (PRD 14.2).
 */
export async function bacaPeserta(nomor: string, tanggal: string): Promise<PesertaBpjs> {
  const { data } = await klien.get<{ data: PesertaBpjs }>('/bpjs/peserta', { params: { tanggal }, headers: { 'X-Nomor': encodeURIComponent(nomor) } })
  return data.data
}

export async function bacaRujukan(nomor: string, tanggal: string, asal: AsalRujukan | null): Promise<RujukanBpjs> {
  const { data } = await klien.get<{ data: RujukanBpjs }>('/bpjs/rujukan', {
    params: { tanggal, asal: asal ?? undefined },
    headers: { 'X-Nomor': encodeURIComponent(nomor) },
  })
  return data.data
}

/** 201 = terbit, 202 = tersimpan menunggu (ditunda atau BPJS tidak menjawab); keduanya mengembalikan Sep. */
export async function terbitkanSep(kunjunganId: string, isian: IsianSep): Promise<Sep> {
  const { data } = await klien.post<{ data: Sep }>(`/kunjungan/${kunjunganId}/sep`, isian, { headers: { 'Idempotency-Key': crypto.randomUUID() } })
  return data.data
}
