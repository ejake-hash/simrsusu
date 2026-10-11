import type { AxiosResponse } from 'axios'
import type { Berversi } from '@/modules/organisasi/api'
import type { Halaman } from '@/modules/akses/tipePengaturan'
import { klien } from '@/shared/api/klien'
import type { AkunDibuat, FilterPegawai, IsianIzinPraktik, IsianKewenangan, IsianPegawai, IzinPraktik, KewenanganKlinis, PegawaiRingkas, PegawaiRinci, UbahPegawai } from './tipe'

/** Klien SDM → pegawai, izin praktik, RKK, akun dari pegawai (docs/api/openapi.yaml v0.6.0+, tiket 1.8d). */

function berversi(r: AxiosResponse<{ data: PegawaiRinci }>): Berversi<PegawaiRinci> {
  const etag = r.headers.etag
  return { data: r.data.data, etag: typeof etag === 'string' && etag ? etag : `"${r.data.data.versi}"` }
}
const bersihkan = <T extends object>(f: T) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>

// ── Pegawai ──
export async function daftarPegawai(filter: FilterPegawai, kursor: string | null = null, batas = 50): Promise<Halaman<PegawaiRingkas>> {
  const { data } = await klien.get<Halaman<PegawaiRingkas>>('/pegawai', { params: { ...bersihkan(filter), kursor: kursor ?? undefined, batas } })
  return data
}
/** `tampilkanNik` hanya dari tombol "Tampilkan NIK"; hasilnya tidak disimpan di cache kueri (lihat RincianPegawai). */
export async function ambilPegawai(id: string, tampilkanNik = false): Promise<Berversi<PegawaiRinci>> {
  return berversi(await klien.get(`/pegawai/${id}`, { params: tampilkanNik ? { tampilkan_nik: true } : undefined }))
}
export async function buatPegawai(isian: IsianPegawai): Promise<Berversi<PegawaiRinci>> {
  return berversi(await klien.post('/pegawai', isian))
}
export async function ubahPegawai(id: string, etag: string, isian: UbahPegawai): Promise<Berversi<PegawaiRinci>> {
  return berversi(await klien.patch(`/pegawai/${id}`, isian, { headers: { 'If-Match': etag } }))
}

// ── Izin praktik ──
/** Riwayat izin praktik satu pegawai, semua halaman (jumlahnya kecil per pegawai). */
export async function semuaIzinPraktik(pegawaiId: string): Promise<IzinPraktik[]> {
  const hasil: IzinPraktik[] = []
  let kursor: string | null = null
  do {
    const { data }: { data: Halaman<IzinPraktik> } = await klien.get(`/pegawai/${pegawaiId}/izin-praktik`, { params: { kursor: kursor ?? undefined, batas: 200 } })
    hasil.push(...data.data)
    kursor = data.meta.kursor_berikutnya
  } while (kursor)
  return hasil
}
export async function buatIzinPraktik(pegawaiId: string, isian: IsianIzinPraktik): Promise<IzinPraktik> {
  const isi = new FormData()
  isi.append('jenis', isian.jenis)
  isi.append('nomor', isian.nomor)
  isi.append('terbit', isian.terbit)
  // String kosong = kosong (kontrak): hanya sah untuk STR seumur hidup.
  isi.append('berlaku_sampai', isian.berlaku_sampai ?? '')
  if (isian.berkas) isi.append('berkas', isian.berkas)
  const { data } = await klien.post<{ data: IzinPraktik }>(`/pegawai/${pegawaiId}/izin-praktik`, isi)
  return data.data
}
export async function batalkanIzinPraktik(pegawaiId: string, izinId: string, alasan: string): Promise<IzinPraktik> {
  const { data } = await klien.post<{ data: IzinPraktik }>(`/pegawai/${pegawaiId}/izin-praktik/${izinId}/batal`, { alasan_batal: alasan })
  return data.data
}
/**
 * Unduh dokumen izin praktik lewat klien (cookie sesi), lalu simpan dengan nama generik dari server
 * (`<jenis>-<id>.<ekstensi>`, tanpa nomor izin atau nama pegawai). Object URL dilepas segera sesudahnya.
 */
export async function unduhDokumenIzinPraktik(pegawaiId: string, izinId: string): Promise<void> {
  const r = await klien.get<Blob>(`/pegawai/${pegawaiId}/izin-praktik/${izinId}/dokumen`, { responseType: 'blob' })
  const disposisi = String(r.headers['content-disposition'] ?? '')
  const nama = /filename="?([^";]+)"?/i.exec(disposisi)?.[1] ?? `dokumen-${izinId}`
  const url = URL.createObjectURL(r.data)
  try {
    const a = document.createElement('a')
    a.href = url
    a.download = nama
    a.click()
  } finally {
    setTimeout(() => URL.revokeObjectURL(url), 0)
  }
}

// ── Kewenangan klinis (RKK) ──
export async function semuaKewenangan(pegawaiId: string): Promise<KewenanganKlinis[]> {
  const hasil: KewenanganKlinis[] = []
  let kursor: string | null = null
  do {
    const { data }: { data: Halaman<KewenanganKlinis> } = await klien.get(`/pegawai/${pegawaiId}/kewenangan-klinis`, { params: { kursor: kursor ?? undefined, batas: 200 } })
    hasil.push(...data.data)
    kursor = data.meta.kursor_berikutnya
  } while (kursor)
  return hasil
}
export async function buatKewenangan(pegawaiId: string, isian: IsianKewenangan): Promise<KewenanganKlinis> {
  const { data } = await klien.post<{ data: KewenanganKlinis }>(`/pegawai/${pegawaiId}/kewenangan-klinis`, isian)
  return data.data
}
export async function batalkanKewenangan(pegawaiId: string, kewenanganId: string, alasan: string): Promise<KewenanganKlinis> {
  const { data } = await klien.post<{ data: KewenanganKlinis }>(`/pegawai/${pegawaiId}/kewenangan-klinis/${kewenanganId}/batal`, { alasan_batal: alasan })
  return data.data
}

// ── Akun dari pegawai ──
export async function buatAkunPegawai(pegawaiId: string, isian: { nama_pengguna: string; email: string | null; dasar_pemberian: string }): Promise<AkunDibuat> {
  const { data } = await klien.post<{ data: AkunDibuat }>(`/pegawai/${pegawaiId}/akun`, isian)
  return data.data
}
