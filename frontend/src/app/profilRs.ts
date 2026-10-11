/**
 * Profil RS dan tanda instalasi demo. Sementara dari variabel Vite (.env.*); nanti dari
 * GET /profil-rs (inti.profil_rs, tiket 1.8a; PRD 3.4). Nilai kosong = bagian itu tidak ditampilkan.
 */
export interface ProfilRs {
  /** Nama resmi RS di kartu RS; tanpa nama, kartu RS tidak tampil. */
  nama: string | null
  logo: string | null
  /** Motif grafis di panel merek halaman masuk (PRD 5.3.2 aturan 4); bukan untuk layar kerja. */
  motif: string | null
  /** Foto gedung RS di panel merek halaman masuk; tanpa foto, bagian itu tidak tampil. */
  foto: string | null
  /** Unit yang mengelola akun, dipakai di teks halaman masuk; bawaan "admin SIMRS". */
  unitAdmin: string
  /** Kontak unit itu untuk lupa sandi/akun terkunci, mis. "ext. 1234". */
  kontakAdmin: string | null
  /** Instalasi demo/pelatihan berisi data sintetis (FR-54-10): pita "DEMO — data fiktif" di setiap layar. */
  demo: boolean
}

const UNIT_ADMIN_BAWAAN = 'admin SIMRS'

export function profilRsDariEnv(env: Record<string, string | undefined> = import.meta.env): ProfilRs {
  const isi = (nilai: string | undefined) => nilai?.trim() || null
  return {
    nama: isi(env.VITE_RS_NAMA),
    logo: isi(env.VITE_RS_LOGO),
    motif: isi(env.VITE_RS_MOTIF),
    foto: isi(env.VITE_RS_FOTO),
    unitAdmin: isi(env.VITE_RS_UNIT_ADMIN) ?? UNIT_ADMIN_BAWAAN,
    kontakAdmin: isi(env.VITE_RS_KONTAK_ADMIN),
    demo: env.VITE_INSTALASI_DEMO === 'true',
  }
}
