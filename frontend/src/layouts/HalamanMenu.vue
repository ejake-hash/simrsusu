<script setup lang="ts">
import { computed, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { cariHalaman, cariPosisi, useMenuSaya } from '@/app/menu'
import type { Menu, SubMenu } from '@/modules/akses/api'
import SegeraHadirPage from '@/shared/pages/SegeraHadirPage.vue'
import TidakDitemukanPage from '@/shared/pages/TidakDitemukanPage.vue'
import HalamanIndukModul from './HalamanIndukModul.vue'

/**
 * Rute dinamis dari menu: jalur yang belum punya halaman sendiri di router. Menu aktif yang punya
 * sub-menu → HalamanIndukModul (kartu sub-menu); item menu lain (berstatus "segera", atau "aktif" yang
 * halamannya belum dibuat di frontend) → SegeraHadirPage; jalur lain → TidakDitemukanPage. Halaman
 * modul yang sudah dibangun didaftarkan sebagai rute biasa di app/router.ts, sehingga menang atas rute ini.
 */
const route = useRoute()
const menu = useMenuSaya()

const item = computed(() => (menu.data.value ? cariHalaman(menu.data.value.grup, route.path) : null))
const adalahMenu = (i: Menu | SubMenu): i is Menu => 'sub_menu' in i
const menuInduk = computed(() => {
  const i = item.value
  return i && adalahMenu(i) && i.status === 'aktif' && i.sub_menu.length > 0 ? i : null
})
const induk = computed(() => {
  if (!menu.data.value || !item.value) return null
  const posisi = cariPosisi(menu.data.value.grup, route.path)
  return posisi?.sub ? posisi.menu.label : null
})

watchEffect(() => {
  if (!menu.data.value) return
  document.title = `${item.value?.label ?? 'Halaman tidak ditemukan'} · Karsitara`
})
</script>

<template>
  <template v-if="menu.data.value">
    <HalamanIndukModul v-if="menuInduk" :menu="menuInduk" />
    <SegeraHadirPage v-else-if="item" :judul="item.label" :induk="induk" :fase="item.fase" />
    <TidakDitemukanPage v-else />
  </template>
</template>
