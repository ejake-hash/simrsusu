/**
 * Objek berlabel sensitivitas yang tidak boleh dibuka akun ini (PRD 9.2.2 lapis 4; kontrak v0.14.0 `Terbatas`). Server
 * mengirim `{ "terbatas": true }` sebagai pengganti isi, tanpa nama atau kode label (label itu sendiri data sensitif).
 * Endpoint data pasien memakainya mulai fase 2; layar menampilkan PenandaTerbatas di tempat isinya.
 */
export interface Terbatas {
  terbatas: true
}

export function apakahTerbatas(nilai: unknown): nilai is Terbatas {
  return typeof nilai === 'object' && nilai !== null && (nilai as { terbatas?: unknown }).terbatas === true
}
