import { useQuery, type QueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { ambilMenuSaya, lupakanMenuSaya, type GrupMenu, type Menu, type MenuSaya, type SubMenu } from '@/modules/akses/api'
import { aturIzin } from '@/shared/composables/usePermission'
import { useSesiStore } from './sesi'

export const KUNCI_MENU = ['saya', 'menu'] as const

/** Menu efektif dari server; hanya diminta saat ada sesi. Pemuatan ulang murah berkat ETag. */
export function useMenuSaya() {
  const sesi = useSesiStore()
  return useQuery({
    queryKey: KUNCI_MENU,
    queryFn: ambilMenuSaya,
    enabled: computed(() => sesi.pengguna !== null),
    staleTime: 60_000,
  })
}

/** Menyalin `izin` dari cache menu ke v-can/PermissionGate setiap kali cache menu berubah. */
export function sambungIzin(client: QueryClient): () => void {
  let terakhir: MenuSaya | undefined
  return client.getQueryCache().subscribe(() => {
    const data = client.getQueryData<MenuSaya>(KUNCI_MENU)
    if (data === terakhir) return
    terakhir = data
    aturIzin(data?.izin ?? [])
  })
}

/** Sesi berakhir: menu, izin, dan ETag akun sebelumnya tidak boleh terbawa ke akun berikutnya. */
export function lupakanSesi(client: QueryClient): void {
  client.clear()
  lupakanMenuSaya()
  aturIzin([])
}

export interface PosisiMenu {
  grup: GrupMenu
  menu: Menu
  sub: SubMenu | null
}

const rapikan = (jalur: string) => (jalur.length > 1 ? jalur.replace(/\/+$/, '') : jalur)

function memuat(rute: string, jalur: string): boolean {
  return rute === '/' ? jalur === '/' : jalur === rute || jalur.startsWith(`${rute}/`)
}

/** Menu (dan sub-menu) yang memuat `jalur`, untuk penanda menu aktif dan judul header. */
export function cariPosisi(grup: GrupMenu[], jalur: string): PosisiMenu | null {
  const j = rapikan(jalur)
  for (const g of grup) {
    for (const menu of g.menu) {
      if (memuat(menu.rute, j)) {
        return { grup: g, menu, sub: menu.sub_menu.find((s) => memuat(s.rute, j)) ?? null }
      }
    }
  }
  return null
}

/** Item menu yang rutenya persis `jalur`; null = bukan halaman menu (atau tidak diizinkan). */
export function cariHalaman(grup: GrupMenu[], jalur: string): Menu | SubMenu | null {
  const j = rapikan(jalur)
  const posisi = cariPosisi(grup, j)
  if (!posisi) return null
  if (posisi.sub?.rute === j) return posisi.sub
  return posisi.menu.rute === j ? posisi.menu : null
}
