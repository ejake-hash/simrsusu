/**
 * Sisa waktu untuk pesan "coba lagi dalam …". Dibulatkan ke atas per menit agar teks (yang
 * dibacakan pembaca layar) berubah paling sering sekali semenit. null = waktunya sudah habis.
 */
export function formatSisaWaktu(detik: number): string | null {
  if (detik <= 0) return null
  if (detik < 60) return 'kurang dari 1 menit'
  return `${Math.ceil(detik / 60)} menit`
}
