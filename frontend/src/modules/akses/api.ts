import type { StatusTtd } from '@/modules/ttd/tipe'
import axios from 'axios'
import { keApiError, klien } from '@/shared/api/klien'
import { langkahDariRespons, type Langkah, type MetodeMfa } from './alurMasuk'
import type { MenuSaya } from './tipeMenu'
import type { IsianSandi } from './validasi'

export type { GrupMenu, Menu, MenuSaya, StatusMenu, SubMenu } from './tipeMenu'

/** GET /saya (docs/api/openapi.yaml v0.2.3 → Saya); juga `pengguna` di respons masuk. */
export interface Pengguna {
  id: string
  nama_pengguna: string
  login_terakhir_pada: string | null
  mfa_aktif: boolean
  /** Sejak 1.8d: akun bersandi awal; endpoint bersesi lain 403 wajib_ganti_sandi sampai POST /saya/sandi. */
  wajib_ganti_sandi?: boolean
  /** Sejak 1.6: status TTE T1 akun ini (tanpa kunci atau PIN). */
  ttd: StatusTtd
  pegawai: { id: string; nama: string } | null
  /** Peran dengan penugasan yang berlaku sekarang, urut nama; kosong = belum ada modul. */
  peran: { id: string; nama: string }[]
  /** Masuk berhasil terakhir selain sesi ini (juga dari perangkat lain sesudah sesi dibuka); null = tidak ada catatan. */
  masuk_sebelumnya_pada: string | null
  masuk_sebelumnya_dari_jaringan_rs: boolean | null
}

export interface RahasiaMfa {
  uri_otpauth: string
  qr_svg: string
}

/** Langkah 1 kontrak 10.3.2: cookie CSRF dulu, lalu nama pengguna + sandi. */
export async function kirimSandi(isian: IsianSandi): Promise<Langkah> {
  try {
    // /sanctum ada di luar /api/v1, jadi tidak lewat baseURL klien.
    await axios.get('/sanctum/csrf-cookie', { headers: { Accept: 'application/json' } })
  } catch (error) {
    throw keApiError(error)
  }

  const { data } = await klien.post('/autentikasi/masuk', isian, { tangani401Sendiri: true })
  return langkahDariRespons(data)
}

/** Langkah 2a: kode TOTP atau kode cadangan. */
export async function kirimKodeMfa(challengeId: string, metode: MetodeMfa, kode: string): Promise<Langkah> {
  const { data } = await klien.post('/autentikasi/mfa', { challenge_id: challengeId, metode, kode }, { tangani401Sendiri: true })
  return langkahDariRespons(data)
}

/** Langkah 2b: rahasia baru (URI otpauth + QR SVG) untuk dipindai. Hanya di memori komponen. */
export async function mintaRahasiaMfa(challengeId: string): Promise<RahasiaMfa> {
  const { data } = await klien.post<RahasiaMfa>('/autentikasi/mfa/daftar', { challenge_id: challengeId }, { tangani401Sendiri: true })
  return data
}

/** Langkah 2c: konfirmasi pendaftaran; kode cadangan hanya dikirim server sekali di sini. */
export async function konfirmasiDaftarMfa(challengeId: string, kode: string): Promise<{ langkah: Langkah; kodeCadangan: string[] }> {
  const { data } = await klien.post<{ kode_cadangan?: unknown }>(
    '/autentikasi/mfa/daftar/konfirmasi',
    { challenge_id: challengeId, kode },
    { tangani401Sendiri: true },
  )
  const kodeCadangan = Array.isArray(data.kode_cadangan) ? data.kode_cadangan.map(String) : []
  return { langkah: langkahDariRespons(data), kodeCadangan }
}

// Respons terakhir per ETag, hanya di memori dan dibuang saat sesi berakhir (lupakanMenuSaya).
let menuTerakhir: { etag: string; data: MenuSaya } | null = null

/** Menu efektif akun. 304 (versi sama) = pakai respons terakhir tanpa mengunduh ulang. */
export async function ambilMenuSaya(): Promise<MenuSaya> {
  const sebelumnya = menuTerakhir
  const respons = await klien.get<{ data: MenuSaya }>('/saya/menu', {
    headers: sebelumnya ? { 'If-None-Match': sebelumnya.etag } : {},
    validateStatus: (status) => status === 200 || (status === 304 && sebelumnya !== null),
  })
  if (respons.status === 304 && sebelumnya) return sebelumnya.data

  const etag = respons.headers.etag
  menuTerakhir = typeof etag === 'string' && etag ? { etag, data: respons.data.data } : null
  return respons.data.data
}

export function lupakanMenuSaya(): void {
  menuTerakhir = null
}

export async function ambilSaya(): Promise<Pengguna> {
  const { data } = await klien.get<{ data: Pengguna }>('/saya', { tangani401Sendiri: true })
  return data.data
}

/** Ganti sandi akun yang masuk (kontrak v0.6.0). Sesi ini tetap; sesi lain akun ini diputus server. */
export async function gantiSandi(isian: { sandi_lama: string; sandi_baru: string }): Promise<Pengguna> {
  const { data } = await klien.post<{ data: Pengguna }>('/saya/sandi', isian)
  return data.data
}

export async function keluar(): Promise<void> {
  await klien.post('/autentikasi/keluar')
}

/**
 * MFA ulang di sesi berjalan (kontrak v0.14.0, K11): sesudah berhasil, aksi `butuh_mfa` boleh sampai `berlaku_sampai`.
 * 422 kode_mfa_salah dihitung ke penguncian yang sama dengan masuk.
 */
export async function verifikasiUlangMfa(metode: MetodeMfa, kode: string): Promise<{ mfa_terverifikasi_pada: string; berlaku_sampai: string }> {
  const { data } = await klien.post<{ data: { mfa_terverifikasi_pada: string; berlaku_sampai: string } }>('/saya/mfa/verifikasi-ulang', { metode, kode })
  return data.data
}
