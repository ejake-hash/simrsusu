import { onScopeDispose, ref } from 'vue'

/** Kelas layar PRD 5.5. Batas yang sama dipakai media query CSS di AppShell/SidebarMenu. */
export type KelasLayar = 'desktop' | 'tablet-mendatar' | 'tablet-tegak' | 'ponsel'

const KUERI: [KelasLayar, string][] = [
  ['desktop', '(min-width: 1280px)'],
  ['tablet-mendatar', '(min-width: 1024px)'],
  ['tablet-tegak', '(min-width: 768px)'],
]

function hitung(): KelasLayar {
  if (typeof window.matchMedia !== 'function') return 'desktop'
  return KUERI.find(([, q]) => window.matchMedia(q).matches)?.[0] ?? 'ponsel'
}

export function useKelasLayar() {
  const kelas = ref<KelasLayar>(hitung())
  const perbarui = () => {
    kelas.value = hitung()
  }
  if (typeof window.matchMedia === 'function') {
    const daftar = KUERI.map(([, q]) => window.matchMedia(q))
    daftar.forEach((m) => m.addEventListener('change', perbarui))
    onScopeDispose(() => daftar.forEach((m) => m.removeEventListener('change', perbarui)))
  }
  return kelas
}
