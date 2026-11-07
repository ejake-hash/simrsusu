import type { DisplayAntrean, PanggilanDisplay } from './tipe'

/**
 * Display antrean (tiket 2.8). Token perangkat dibawa sekali lewat fragmen URL (`#token=...`), disimpan di localStorage
 * khusus display, lalu fragmen dihapus dari URL. Tidak pernah di query string atau path (tercatat di log server, riwayat).
 */
const KUNCI_TOKEN = 'karsitara.display.token'

export function bacaTokenTersimpan(): string | null {
  try {
    return localStorage.getItem(KUNCI_TOKEN)
  } catch {
    return null
  }
}
export function simpanToken(token: string): void {
  try {
    localStorage.setItem(KUNCI_TOKEN, token)
  } catch {
    // Penyimpanan diblokir: token hanya berlaku sampai halaman ditutup.
  }
}
export function lupakanToken(): void {
  try {
    localStorage.removeItem(KUNCI_TOKEN)
  } catch {
    // Tidak ada yang perlu dilupakan.
  }
}

/** Token dari fragmen URL; null bila tidak ada. */
export function tokenDariFragmen(fragmen: string): string | null {
  const nilai = new URLSearchParams(fragmen.replace(/^#/, '')).get('token')?.trim()
  return nilai ? nilai : null
}

/** Ambil token dari fragmen (lalu simpan dan bersihkan URL) atau dari penyimpanan. */
export function siapkanToken(): string | null {
  const baru = tokenDariFragmen(window.location.hash)
  if (baru) {
    simpanToken(baru)
    history.replaceState(history.state, '', window.location.pathname + window.location.search)
    return baru
  }
  return bacaTokenTersimpan()
}

export type HasilBaca = { jenis: 'baru'; data: DisplayAntrean; etag: string | null } | { jenis: 'sama' } | { jenis: 'ditolak'; status: 401 | 403 | 404 } | { jenis: 'gagal' }

/** Satu pembacaan GET /display/{area}; If-None-Match membuat server menjawab 304 saat isi tidak berubah. */
export async function bacaDisplay(area: string, token: string, etag: string | null): Promise<HasilBaca> {
  try {
    const r = await fetch(`/api/v1/display/${encodeURIComponent(area)}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(etag ? { 'If-None-Match': etag } : {}) },
      cache: 'no-store',
      credentials: 'omit',
    })
    if (r.status === 304) return { jenis: 'sama' }
    if (r.status === 401 || r.status === 403 || r.status === 404) return { jenis: 'ditolak', status: r.status }
    if (!r.ok) return { jenis: 'gagal' }
    return { jenis: 'baru', data: ((await r.json()) as { data: DisplayAntrean }).data, etag: r.headers.get('ETag') }
  } catch {
    return { jenis: 'gagal' }
  }
}

/** Pasangan yang menentukan satu panggilan; panggilan ulang (`panggilan_ke` naik) diputar lagi. */
export const kunciPanggilan = (p: PanggilanDisplay) => `${p.nomor_tampil}|${p.panggilan_ke}|${p.dipanggil_pada}`

/** Panggilan yang belum diputar, yang paling lama lebih dulu (respons mengurutkan terbaru dulu). */
export function panggilanBaru(sudah: ReadonlySet<string>, dipanggil: readonly PanggilanDisplay[]): PanggilanDisplay[] {
  return dipanggil.filter((p) => !sudah.has(kunciPanggilan(p))).reverse()
}

/** Suara bawaan peramban (Web Speech API), hanya `teks_suara` dari server (tanpa nama). false = peramban tak mendukung. */
export function ucapkan(teks: string): boolean {
  if (typeof speechSynthesis === 'undefined') return false
  const u = new SpeechSynthesisUtterance(teks)
  u.lang = 'id-ID'
  speechSynthesis.speak(u)
  return true
}
