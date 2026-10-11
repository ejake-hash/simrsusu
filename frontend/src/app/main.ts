import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import '@/design-system/tokens.css'
import '@/design-system/dasar.css'
import { aturPenangan401, ApiError, klien } from '@/shared/api/klien'
import { vCan } from '@/shared/composables/usePermission'
import App from './App.vue'
import { lupakanSesi, sambungIzin } from './menu'
import { router } from './router'
import { useSesiStore } from './sesi'

// Error 4xx (401, 403, 422, 429) tidak membaik dengan diulang; jaringan/5xx diulang sekali.
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (kali, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && kali < 1,
    },
  },
})

const app = createApp(App).use(createPinia()).use(VueQueryPlugin, { queryClient })
app.directive('can', vCan)
sambungIzin(queryClient)

// 401 dari endpoint mana pun = sesi berakhir: kembali ke /masuk, lalu ke rute asal setelah masuk.
aturPenangan401((error) => {
  useSesiStore().akhiri(error)
  const sekarang = router.currentRoute.value
  if (sekarang.name !== 'masuk') {
    void router.replace({ name: 'masuk', query: sekarang.fullPath === '/' ? {} : { kembali: sekarang.fullPath } })
  }
})

// Keluar atau sesi berakhir: buang menu, izin, dan cache data akun ini.
watch(
  () => useSesiStore().pengguna,
  (pengguna) => {
    if (!pengguna) lupakanSesi(queryClient)
  },
)

async function mulai() {
  // Server tiruan kontrak 1.2c untuk `vite` dev tanpa backend; cabang ini dibuang dari build produksi.
  if (import.meta.env.DEV && import.meta.env.VITE_API_TIRUAN === 'true') {
    const { pasangTiruan } = await import('@/modules/akses/tiruan/pasangTiruan')
    pasangTiruan(klien)
  }
  app.use(router).mount('#app')
}
void mulai()
