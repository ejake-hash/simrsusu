import { ref } from 'vue'

/** Status dialog pencarian menu (Ctrl/Cmd+K, tombol header, kartu Pintasan di Beranda). */
export const pencarianTerbuka = ref(false)

export function bukaPencarianMenu(): void {
  pencarianTerbuka.value = true
}

const apple = () => typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent)

/** Label pintasan sesuai sistem: ⌘K di Apple, Ctrl K di lainnya. */
export function labelPintasan(): string {
  return apple() ? '⌘K' : 'Ctrl K'
}

/**
 * Cmd+K di Apple (Ctrl+K di sana menghapus sampai akhir baris di isian teks), Ctrl+K di sistem lain. Diabaikan bila
 * sudah ditangani elemen lain atau sedang menyusun teks dengan IME.
 */
export function adalahPintasanCari(e: KeyboardEvent, padaApple = apple()): boolean {
  if (e.defaultPrevented || e.isComposing || e.altKey || e.shiftKey || e.key.toLowerCase() !== 'k') return false
  return padaApple ? e.metaKey && !e.ctrlKey : e.ctrlKey && !e.metaKey
}
