<script setup lang="ts">
import { useRoute } from 'vue-router'

/**
 * Navigasi sub-menu di dalam halaman modul, dari sub-menu yang dikirim /saya/menu (hanya yang diizinkan).
 * Wajib dipakai halaman induk dan halaman sub-menu setiap modul: sidebar ringkas 64 px (tablet mendatar)
 * tidak menampilkan sub-menu (PRD 5.5).
 * - `tab`: deret tab di kepala halaman sub-menu (pola vtabs masterdata.html).
 * - `kartu`: kisi kartu di halaman induk modul (mis. /pengaturan).
 */
interface ItemSubMenu {
  kode: string
  label: string
  rute: string
  status?: 'aktif' | 'segera'
}

withDefaults(defineProps<{ daftar: ItemSubMenu[]; label: string; bentuk?: 'tab' | 'kartu' }>(), { bentuk: 'tab' })

const route = useRoute()
const aktif = (rute: string) => route.path === rute || route.path.startsWith(`${rute}/`)
</script>

<template>
  <nav :aria-label="label" :class="bentuk === 'tab' ? 'navsub-tab' : 'navsub-kartu'">
    <ul>
      <li v-for="s in daftar" :key="s.kode">
        <RouterLink :to="s.rute" class="navsub-item" :class="{ aktif: aktif(s.rute) }" :aria-current="aktif(s.rute) ? 'page' : undefined">
          <span class="navsub-label">{{ s.label }}</span>
          <span v-if="s.status === 'segera'" class="navsub-segera">Segera<span class="sr-only"> hadir</span></span>
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.navsub-item {
  box-sizing: border-box;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--muted);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
}
.navsub-item:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.navsub-segera {
  flex: none;
  padding: 1px 6px;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--bg);
  color: var(--muted);
  font-size: 10.5px;
  font-weight: var(--font-weight-semibold);
}

/* ── Tab ── */
.navsub-tab ul {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  padding: 3px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
}
.navsub-tab .navsub-item {
  min-height: 34px;
  padding: 7px 12px;
  border-radius: 7px;
  font-size: 13px;
}
.navsub-tab .navsub-item:hover {
  color: var(--text);
}
.navsub-tab .navsub-item.aktif {
  background: var(--brand-600);
  color: var(--surface);
}
.navsub-tab .aktif .navsub-segera {
  background: var(--surface);
  color: var(--brand-800);
}

/* ── Kartu ── */
.navsub-kartu ul {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: var(--space-3);
}
.navsub-kartu .navsub-item {
  min-height: 64px;
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  color: var(--text);
  font-size: 14px;
  font-weight: var(--font-weight-semibold);
}
.navsub-kartu .navsub-item:hover {
  border-color: var(--brand-100);
  background: var(--brand-25);
}
.navsub-kartu .navsub-segera {
  margin-left: auto;
}

/* Kelas tablet: target sentuh 44 px (PRD 5.5 aturan 1). */
@media (min-width: 768px) and (max-width: 1279.98px) {
  .navsub-tab .navsub-item {
    min-height: 44px;
  }
}
</style>
