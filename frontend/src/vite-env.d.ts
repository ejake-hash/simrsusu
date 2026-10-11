/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Nama resmi RS di kartu RS (sementara, sampai profil RS dari API). */
  readonly VITE_RS_NAMA?: string
  /** Jalur logo RS di public/, mis. /logo/rs-usu.png. */
  readonly VITE_RS_LOGO?: string
  /** Jalur motif grafis RS di public/ untuk panel merek halaman masuk, mis. /motif/usu.svg. */
  readonly VITE_RS_MOTIF?: string
  /** Jalur foto gedung RS di public/ untuk panel merek halaman masuk, mis. /foto/rs-usu.webp. */
  readonly VITE_RS_FOTO?: string
  /** Unit pengelola akun di teks halaman masuk; kosong = "admin SIMRS". */
  readonly VITE_RS_UNIT_ADMIN?: string
  /** Kontak unit pengelola akun di teks bantuan halaman masuk, mis. "ext. 1234". */
  readonly VITE_RS_KONTAK_ADMIN?: string
  /** "true" = instalasi demo/pelatihan (FR-54-10): pita "DEMO — data fiktif" di setiap layar. Sementara sampai GET /profil-rs (1.8a). */
  readonly VITE_INSTALASI_DEMO?: string
  /**
   * "true" = `vite` dev menjawab /saya, /saya/menu, /menu, /peran*, /akun* dari server tiruan kontrak
   * (modules/akses/tiruan) tanpa backend. Diabaikan di build produksi.
   */
  readonly VITE_API_TIRUAN?: string
}
