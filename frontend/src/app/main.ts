import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@/design-system/tokens.css'
import { aturPenangan401 } from '@/shared/api/klien'
import App from './App.vue'
import { router } from './router'
import { useSesiStore } from './sesi'

const app = createApp(App).use(createPinia())

// 401 dari endpoint mana pun = sesi berakhir: kembali ke /masuk, lalu ke rute asal setelah masuk.
aturPenangan401((galat) => {
  useSesiStore().akhiri(galat)
  const sekarang = router.currentRoute.value
  if (sekarang.name !== 'masuk') {
    void router.replace({ name: 'masuk', query: sekarang.fullPath === '/' ? {} : { kembali: sekarang.fullPath } })
  }
})

app.use(router).mount('#app')
