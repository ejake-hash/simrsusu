import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, onMounted, toValue, type MaybeRefOrGetter } from 'vue'
import type { HasilPolling } from './api'

/** Interval polling notifikasi dan kotak kerja (K1: 30 detik, If-None-Match). */
export const INTERVAL_POLLING = 30_000

/** ETag terakhir per kunci kueri (modul, bukan reaktif): dipakai bersama lonceng dan halaman yang memakai kunci sama. */
const etagPerKunci = new Map<string, string | null>()

/**
 * Kueri yang dimuat sekali saat dipasang (permintaan biasa) lalu diperbarui tiap 30 detik sebagai permintaan latar:
 * header X-Permintaan-Latar dan If-None-Match; 304 = data cache tetap. Polling berhenti saat tab tidak terlihat dan
 * menyusul sekali saat terlihat lagi. Galat polling diabaikan (401 ditangani penangan global; jaringan dicoba di detak
 * berikutnya). `segarkan()` = permintaan biasa (mis. sesudah pengguna menandai dibaca).
 */
export function useKueriBerPolling<T>(
  kunci: MaybeRefOrGetter<readonly unknown[]>,
  ambil: (polling: { etag: string | null; latar: boolean }) => Promise<HasilPolling<T>>,
  opsi: { aktif?: MaybeRefOrGetter<boolean>; interval?: number } = {},
) {
  const qc = useQueryClient()
  const kunciKueri = computed(() => toValue(kunci))
  const teks = () => JSON.stringify(kunciKueri.value)
  const aktif = () => toValue(opsi.aktif ?? true)

  async function muat(latar: boolean): Promise<T | undefined> {
    const ada = qc.getQueryData<T>(kunciKueri.value)
    const r = await ambil({ etag: ada !== undefined ? (etagPerKunci.get(teks()) ?? null) : null, latar })
    if (!r.berubah) return ada
    etagPerKunci.set(teks(), r.etag)
    return r.data
  }

  const kueri = useQuery({
    queryKey: kunciKueri,
    queryFn: async () => (await muat(false)) as T,
    enabled: computed(aktif),
    refetchOnWindowFocus: false,
  })

  let pewaktu: ReturnType<typeof setInterval> | undefined
  async function detak() {
    if (!aktif() || document.visibilityState !== 'visible' || kueri.isFetching.value) return
    try {
      const data = await muat(true)
      if (data !== undefined) qc.setQueryData(kunciKueri.value, data)
    } catch {
      // Diabaikan: dicoba lagi di detak berikutnya.
    }
  }
  const saatTerlihat = () => {
    if (document.visibilityState === 'visible') void detak()
  }
  onMounted(() => {
    pewaktu = setInterval(() => void detak(), opsi.interval ?? INTERVAL_POLLING)
    document.addEventListener('visibilitychange', saatTerlihat)
  })
  onBeforeUnmount(() => {
    clearInterval(pewaktu)
    document.removeEventListener('visibilitychange', saatTerlihat)
  })

  return { kueri, segarkan: () => kueri.refetch(), detak }
}
