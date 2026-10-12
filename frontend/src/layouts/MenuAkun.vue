<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { useRoute } from 'vue-router'
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Menu akun di header: ganti sandi (1.8d), tanda tangan elektronik (1.6), dan pengaturan notifikasi (1.7). Tombol
 * pengungkap dengan daftar tautan (bukan role=menu: isinya navigasi biasa); Esc dan klik di luar menutup.
 */
const route = useRoute()
const id = `menu-akun-${useId()}`
const buka = ref(false)
const tombol = ref<HTMLButtonElement | null>(null)
const panel = ref<HTMLElement | null>(null)

async function alihkan() {
  buka.value = !buka.value
  if (buka.value) {
    await nextTick()
    panel.value?.querySelector<HTMLElement>('a')?.focus()
    document.addEventListener('pointerdown', klikLuar)
  } else tutup(false)
}
function tutup(fokuskan = true) {
  buka.value = false
  document.removeEventListener('pointerdown', klikLuar)
  if (fokuskan) tombol.value?.focus()
}
function klikLuar(e: PointerEvent) {
  const t = e.target as Node
  if (!panel.value?.contains(t) && !tombol.value?.contains(t)) tutup(false)
}
onBeforeUnmount(() => document.removeEventListener('pointerdown', klikLuar))
</script>

<template>
  <div class="menu-akun" @keydown.esc="(e: KeyboardEvent) => { if (buka) { e.stopPropagation(); tutup() } }">
    <button ref="tombol" type="button" class="tombol-ikon" aria-label="Menu akun" title="Menu akun" :aria-expanded="buka" :aria-controls="id" @click="alihkan">
      <Ikon nama="pengguna" />
    </button>
    <nav v-show="buka" :id="id" ref="panel" class="panel" aria-label="Menu akun">
      <RouterLink :to="{ path: '/ganti-sandi', query: { kembali: route.fullPath } }" @click="tutup(false)"><Ikon nama="gembok" :ukuran="16" />Ganti sandi</RouterLink>
      <RouterLink to="/tanda-tangan" @click="tutup(false)"><Ikon nama="centang" :ukuran="16" />Tanda tangan elektronik</RouterLink>
      <RouterLink to="/notifikasi?tab=preferensi" @click="tutup(false)"><Ikon nama="lonceng" :ukuran="16" />Pengaturan notifikasi</RouterLink>
    </nav>
  </div>
</template>

<style scoped>
.menu-akun {
  position: relative;
  flex: none;
}
.tombol-ikon {
  box-sizing: border-box;
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border: 1px solid var(--border-input);
  border-radius: 10px;
  background: var(--surface);
  color: var(--muted);
  cursor: pointer;
}
.tombol-ikon:hover {
  background: var(--bg);
  color: var(--text);
}
.tombol-ikon:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.panel {
  position: absolute;
  z-index: 30;
  top: calc(100% + 8px);
  right: 0;
  display: grid;
  min-width: 240px;
  padding: var(--space-1);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  box-shadow: var(--shadow-laci);
}
.panel a {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 40px;
  padding: 0 var(--space-3);
  border-radius: var(--radius-input);
  color: var(--text);
  text-decoration: none;
}
.panel a:hover,
.panel a:focus-visible {
  outline: none;
  background: var(--brand-25);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .panel a {
    min-height: 44px;
  }
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .tombol-ikon {
    width: 44px;
    height: 44px;
  }
}
</style>
