/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Nama resmi RS di kartu RS (sementara, sampai profil RS dari API). */
  readonly VITE_RS_NAMA?: string
  /** Jalur logo RS di public/, mis. /logo/rs-usu.png. */
  readonly VITE_RS_LOGO?: string
  /** Kontak admin SIMRS di teks bantuan halaman masuk, mis. "ext. 1234". */
  readonly VITE_RS_KONTAK_ADMIN?: string
}
