import { ref } from 'vue'

/** Status dialog pencarian menu (Ctrl/Cmd+K, tombol header, kartu Pintasan di Beranda). */
export const pencarianTerbuka = ref(false)

export function bukaPencarianMenu(): void {
  pencarianTerbuka.value = true
}

/** Label pintasan sesuai sistem: ⌘K di Apple, Ctrl K di lainnya. */
export function labelPintasan(): string {
  return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘K' : 'Ctrl K'
}

export function adalahPintasanCari(e: KeyboardEvent): boolean {
  return (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k'
}
