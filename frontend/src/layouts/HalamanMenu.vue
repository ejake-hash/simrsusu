<script setup lang="ts">
import { computed, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { cariHalaman, cariPosisi, useMenuSaya } from '@/app/menu'
import SegeraHadirPage from '@/shared/pages/SegeraHadirPage.vue'
import TidakDitemukanPage from '@/shared/pages/TidakDitemukanPage.vue'

/**
 * Rute dinamis dari menu: jalur yang belum punya halaman sendiri di router. Item menu (berstatus
 * "segera", atau "aktif" yang halamannya belum dibuat di frontend) → SegeraHadirPage; jalur lain
 * → TidakDitemukanPage. Halaman modul yang sudah dibangun didaftarkan sebagai rute biasa di
 * app/router.ts, sehingga menang atas rute ini.
 */
const route = useRoute()
const menu = useMenuSaya()

const item = computed(() => (menu.data.value ? cariHalaman(menu.data.value.grup, route.path) : null))
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
    <SegeraHadirPage v-if="item" :judul="item.label" :induk="induk" :fase="item.fase" />
    <TidakDitemukanPage v-else />
  </template>
</template>
