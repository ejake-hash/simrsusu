<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { cariPosisi } from '@/app/menu'
import Ikon from '@/design-system/ikon/Ikon.vue'
import type { GrupMenu } from '@/modules/akses/api'
import type { KelasLayar } from '@/shared/composables/useKelasLayar'

/**
 * Menu sidebar dari GET /saya/menu (PRD 5.2, 5.4). Yang tidak diizinkan tidak dikirim server,
 * jadi semua yang diterima dirender. Sub-menu tampil di bawah menu yang sedang aktif (prototipe
 * masterdata.html). Di tablet mendatar sidebar menjadi ikon 64 px + tooltip; di ponsel hanya Beranda.
 */
const props = defineProps<{ grup: GrupMenu[]; kelas: KelasLayar }>()
const emit = defineEmits<{ pilih: [] }>()

const route = useRoute()
const posisi = computed(() => cariPosisi(props.grup, route.path))
const ringkas = computed(() => props.kelas === 'tablet-mendatar')

const grupTampil = computed(() =>
  props.kelas === 'ponsel'
    ? props.grup.map((g) => ({ ...g, menu: g.menu.filter((m) => m.rute === '/') })).filter((g) => g.menu.length > 0)
    : props.grup,
)

// Tooltip satu elemen posisi tetap: nav menggulir sendiri, jadi tooltip di dalamnya akan terpotong.
const tip = ref<{ teks: string; atas: number } | null>(null)

function tampilkanTip(teks: string, e: Event) {
  if (!ringkas.value) return
  const kotak = (e.currentTarget as HTMLElement).getBoundingClientRect()
  tip.value = { teks, atas: kotak.top + kotak.height / 2 }
}

function sembunyikanTip() {
  tip.value = null
}
</script>

<template>
  <nav class="nav" :class="{ ringkas }" aria-label="Menu utama" @keydown.esc="sembunyikanTip" @scroll="sembunyikanTip">
    <template v-for="g in grupTampil" :key="g.kode">
      <p v-if="g.label" :id="`grup-${g.kode}`" class="nav-grup">{{ g.label }}</p>
      <ul class="daftar" :aria-labelledby="g.label ? `grup-${g.kode}` : undefined">
        <li v-for="m in g.menu" :key="m.kode">
          <RouterLink
            :to="m.rute"
            class="nav-item"
            :class="{ aktif: posisi?.menu.kode === m.kode }"
            :aria-current="route.path === m.rute ? 'page' : undefined"
            @click="emit('pilih')"
            @mouseenter="tampilkanTip(m.label, $event)"
            @mouseleave="sembunyikanTip"
            @focus="tampilkanTip(m.label, $event)"
            @blur="sembunyikanTip"
          >
            <Ikon :nama="m.ikon" />
            <span class="label">{{ m.label }}</span>
            <span v-if="m.status === 'segera'" class="segera">Segera<span class="sr-only"> hadir</span></span>
            <span v-else-if="m.lencana != null" class="lencana">
              {{ m.lencana }}<span class="sr-only"> tugas terbuka</span>
            </span>
          </RouterLink>
          <ul v-if="!ringkas && posisi?.menu.kode === m.kode && m.sub_menu.length" class="nav-sub">
            <li v-for="s in m.sub_menu" :key="s.kode">
              <RouterLink
                :to="s.rute"
                class="sub-item"
                :class="{ aktif: posisi?.sub?.kode === s.kode }"
                :aria-current="posisi?.sub?.kode === s.kode ? 'page' : undefined"
                @click="emit('pilih')"
              >
                {{ s.label }}
              </RouterLink>
            </li>
          </ul>
        </li>
      </ul>
    </template>
    <p v-if="kelas === 'ponsel'" class="catatan-ponsel">Modul lain dibuka dari tablet atau komputer.</p>
  </nav>
  <div v-if="tip" class="tip" :style="{ top: `${tip.atas}px` }" aria-hidden="true">{{ tip.teks }}</div>
</template>

<style scoped>
.nav {
  flex: 1;
  overflow-y: auto;
  padding: 6px 10px var(--space-4);
}
.daftar,
.nav-sub {
  list-style: none;
  margin: 0;
  padding: 0;
}
.nav-grup {
  margin: 0;
  padding: var(--space-4) 10px 6px;
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--subtle);
}
.nav-item {
  box-sizing: border-box;
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 37px;
  padding: var(--space-2) 10px;
  border-radius: var(--radius-input);
  color: var(--muted);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
}
.nav-item svg {
  flex: none;
  color: var(--subtle);
}
.nav-item:hover {
  background: var(--bg);
  color: var(--text);
}
.nav-item:focus-visible,
.sub-item:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.nav-item.aktif {
  background: var(--brand-50);
  color: var(--brand-800);
}
.nav-item.aktif svg {
  color: var(--brand-600);
}
.nav-item.aktif::before {
  content: '';
  position: absolute;
  left: -10px;
  top: var(--space-2);
  bottom: var(--space-2);
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: var(--brand-600);
}
.label {
  min-width: 0;
  line-height: 1.3;
}
.segera,
.lencana {
  margin-left: auto;
  flex: none;
  border-radius: 999px;
  padding: 1px 6px;
  font-size: 10.5px;
  font-weight: var(--font-weight-semibold);
  background: var(--bg);
  color: var(--muted);
}
.segera {
  border: 1px solid var(--border);
}
.nav-item.aktif .segera,
.nav-item.aktif .lencana {
  background: var(--surface);
  color: var(--brand-600);
}
.nav-sub {
  margin: 2px 0 6px 28px;
  padding-left: var(--space-2);
  border-left: 1px solid var(--border);
}
.sub-item {
  box-sizing: border-box;
  display: flex;
  align-items: center;
  min-height: 32px;
  padding: 6px 10px;
  border-radius: 7px;
  color: var(--muted);
  font-size: 13px;
  text-decoration: none;
}
.sub-item:hover {
  background: var(--bg);
  color: var(--text);
}
.sub-item.aktif {
  background: var(--brand-25);
  color: var(--brand-800);
  font-weight: var(--font-weight-semibold);
}
.catatan-ponsel {
  margin: var(--space-4) 10px 0;
  font-size: 12px;
  color: var(--subtle);
}
.tip {
  position: fixed;
  left: calc(64px + var(--space-2));
  z-index: 40;
  transform: translateY(-50%);
  padding: 6px 10px;
  border-radius: var(--radius-input);
  background: var(--text);
  color: var(--surface);
  font-size: 12.5px;
  font-weight: var(--font-weight-medium);
  white-space: nowrap;
  pointer-events: none;
}

/* Kelas tablet (768–1279 px): target sentuh minimal 44 px (PRD 5.5 aturan 1). */
@media (min-width: 768px) and (max-width: 1279.98px) {
  .nav-item,
  .sub-item {
    min-height: 44px;
  }
}

/* Tablet mendatar: hanya ikon, label tetap terbaca pembaca layar. */
@media (min-width: 1024px) and (max-width: 1279.98px) {
  .nav {
    padding: 6px 10px var(--space-4);
  }
  .nav-grup {
    height: 1px;
    margin: var(--space-2) 6px;
    padding: 0;
    overflow: hidden;
    color: transparent;
    background: var(--border);
  }
  .nav-item {
    justify-content: center;
    width: 44px;
    padding: 0;
  }
  .nav-item .label,
  .nav-item .lencana {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
  }
  /* Penanda segera menjadi titik di sudut ikon; teksnya tetap terbaca pembaca layar. */
  .nav-item .segera {
    position: absolute;
    top: 7px;
    right: 7px;
    width: 8px;
    height: 8px;
    padding: 0;
    overflow: hidden;
    color: transparent;
    background: var(--border-strong);
    border: 0;
  }
  .nav-item.aktif::before {
    left: -10px;
  }
}
</style>
