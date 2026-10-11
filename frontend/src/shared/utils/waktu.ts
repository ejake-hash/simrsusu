/**
 * Sisa waktu untuk pesan "coba lagi dalam …". Dibulatkan ke atas per menit agar teks (yang
 * dibacakan pembaca layar) berubah paling sering sekali semenit. null = waktunya sudah habis.
 */
export function formatSisaWaktu(detik: number): string | null {
  if (detik <= 0) return null
  if (detik < 60) return 'kurang dari 1 menit'
  return `${Math.ceil(detik / 60)} menit`
}

const FORMAT_TANGGAL = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
const FORMAT_JAM = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })

/** "2 Oktober 2026" (PRD 5.4 format Indonesia). null/kosong → null agar pemanggil memilih teks penggantinya. */
export function formatTanggal(iso: string | null | undefined): string | null {
  if (!iso) return null
  const t = new Date(iso)
  return Number.isNaN(t.getTime()) ? null : FORMAT_TANGGAL.format(t)
}

/** "2 Oktober 2026, 10.30" (jam lokal perangkat; PRD 5.4: titik sebagai pemisah jam). */
export function formatTanggalWaktu(iso: string | null | undefined): string | null {
  if (!iso) return null
  const t = new Date(iso)
  return Number.isNaN(t.getTime()) ? null : `${FORMAT_TANGGAL.format(t)}, ${FORMAT_JAM.format(t).replace(':', '.')}`
}

/** Nilai `<input type="date">` (tanggal lokal) → ISO 8601. `akhirHari` = 23.59.59 lokal (batas "sampai"). */
export function tanggalKeIso(tanggal: string, akhirHari = false): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(tanggal)) return null
  const [th, bl, hr] = tanggal.split('-').map(Number) as [number, number, number]
  const t = akhirHari ? new Date(th, bl - 1, hr, 23, 59, 59) : new Date(th, bl - 1, hr, 0, 0, 0)
  return t.toISOString()
}
