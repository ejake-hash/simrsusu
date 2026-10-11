<script setup lang="ts">
import { computed, useId } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import type { Menu } from '@/modules/akses/api'
import { ringkasanSubMenu } from '../beranda'

/**
 * Kartu satu modul milik akun: nama (ke halaman modul), tiga sub-menu pertama yang bisa langsung diklik (penting
 * di tablet 64 px yang sidebarnya tanpa sub-menu), dan lencana Segera hadir untuk modul yang belum dibangun.
 */
const props = defineProps<{ menu: Menu }>()
const id = `modul-${useId()}`
const ringkas = computed(() => ringkasanSubMenu(props.menu.sub_menu))
</script>

<template>
  <article class="kartu-modul" :aria-labelledby="id">
    <span class="ikon"><Ikon :nama="menu.ikon" /></span>
    <div class="isi">
      <h3>
        <RouterLink :id="id" :to="menu.rute" class="judul">{{ menu.label }}</RouterLink>
      </h3>
      <p v-if="menu.status === 'segera'" class="pil pil-netral">Segera hadir · fase {{ menu.fase }}</p>
      <ul v-if="ringkas.tampil.length" class="sub">
        <li v-for="s in ringkas.tampil" :key="s.kode">
          <RouterLink :to="s.rute">{{ s.label }}</RouterLink>
        </li>
        <li v-if="ringkas.lainnya">
          <RouterLink :to="menu.rute" class="lainnya">dan {{ ringkas.lainnya }} lainnya<span class="sr-only"> di {{ menu.label }}</span></RouterLink>
        </li>
      </ul>
    </div>
  </article>
</template>

<style scoped>
.kartu-modul {
  display: flex;
  gap: var(--space-3);
  min-width: 0;
  padding: 14px var(--space-4);
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
}
.kartu-modul:hover {
  border-color: var(--brand-100);
  background: var(--brand-25);
}
.ikon {
  flex: none;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 9px;
  background: var(--brand-50);
  color: var(--brand-600);
}
.isi {
  min-width: 0;
}
h3 {
  margin: 0;
  font-size: 14px;
}
.judul {
  display: inline-flex;
  align-items: center;
  min-height: 28px;
  color: var(--text);
  font-weight: var(--font-weight-semibold);
  text-decoration: none;
}
.judul:hover {
  color: var(--brand-600);
  text-decoration: underline;
}
.isi .pil {
  margin: 2px 0 4px;
}
.sub {
  display: flex;
  flex-wrap: wrap;
  gap: 2px var(--space-2);
  margin: 2px 0 0;
  padding: 0;
  list-style: none;
  font-size: 12.5px;
}
.sub a {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  color: var(--muted);
  text-decoration: none;
}
.sub a:hover {
  color: var(--brand-600);
  text-decoration: underline;
}
.lainnya {
  font-style: italic;
}
a:focus-visible {
  outline: none;
  border-radius: 4px;
  box-shadow: var(--ring);
}
/* Kelas tablet: target sentuh 44 px (PRD 5.5 aturan 1). */
@media (min-width: 768px) and (max-width: 1279.98px) {
  .judul,
  .sub a {
    min-height: 44px;
  }
  /* Jarak antar-target sentuh ≥ 8 px. */
  .sub {
    gap: var(--space-2);
  }
}
</style>
