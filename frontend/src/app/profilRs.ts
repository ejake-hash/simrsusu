/**
 * Profil RS untuk halaman masuk. Sementara dari variabel Vite (.env.*); nanti dari
 * inti.profil_rs lewat API (PRD 3.4). Nilai kosong = bagian itu tidak ditampilkan.
 */
export interface ProfilRs {
  /** Nama resmi RS di kartu RS; tanpa nama, kartu RS tidak tampil. */
  nama: string | null
  logo: string | null
  /** Motif grafis di panel merek halaman masuk (PRD 5.3.2 aturan 4); bukan untuk layar kerja. */
  motif: string | null
  /** Kontak admin SIMRS untuk lupa sandi/akun terkunci, mis. "ext. 1234". */
  kontakAdmin: string | null
}

export function profilRsDariEnv(env: Record<string, string | undefined> = import.meta.env): ProfilRs {
  const isi = (nilai: string | undefined) => nilai?.trim() || null
  return {
    nama: isi(env.VITE_RS_NAMA),
    logo: isi(env.VITE_RS_LOGO),
    motif: isi(env.VITE_RS_MOTIF),
    kontakAdmin: isi(env.VITE_RS_KONTAK_ADMIN),
  }
}
