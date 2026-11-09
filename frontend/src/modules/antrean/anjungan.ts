import type { AlasanPrioritas, AnjunganPerangkat, JadwalAnjungan, TiketLoket } from './tipe'

/**
 * Anjungan pendaftaran mandiri tahap 1 (tiket 2.20, PRD 7.01.1). Perangkat bertoken, bukan akun orang: token dibawa sekali
 * lewat fragmen URL (`#token=...`, pola display 2.8), disimpan di localStorage khusus anjungan, lalu fragmen dihapus. Tidak
 * pernah di query string atau path. Permintaan tanpa cookie (`credentials: 'omit'`), jadi sesi petugas di peramban yang
 * sama tidak ikut terkirim. Tanpa data pasien di permintaan maupun respons (FR-01-28).
 */
const KUNCI_TOKEN = 'karsitara.anjungan.token'

export function bacaTokenAnjungan(): string | null {
  try {
    return localStorage.getItem(KUNCI_TOKEN)
  } catch {
    return null
  }
}
function simpanTokenAnjungan(token: string): void {
  try {
    localStorage.setItem(KUNCI_TOKEN, token)
  } catch {
    // Penyimpanan diblokir: token hanya berlaku sampai halaman ditutup.
  }
}
export function lupakanTokenAnjungan(): void {
  try {
    localStorage.removeItem(KUNCI_TOKEN)
  } catch {
    // Tidak ada yang perlu dilupakan.
  }
}

/** Token dari fragmen URL (lalu simpan dan bersihkan URL) atau dari penyimpanan. */
export function siapkanTokenAnjungan(): string | null {
  const baru = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('token')?.trim()
  if (baru) {
    simpanTokenAnjungan(baru)
    history.replaceState(history.state, '', window.location.pathname + window.location.search)
    return baru
  }
  return bacaTokenAnjungan()
}

export type Hasil<T> =
  | { jenis: 'ok'; data: T; meta?: { kursor_berikutnya: string | null } }
  /** 401/403: token salah, dicabut, perangkat nonaktif, atau bukan token anjungan. */
  | { jenis: 'ditolak'; status: 401 | 403 }
  /** Penolakan lain dari server (409 mode, 422 validasi, 429 batas laju). */
  | { jenis: 'galat'; status: number; kode: string }
  /** Jaringan putus atau server tidak menjawab. */
  | { jenis: 'gagal' }

async function minta<T>(jalur: string, token: string, init: RequestInit = {}): Promise<Hasil<T>> {
  try {
    const r = await fetch(`/api/v1/anjungan${jalur}`, {
      ...init,
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(init.body ? { 'Content-Type': 'application/json' } : {}) },
      cache: 'no-store',
      credentials: 'omit',
    })
    if (r.status === 401 || r.status === 403) return { jenis: 'ditolak', status: r.status }
    const isi = (await r.json().catch(() => null)) as { data?: T; meta?: { kursor_berikutnya: string | null }; error?: { kode?: string } } | null
    if (!r.ok || !isi || isi.data === undefined) return r.ok ? { jenis: 'gagal' } : { jenis: 'galat', status: r.status, kode: isi?.error?.kode ?? '' }
    return { jenis: 'ok', data: isi.data, meta: isi.meta }
  } catch {
    return { jenis: 'gagal' }
  }
}

/** GET /anjungan: konfigurasi perangkat, sekaligus tanda online (FR-01-30). */
export const bacaAnjungan = (token: string) => minta<AnjunganPerangkat>('', token)

export const ambilNomorLoket = (token: string, alasan: AlasanPrioritas | null) =>
  minta<TiketLoket>('/nomor-loket', token, { method: 'POST', body: JSON.stringify({ prioritas: alasan !== null, alasan_prioritas: alasan }) })

/** Satu tanggal, semua halaman (jadwal satu hari kecil; batas 200 per halaman, berhenti di 5 halaman). */
export async function jadwalAnjungan(token: string, tanggal: string): Promise<Hasil<JadwalAnjungan[]>> {
  const semua: JadwalAnjungan[] = []
  let kursor: string | null = null
  for (let i = 0; i < 5; i++) {
    const q: URLSearchParams = new URLSearchParams({ tanggal, batas: '200', ...(kursor ? { kursor } : {}) })
    const h: Hasil<JadwalAnjungan[]> = await minta<JadwalAnjungan[]>(`/jadwal-praktik?${q}`, token)
    if (h.jenis !== 'ok') return h
    semua.push(...h.data)
    kursor = h.meta?.kursor_berikutnya ?? null
    if (!kursor) break
  }
  return { jenis: 'ok', data: semua }
}

export const LABEL_ALASAN_PRIORITAS: Record<AlasanPrioritas, string> = {
  lansia: 'Lansia',
  disabilitas: 'Penyandang disabilitas',
  ibu_hamil: 'Ibu hamil',
  anak_khusus: 'Anak dengan kondisi khusus',
}

export const LABEL_STATUS_JADWAL: Record<JadwalAnjungan['status'], string> = {
  tersedia: 'Tersedia',
  penuh: 'Penuh',
  tidak_praktik: 'Tidak praktik',
}

/** Jadwal dikelompokkan per poli (urutan kemunculan dari server: jam mulai). */
export function kelompokPerPoli(jadwal: readonly JadwalAnjungan[]): { poli: JadwalAnjungan['poliklinik']; jadwal: JadwalAnjungan[] }[] {
  const peta = new Map<string, { poli: JadwalAnjungan['poliklinik']; jadwal: JadwalAnjungan[] }>()
  for (const j of jadwal) {
    const k = peta.get(j.poliklinik.id) ?? { poli: j.poliklinik, jadwal: [] }
    k.jadwal.push(j)
    peta.set(j.poliklinik.id, k)
  }
  return [...peta.values()].sort((a, b) => a.poli.nama.localeCompare(b.poli.nama, 'id'))
}

/** Hari ini sampai 6 hari ke depan (batas server), tanggal kalender perangkat. */
export function pilihanTanggal(hariIni: Date): { nilai: string; label: string }[] {
  const fmt = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long' })
  return Array.from({ length: 7 }, (_, i) => {
    const t = new Date(hariIni.getFullYear(), hariIni.getMonth(), hariIni.getDate() + i)
    const nilai = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`
    return { nilai, label: i === 0 ? `Hari ini, ${fmt.format(t)}` : i === 1 ? `Besok, ${fmt.format(t)}` : fmt.format(t) }
  })
}

/** Pesan layar kios untuk penolakan server; bahasa pasien, tanpa istilah teknis. */
export function pesanAnjungan(h: Exclude<Hasil<unknown>, { jenis: 'ok' }>): string {
  if (h.jenis === 'gagal') return 'Layanan sedang tidak terhubung. Silakan coba lagi atau minta bantuan petugas.'
  if (h.jenis === 'ditolak') return 'Anjungan ini belum siap dipakai. Silakan minta bantuan petugas.'
  if (h.status === 429) return 'Anjungan sedang ramai. Tunggu sebentar, lalu coba lagi.'
  if (h.kode === 'mode_belum_didukung') return 'Layanan ini belum tersedia di anjungan ini. Silakan ke loket.'
  return 'Permintaan belum dapat diproses. Silakan coba lagi atau minta bantuan petugas.'
}
