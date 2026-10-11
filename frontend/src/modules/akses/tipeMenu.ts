/**
 * Bentuk GET /api/v1/saya/menu (docs/api/openapi.yaml → MenuSaya). Tanpa impor agar data tiruan
 * bisa dipakai e2e (tsconfig.node) tanpa menarik klien API.
 */
export type StatusMenu = 'aktif' | 'segera'

export interface SubMenu {
  kode: string
  label: string
  rute: string
  status: StatusMenu
  fase: number
}

export interface Menu extends SubMenu {
  /** Nama ikon design-system (`@/design-system/ikon`), bukan URL. */
  ikon: string
  /** Jumlah tugas terbuka; null/tidak ada = belum tersedia, lencana tidak ditampilkan. */
  lencana?: number | null
  sub_menu: SubMenu[]
}

export interface GrupMenu {
  kode: string
  label: string | null
  menu: Menu[]
}

export interface MenuSaya {
  versi: string
  grup: GrupMenu[]
  izin: string[]
}
