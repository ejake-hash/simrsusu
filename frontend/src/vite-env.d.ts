/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * "true" = `vite` dev menjawab /saya, /saya/menu, /menu, /peran*, /akun* dari server tiruan kontrak
   * (modules/akses/tiruan) tanpa backend. Diabaikan di build produksi.
   */
  readonly VITE_API_TIRUAN?: string
}
