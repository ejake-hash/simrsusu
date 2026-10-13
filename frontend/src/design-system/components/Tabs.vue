<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'

/**
 * Tab di dalam halaman (mis. rincian akun: Penugasan, Per akun, Lihat sebagai, MFA). Keyboard: panah kiri/kanan
 * pindah tab, Home/End ke ujung (pola WAI-ARIA tabs). Isi tiap tab lewat slot bernama sesuai `nilai`.
 * `pertahankanIsi`: isi tab yang tidak aktif tetap terpasang (disembunyikan), mis. formulir yang belum disimpan.
 */
withDefaults(defineProps<{ label: string; daftar: { nilai: string; label: string }[]; pertahankanIsi?: boolean }>(), { pertahankanIsi: false })
const aktif = defineModel<string>({ required: true })
</script>

<template>
  <TabsRoot v-model="aktif" class="tabs" :unmount-on-hide="!pertahankanIsi">
    <TabsList class="tabs-daftar" :aria-label="label">
      <TabsTrigger v-for="t in daftar" :key="t.nilai" :value="t.nilai" class="tabs-pemicu">
        {{ t.label }}
      </TabsTrigger>
    </TabsList>
    <TabsContent v-for="t in daftar" :key="t.nilai" :value="t.nilai" class="tabs-isi">
      <slot :name="t.nilai" />
    </TabsContent>
  </TabsRoot>
</template>

<style scoped>
.tabs-daftar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}
.tabs-pemicu {
  box-sizing: border-box;
  min-height: 34px;
  padding: 6px 11px;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--surface);
  color: var(--muted);
  font: inherit;
  font-size: 12.5px;
  font-weight: var(--font-weight-medium);
  cursor: pointer;
}
.tabs-pemicu:hover {
  color: var(--text);
}
.tabs-pemicu[data-state='active'] {
  border-color: var(--brand-500);
  background: var(--brand-50);
  color: var(--brand-800);
}
.tabs-pemicu:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.tabs-isi {
  padding-top: var(--space-4);
}
.tabs-isi:focus-visible {
  outline: none;
  box-shadow: var(--ring);
  border-radius: var(--radius-input);
}

/* Kelas tablet: target sentuh 44 px (PRD 5.5 aturan 1). */
@media (min-width: 768px) and (max-width: 1279.98px) {
  .tabs-pemicu {
    min-height: 44px;
  }
}
</style>
