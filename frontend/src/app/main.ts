import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import '@/design-system/tokens.css'
import '@/design-system/dasar.css'
import { aturPenangan401, aturPenanganMfaUlang, aturPenanganWajibGantiSandi, ApiError, klien } from '@/shared/api/klien'
import { vCan } from '@/shared/composables/usePermission'
import App from './App.vue'
import { lupakanSesi, sambungIzin } from './menu'
import { jawabMfaUlang, lupakanMasaMfaUlang, mfaUlangTerbuka, mintaMfaUlang } from './mfaUlang'
import { muatProfilRs } from './profilRs'
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

// 403 wajib_ganti_sandi: sandi awal belum diganti (mis. sesi lama sebelum /saya dimuat ulang).
aturPenanganWajibGantiSandi(() => {
  const sesi = useSesiStore()
  if (sesi.pengguna) sesi.pengguna = { ...sesi.pengguna, wajib_ganti_sandi: true }
  const sekarang = router.currentRoute.value
  if (sekarang.name !== 'ganti-sandi') void router.replace({ name: 'ganti-sandi', query: { kembali: sekarang.fullPath } })
})

// 403 mfa_ulang_diperlukan (aksi butuh_mfa, K11): minta kode lewat dialog, lalu klien mengulang permintaan itu.
aturPenanganMfaUlang(mintaMfaUlang)

// Keluar atau sesi berakhir: buang menu, izin, dan cache data akun ini.
watch(
  () => useSesiStore().pengguna,
  (pengguna) => {
    if (!pengguna) {
      lupakanSesi(queryClient)
      if (mfaUlangTerbuka.value) jawabMfaUlang(false)
      lupakanMasaMfaUlang()
    }
  },
)

// Profil dan identitas RS untuk semua layar, termasuk halaman masuk (tidak menahan tampilan pertama).
async function mulai() {
  // Server tiruan kontrak 1.2c untuk `vite` dev tanpa backend; cabang ini dibuang dari build produksi.
  if (import.meta.env.DEV && import.meta.env.VITE_API_TIRUAN === 'true') {
    const { pasangTiruan } = await import('@/modules/akses/tiruan/pasangTiruan')
    pasangTiruan(klien)
  }
  void muatProfilRs()
  app.use(router).mount('#app')
}
void mulai()
