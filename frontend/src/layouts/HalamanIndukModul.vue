<script setup lang="ts">
import NavigasiSubMenu from '@/design-system/components/NavigasiSubMenu.vue'
import type { Menu } from '@/modules/akses/api'

/**
 * Halaman induk modul (mis. /pengaturan): sub-menu yang diizinkan sebagai kartu. Wajib ada karena sidebar
 * ringkas 64 px (tablet mendatar) tidak menampilkan sub-menu (PRD 5.5).
 */
defineProps<{ menu: Menu }>()
</script>

<template>
  <div class="kepala-halaman">
    <h1>{{ menu.label }}</h1>
    <p>Pilih halaman di modul ini.</p>
  </div>
  <NavigasiSubMenu :daftar="menu.sub_menu" :label="`Sub-menu ${menu.label}`" bentuk="kartu" />
</template>

<style scoped>
.kepala-halaman {
  margin-bottom: 18px;
}
h1 {
  margin: 0 0 var(--space-1);
  font-size: var(--text-title);
  line-height: 1.2;
  letter-spacing: -0.02em;
}
p {
  margin: 0;
  color: var(--muted);
}
</style>
