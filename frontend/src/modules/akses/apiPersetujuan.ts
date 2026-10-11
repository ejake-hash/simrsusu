import type { AxiosResponse } from 'axios'
import { klien } from '@/shared/api/klien'
import type { Halaman, PohonMenu } from './tipePengaturan'
import type { PermintaanAkses, PersetujuanAkses, RingkasPeran, SaranPeran, StatusPermintaan, StatusPersetujuan, TabPersetujuan } from './tipePersetujuan'

/** Klien persetujuan dan permintaan akses (docs/api/openapi.yaml v0.4.0, tiket 1.2d). */

const BATAS = 50
const data = <T>(r: AxiosResponse<{ data: T }>) => r.data.data

// ── Persetujuan akses ──
export async function daftarPersetujuan(tab: TabPersetujuan, status: StatusPersetujuan | undefined, kursor: string | null = null, batas = BATAS): Promise<Halaman<PersetujuanAkses>> {
  const { data: h } = await klien.get<Halaman<PersetujuanAkses>>('/persetujuan-akses', { params: { tab, status, kursor: kursor ?? undefined, batas } })
  return h
}
export async function ambilPersetujuan(id: string): Promise<PersetujuanAkses> {
  return data(await klien.get(`/persetujuan-akses/${id}`))
}
export async function setujuiPersetujuan(id: string, catatan: string | null): Promise<PersetujuanAkses> {
  return data(await klien.post(`/persetujuan-akses/${id}/setujui`, { catatan }))
}
export async function tolakPersetujuan(id: string, alasan: string): Promise<PersetujuanAkses> {
  return data(await klien.post(`/persetujuan-akses/${id}/tolak`, { alasan }))
}
export async function batalkanPersetujuan(id: string, alasan: string): Promise<PersetujuanAkses> {
  return data(await klien.post(`/persetujuan-akses/${id}/batalkan`, { alasan }))
}

// ── Permintaan akses ──
export async function daftarPermintaan(filter: { milik_saya?: boolean; status?: StatusPermintaan }, kursor: string | null = null, batas = BATAS): Promise<Halaman<PermintaanAkses>> {
  const { data: h } = await klien.get<Halaman<PermintaanAkses>>('/permintaan-akses', { params: { ...filter, kursor: kursor ?? undefined, batas } })
  return h
}
export async function buatPermintaan(isian: { sasaran_jenis: 'menu' | 'izin'; sasaran_kode: string; alasan: string }): Promise<PermintaanAkses> {
  return data(await klien.post('/permintaan-akses', isian))
}
export async function tolakPermintaan(id: string, alasan: string): Promise<PermintaanAkses> {
  return data(await klien.post(`/permintaan-akses/${id}/tolak`, { alasan }))
}

// ── Penyetuju izin sensitif dan saran peran ──
/** 202 = penggantian penyetuju menunggu persetujuan superadmin lain (kontrol ganda K3/K13). */
export async function aturPenyetujuIzin(
  kode: string,
  isian: { peran_id: string; dasar_perubahan: string },
): Promise<{ menunggu: false; penyetuju: RingkasPeran | null } | { menunggu: true; persetujuan: PersetujuanAkses }> {
  const r = await klien.put<{ data: { penyetuju_peran: RingkasPeran | null } | PersetujuanAkses }>(`/izin/${encodeURIComponent(kode)}/penyetuju`, isian)
  return r.status === 202
    ? { menunggu: true, persetujuan: r.data.data as PersetujuanAkses }
    : { menunggu: false, penyetuju: (r.data.data as { penyetuju_peran: RingkasPeran | null }).penyetuju_peran }
}
export async function ambilSaranPeran(): Promise<SaranPeran[]> {
  return data(await klien.get('/akun/saran-peran'))
}

/** Penyetuju izin sensitif dari pohon menu (NodeMenu.izin[].penyetuju_peran), dibaca tanpa permintaan baru. */
export function petaPenyetuju(pohon: PohonMenu): Map<string, RingkasPeran | null> {
  const peta = new Map<string, RingkasPeran | null>()
  const jalan = (n: PohonMenu['grup'][number]['menu']) =>
    n.forEach((m) => {
      m.izin.forEach((i) => i.sensitif && peta.set(i.kode, i.penyetuju_peran ?? null))
      jalan(m.anak)
    })
  pohon.grup.forEach((g) => jalan(g.menu))
  return peta
}
